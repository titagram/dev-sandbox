<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        RateLimiter::for('plugin-api-light', fn (Request $request) => $this->pluginRateLimit(
            $request,
            max(1, (int) config('services.devboard.plugin_light_rate_limit_per_minute', 240)),
        ));
        RateLimiter::for('plugin-api-heavy', fn (Request $request) => $this->pluginRateLimit(
            $request,
            max(1, (int) config('services.devboard.plugin_heavy_rate_limit_per_minute', 30)),
        ));
    }

    private function pluginRateLimit(Request $request, int $limit): Limit
    {
        $bearerToken = (string) $request->bearerToken();
        $tokenPrefix = explode('|', $bearerToken, 2)[0] ?: 'anonymous';
        $key = hash('sha256', $tokenPrefix.'|'.($request->ip() ?? 'unknown'));

        return Limit::perMinute($limit)
            ->by($key)
            ->response(function (Request $request, array $headers) {
                return response()->json([
                    'error' => [
                        'code' => 'rate_limited',
                        'message' => 'Plugin API rate limit exceeded.',
                    ],
                ], 429, $headers);
            });
    }
}
