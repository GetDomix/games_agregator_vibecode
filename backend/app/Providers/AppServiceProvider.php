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
    }
}
