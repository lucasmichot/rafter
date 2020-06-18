<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizeDeployment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Trackable;

    public function handle()
    {
        $this->model->markAsSuccessful();
        $this->model->updateSourceProviderDeploymentStatus('success');

        $environment = $this->model->environment;

        $environment->activeDeployment()->associate($this->model);
        $environment->save();

        return true;
    }
}
