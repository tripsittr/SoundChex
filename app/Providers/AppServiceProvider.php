<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
        $this->enforceHttpsInProduction();
        $this->configureRateLimiting();
    }

    /**
     * A self-hosted server reached over a tunnel is on the public internet, so
     * generated URLs must not fall back to http:// once it's live. Local
     * development stays on whatever scheme it's already using.
     */
    private function enforceHttpsInProduction(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Throttles the endpoints worth guessing at.
     *
     * Login is the one route where an attacker gets unlimited free attempts,
     * and a home server is reachable from anywhere once tunnelled — so it's
     * limited per IP *and* per account, since a botnet defeats IP limits alone
     * while a single attacker defeats account limits by spraying addresses.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = (string) $request->input('email');

            return [
                Limit::perMinute(5)->by('login-ip:' . $request->ip()),
                Limit::perMinute(5)->by('login-user:' . mb_strtolower($email)),
            ];
        });

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        // Streaming issues many range requests per track, so this is set high
        // enough to never bother a real listener while still capping a scraper
        // trying to pull the whole library.
        RateLimiter::for('stream', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
