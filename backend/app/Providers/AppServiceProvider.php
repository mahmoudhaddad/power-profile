<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (app()->environment('local')) {
            Socialite::extend('google', function ($app) {
                $config = $app['config']['services.google'];
                return Socialite::buildProvider(
                    \Laravel\Socialite\Two\GoogleProvider::class,
                    $config
                )->setHttpClient(new \GuzzleHttp\Client(['verify' => false]));
            });
        }
    }
}
