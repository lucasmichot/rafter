<?php

/** @var \Illuminate\Database\Eloquent\Factory $factory */

use App\Model;
use App\SourceProvider;
use Faker\Generator as Faker;

$factory->define(SourceProvider::class, function (Faker $faker) {
    return [
        'user_id' => factory('App\User'),
        'name' => 'GitHub ' . $faker->text(10),
        'type' => 'github',
        'installation_id' => $faker->randomNumber(),
        'meta' => [
            'token' => 'notatoken',
            'repositories' => [
                'fake/repository',
            ],
        ],
    ];
});
