<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        // The breached-password check calls an external API, so it only runs
        // in production where the network is expected.
        Password::defaults(fn () => app()->isProduction()
            ? Password::min(8)->letters()->numbers()->uncompromised()
            : Password::min(8)->letters()->numbers());

        // Anything that sends an email: keyed by IP so one visitor cannot
        // flood an inbox, with a per-address cap so many IPs cannot either.
        RateLimiter::for('auth-email', function (Request $request) {
            return [
                Limit::perMinute(6)->by('ip:'.$request->ip()),
                Limit::perHour(10)->by('email:'.strtolower((string) ($request->input('email') ?? $request->user()?->email))),
            ];
        });
    }
}
