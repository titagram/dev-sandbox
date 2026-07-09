<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\PluginTokenPolicy;
use App\Policies\ProjectPolicy;
use App\Services\AuditLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        Gate::define('manage-plugin-tokens', [PluginTokenPolicy::class, 'manage']);
        Gate::define('read-project', [ProjectPolicy::class, 'read']);
        Gate::define('write-project', [ProjectPolicy::class, 'write']);

        Gate::after(function (?User $user, string $ability, bool|null $result) {
            if ($result === false && $user !== null && request() instanceof Request) {
                app(AuditLogger::class)->record(
                    'permission.denied',
                    'authorization',
                    $ability,
                    ['ability' => $ability],
                    [
                        'type' => 'user',
                        'user_id' => $user->id,
                        'ip_address' => request()->ip(),
                        'user_agent' => request()->userAgent(),
                    ],
                );
            }
        });

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
