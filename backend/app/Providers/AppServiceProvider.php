<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Hashing\ScryptHasher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use App\Auth\CustomJwtGuard;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Hash::extend('scrypt', function () {
            return new ScryptHasher();
        });

        Auth::extend('custom_jwt', function ($app, $name, array $config) {
            $guard = new CustomJwtGuard(Auth::createUserProvider($config['provider']), $app['request']);
            $app->refresh('request', $guard, 'setRequest');
            return $guard;
        });
    }
}
