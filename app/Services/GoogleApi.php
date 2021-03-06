<?php

namespace App\Services;

use App\DatabaseInstance;
use App\DomainMapping;
use App\Environment;
use App\GoogleCloud\CloudBuildConfig;
use App\GoogleCloud\CloudBuildOperation;
use App\GoogleCloud\CloudRunConfig;
use App\GoogleCloud\CloudRunIamPolicy;
use App\GoogleCloud\CloudRunService;
use App\GoogleCloud\DatabaseConfig;
use App\GoogleCloud\DatabaseInstanceConfig;
use App\GoogleCloud\DatabaseOperation;
use App\GoogleCloud\DomainMappingConfig;
use App\GoogleCloud\DomainMappingResponse;
use App\GoogleCloud\EnableApisOperation;
use App\GoogleCloud\IamPolicy;
use App\GoogleCloud\QueueConfig;
use App\GoogleCloud\SchedulerJobConfig;
use App\GoogleProject;
use Google\ApiCore\ApiException;
use Google\Cloud\Logging\LoggingClient;
use Google\Cloud\SecretManager\V1\Replication;
use Google\Cloud\SecretManager\V1\Replication\Automatic;
use Google\Cloud\SecretManager\V1\Secret;
use Google\Cloud\SecretManager\V1\SecretManagerServiceClient;
use Google\Cloud\SecretManager\V1\SecretPayload;
use Google_Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleApi
{
    protected $googleProject;
    protected $googleClient;

    public function __construct(GoogleProject $googleProject)
    {
        $this->googleProject = $googleProject;
        $this->googleClient = app(Google_Client::class);
        $this->googleClient->setAuthConfig($googleProject->service_account_json);
        $this->googleClient->addScope('https://www.googleapis.com/auth/cloud-platform');
    }

    /**
     * Get a Google Cloud Project
     *
     * @return array
     */
    public function getProject()
    {
        return $this->request('https://cloudresourcemanager.googleapis.com/v1/projects/' . $this->googleProject->project_id);
    }

    /**
     * Enable a given set of APIs
     *
     * @param array $apis
     * @return array
     */
    public function enableApis($apis = [])
    {
        return $this->request(
            "https://serviceusage.googleapis.com/v1/projects/{$this->googleProject->project_id}/services:batchEnable",
            "POST",
            [
                'serviceIds' => $apis,
            ]
        );
    }

    public function getEnableApisOperation($operationName)
    {
        $response = $this->request("https://serviceusage.googleapis.com/v1/{$operationName}");

        return new EnableApisOperation($response);
    }

    /**
     * Whether this project has an app engine app associated yet.
     *
     * @return boolean
     */
    public function hasAppEngineApp()
    {
        try {
            $res = $this->request(
                "https://appengine.googleapis.com/v1/apps/{$this->googleProject->project_id}"
            );

            return true;
        } catch (ClientException $e) {
            return false;
        }
    }

    /**
     * Create a shell AppEngine app to be able to use Cloud Tasks.
     * Hopefully temporary.
     *
     * @return array
     */
    public function createAppEngineApp()
    {
        return $this->request(
            "https://appengine.googleapis.com/v1/apps",
            "POST",
            [
                'id' => $this->googleProject->project_id,
                'locationId' => 'us-central',
            ]
        );
    }

    /**
     * Takes a CloudBuild configuration and sends it to Cloud Build to create an image.
     */
    public function createImageForBuild(CloudBuildConfig $cloudBuild)
    {
        return $this->request(
            "https://cloudbuild.googleapis.com/v1/projects/{$this->googleProject->project_id}/builds",
            "POST",
            $cloudBuild->instructions()
        );
    }

    /**
     * Get information about a build.
     */
    public function getBuild($buildId)
    {
        return $this->request(
            "https://cloudbuild.googleapis.com/v1/projects/{$this->googleProject->project_id}/builds/{$buildId}"
        );
    }

    /**
     * Get details about a Cloud Build operation.
     */
    public function getCloudBuildOperation($operationName)
    {
        $response = $this->request(
            "https://cloudbuild.googleapis.com/v1/{$operationName}"
        );

        return new CloudBuildOperation($response);
    }

    /**
     * Create a Cloud Run service in a given region.
     */
    public function createCloudRunService(CloudRunConfig $cloudRunConfig)
    {
        return $this->request(
            "https://{$cloudRunConfig->region()}-run.googleapis.com/apis/serving.knative.dev/v1/namespaces/{$cloudRunConfig->projectId()}/services",
            "POST",
            $cloudRunConfig->config()
        );
    }

    /**
     * Replace a revision on a Cloud Run service (aka deploy a new image).
     */
    public function replaceCloudRunService(CloudRunConfig $cloudRunConfig)
    {
        return $this->request(
            "https://{$cloudRunConfig->region()}-run.googleapis.com/apis/serving.knative.dev/v1/namespaces/{$cloudRunConfig->projectId()}/services/{$cloudRunConfig->name()}",
            "PUT",
            $cloudRunConfig->config()
        );
    }

    /**
     * Get information about a Cloud Run service.
     */
    public function getCloudRunService($name, $region)
    {
        $response = $this->request(
            "https://{$region}-run.googleapis.com/apis/serving.knative.dev/v1/namespaces/{$this->googleProject->project_id}/services/{$name}"
        );

        return new CloudRunService($response);
    }

    /**
     * Get the IAM policy for a given Cloud Run web service.
     */
    public function getIamPolicyForCloudRunService(Environment $environment): CloudRunIamPolicy
    {
        $response = $this->request($this->cloudRunIamPolicyUrl($environment) . ':getIamPolicy');

        return new CloudRunIamPolicy($response);
    }

    /**
     * Get the IAM policy for a given Cloud Run web service.
     */
    public function setIamPolicyForCloudRunService(Environment $environment, $policy)
    {
        return $this->request(
            $this->cloudRunIamPolicyUrl($environment) . ':setIamPolicy',
            "POST",
            [
                'policy' => $policy,
            ]
        );
    }

    /**
     * Get the URL to interact with a web environment's Cloud Run IAM policy, which is... really long.
     */
    protected function cloudRunIamPolicyUrl(Environment $environment)
    {
        return sprintf(
            "https://%s-run.googleapis.com/v1/projects/%s/locations/%s/services/%s",
            $environment->project->region,
            $environment->project->googleProject->project_id,
            $environment->project->region,
            $environment->web_service_name
        );
    }

    /**
     * Get a domain mapping
     *
     * @param DomainMapping $mapping
     * @return array
     */
    public function getCloudRunDomainMapping(DomainMapping $mapping): DomainMappingResponse
    {
        $region = $mapping->environment->region();

        $response = $this->request(
            sprintf(
                'https://%s-run.googleapis.com/apis/domains.cloudrun.com/v1/namespaces/%s/domainmappings/%s',
                $region,
                $this->googleProject->project_id,
                $mapping->domain
            )
        );

        return new DomainMappingResponse($response);
    }

    /**
     * Add a domain mapping to the given Cloud Run service.
     *
     * @param DomainMappingConfig $mappingConfig
     * @return array
     */
    public function addCloudRunDomainMapping(DomainMappingConfig $mappingConfig)
    {
        return $this->request(
            "https://{$mappingConfig->region()}-run.googleapis.com/apis/domains.cloudrun.com/v1/namespaces/{$this->googleProject->project_id}/domainmappings",
            "POST",
            $mappingConfig->config()
        );
    }

    /**
     * Delete a Cloud Run domain mapping
     *
     * @param DomainMapping $mapping
     * @return array
     */
    public function deleteCloudRunDomainMapping(DomainMapping $mapping): array
    {
        $region = $mapping->environment->region();

        return $this->request(
            sprintf(
                'https://%s-run.googleapis.com/apis/domains.cloudrun.com/v1/namespaces/%s/domainmappings/%s',
                $region,
                $this->googleProject->project_id,
                $mapping->domain
            ),
            'DELETE'
        );
    }

    /**
     * Create a Database Instance on Google Cloud.
     */
    public function createDatabaseInstance(DatabaseInstanceConfig $databaseInstanceConfig)
    {
        return $this->request(
            "https://www.googleapis.com/sql/v1beta4/projects/{$databaseInstanceConfig->projectId()}/instances",
            "POST",
            $databaseInstanceConfig->config()
        );
    }

    /**
     * Return a list of instances in this Google Project
     *
     * @return array
     */
    public function getDatabaseInstances()
    {
        return $this->request("https://www.googleapis.com/sql/v1beta4/projects/{$this->googleProject->project_id}/instances");
    }

    /**
     * Get a current database operation.
     *
     * @return \App\GoogleCloud\DatabaseOperation
     */
    public function getDatabaseOperation($projectId, $operationName)
    {
        $response = $this->request("https://www.googleapis.com/sql/v1beta4/projects/{$projectId}/operations/{$operationName}");

        return new DatabaseOperation($response);
    }

    /**
     * Create a database.
     *
     * @param DatabaseConfig $databaseConfig
     * @return array
     */
    public function createDatabase(DatabaseConfig $databaseConfig)
    {
        return $this->request(
            "https://www.googleapis.com/sql/v1beta4/projects/{$databaseConfig->projectId()}/instances/{$databaseConfig->instanceName()}/databases",
            "POST",
            $databaseConfig->config()
        );
    }

    /**
     * Get databases in a database instance
     *
     * @param DatabaseInstance $databaseInstance
     * @return array
     */
    public function getDatabases(DatabaseInstance $databaseInstance)
    {
        return $this->request(
            "https://www.googleapis.com/sql/v1beta4/projects/{$this->googleProject->project_id}/instances/{$databaseInstance->name}/databases"
        );
    }

    /**
     * Create or update a queue
     *
     * @param QueueConfig $queueConfig
     * @return array
     */
    public function createOrUpdateQueue(QueueConfig $queueConfig)
    {
        $this->request(
            "https://cloudtasks.googleapis.com/v2beta3/{$queueConfig->name()}",
            "PATCH",
            $queueConfig->config()
        );
    }

    /**
     * Create a Google Cloud Scheduler job
     *
     * @param SchedulerJobConfig $schedulerJobConfig
     * @return array
     */
    public function createSchedulerJob(SchedulerJobConfig $schedulerJobConfig)
    {
        $this->request(
            "https://cloudscheduler.googleapis.com/v1/projects/{$schedulerJobConfig->projectId()}/locations/{$schedulerJobConfig->location()}/jobs",
            "POST",
            $schedulerJobConfig->config()
        );
    }

    /**
     * Get Cloud Logs for a given service
     *
     * @param array $config
     * @return array
     */
    public function getLogsForService(array $config): array
    {
        $projectId = $config['projectId'];
        $serviceName = $config['serviceName'];
        $location = $config['location'];
        $logType = $config['logType'];

        $logging = new LoggingClient([
            'keyFile' => $this->googleProject->service_account_json,
            'projectId' => $projectId,
        ]);

        $logName = '';

        if ($logType == 'app') {
            $logName = 'logName = "projects/' . $projectId . '/logs/run.googleapis.com%2F%2Fdev%2Flog" AND ';
        }

        $logs = [];
        $oneDayAgo = Carbon::now()->subDay()->toRfc3339String();
        $filter = sprintf(
            'resource.type = "cloud_run_revision" AND resource.labels.service_name = "%s" AND resource.labels.location = "%s" AND timestamp >= "%s"',
            $serviceName,
            $location,
            $oneDayAgo
        );

        if ($logName) {
            $filter = $logName . $filter;
        }

        $entries = $logging->entries([
            'pageSize' => 30,
            'resultLimit' => 30,
            'filter' => $filter,
            'orderBy' => 'timestamp desc',
        ]);

        foreach ($entries as $entry) {
            $info = $entry->info();

            $logs[] = $info;
        }

        return $logs;
    }

    /**
     * Set or create a secret in Secret Manager API
     *
     * @param string $key
     * @param string $value
     */
    public function setSecret(string $key, string $value)
    {
        /** @var \Google\Cloud\SecretManager\V1\SecretManagerServiceClient */
        $client = app(SecretManagerServiceClient::class, [
            'options' => [
                'credentials' => $this->googleProject->service_account_json,
            ],
        ]);

        // Build the parent name from the project.
        $parent = $client->projectName($this->googleProject->project_id);

        // Try fetching the secret first, and update it.
        try {
            $name = $client->secretName($this->googleProject->project_id, $key);
            $client->getSecret($name);

            return $client->addSecretVersion($name, new SecretPayload([
                'data' => $value,
            ]));
        } catch (ApiException $e) {
            // Otherwise, it needs to be created
            $secret = $client->createSecret(
                $parent,
                $key,
                new Secret([
                    'replication' => new Replication([
                        'automatic' => new Automatic(),
                    ]),
                ])
            );

            return $client->addSecretVersion($secret->getName(), new SecretPayload([
                'data' => $value,
            ]));
        }
    }

    /**
     * Get the IAM Policy for a Google Project.
     *
     * @return IamPolicy
     */
    public function getProjectIamPolicy(): IamPolicy
    {
        return new IamPolicy(
            $this->request(
                "https://cloudresourcemanager.googleapis.com/v1/projects/{$this->googleProject->project_id}:getIamPolicy",
                'POST',
                false
            )
        );
    }

    /**
     * Set the IAM Policy for a Google Project.
     *
     * @return IamPolicy
     */
    public function setProjectIamPolicy(IamPolicy $policy)
    {
        return $this->request(
            "https://cloudresourcemanager.googleapis.com/v1/projects/{$this->googleProject->project_id}:setIamPolicy",
            'POST',
            [
                'policy' => $policy->getPolicy(),
            ]
        );
    }

    /**
     * Request data from the Google Cloud API.
     *
     * @param string $endpoint
     * @param string $method
     * @param array $data
     * @return array
     */
    protected function request($endpoint, $method = 'GET', $data = [])
    {
        try {
            if ($data === false) {
                return Http::withToken($this->token())
                    ->send($method, $endpoint)
                    ->throw()
                    ->json();
            } else {
                return Http::withToken($this->token())
                    ->$method($endpoint, $data)
                    ->throw()
                    ->json();
            }
        } catch (RequestException $exception) {
            Log::error($exception->response->body());

            throw $exception;
        }
    }

    /**
     * Get an access token for the given service account.
     *
     * @return string
     */
    protected function token()
    {
        return $this->googleClient->fetchAccessTokenWithAssertion()['access_token'];
    }
}
