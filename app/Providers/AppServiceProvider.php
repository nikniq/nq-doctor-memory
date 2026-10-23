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

        // Registration and reset emails. The hourly cap is per address AND IP,
        // so someone spamming an address from elsewhere cannot lock its owner
        // out; the password broker separately limits reset mails per address.
        RateLimiter::for('auth-email', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(6)->by('ip:'.$request->ip()),
                Limit::perHour(10)->by('email:'.$request->ip().'|'.$email),
            ];
        });

        // Resending the verification link: only the signed-in account can ask.
        RateLimiter::for('verification-send', function (Request $request) {
            $who = $request->user()?->id ?? $request->ip();

            // Separate keys: limits sharing a key would share one counter.
            return [Limit::perMinute(6)->by('verify-send-minute:'.$who), Limit::perHour(10)->by('verify-send-hour:'.$who)];
        });
    }
}
