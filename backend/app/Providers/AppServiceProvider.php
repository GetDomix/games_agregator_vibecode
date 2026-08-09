<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
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
        foreach ((array) config('gpa.price_source_budgets', []) as $source => $budget) {
            if ($source === 'steam') {
                $budget = intdiv(max(1, (int) $budget), max(1, count((array) config('gpa.steam_price_regions', []))));
            }
            RateLimiter::for("price-source-{$source}", fn () => Limit::perMinute(max(1, (int) $budget)));
        }

        // Named limiters: each endpoint gets its OWN bucket.
        // Laravel's `throttle:N,1` shorthand keys every route by domain|ip only,
        // so heavy public traffic (/api/prices) starved /auth/login -> 429.
        RateLimiter::for('auth-login', fn ($request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('auth-register', fn ($request) => Limit::perMinute(8)->by($request->ip()));
        RateLimiter::for('auth-password', fn ($request) => Limit::perMinute(10)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
        RateLimiter::for('api-search', fn ($request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('api-prices', fn ($request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('api-read', fn ($request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('api-deals', fn ($request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('api-telegram', fn ($request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('api-internal', fn ($request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('admin-read', fn ($request) => Limit::perMinute(120)->by('admin-read:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('admin-action', fn ($request) => Limit::perMinute(20)->by('admin-action:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('admin-role', fn ($request) => Limit::perMinute(5)->by('admin-role:'.($request->user()?->id ?: $request->ip())));
    }
}
