<?php

use App\User;

/** @var \Illuminate\Database\Eloquent\Factory $factory */

use App\Model;
use App\SourceProvider;
use Faker\Generator as Faker;

$factory->define(SourceProvider::class, function (Faker $faker) {
    return [
        'user_id' => factory(User::class),
        'name' => 'GitHub ' . $faker->text(10),
        'type' => 'GitHub',
        'installation_id' => $faker->randomNumber(),
        'meta' => ['token' => 'notatoken'],
    ];
});
