<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

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
        RateLimiter::for('account-password', fn (Request $request) => Limit::perMinute(5)->by((string) $request->user()?->id));
        foreach (['users-create' => 30, 'users-update' => 60, 'users-credentials' => 10, 'credential-inspect' => 20, 'credential-store' => 10] as $name => $attempts) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($attempts)->by($name.':'.($request->user()?->id ?? $request->ip())));
        }
    }
}
