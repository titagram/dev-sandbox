<?php

namespace App\Quality\Route;

use Illuminate\Routing\Router;
use Illuminate\Support\Str;

final readonly class RouteInventoryBuilder
{
    public function __construct(
        private Router $router,
        private RouteRegistry $registry,
    ) {}

    /**
     * @return array{
     *     tool: string,
     *     status: string,
     *     generated_at: string,
     *     summary: array{total: int, passed: int, failed: int, warnings: int, skipped: int},
     *     routes: list<array<string, mixed>>,
     *     findings: list<array<string, mixed>>
     * }
     */
    public function build(): array
    {
        $routes = [];
        $findings = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $methods = array_values(array_filter(
                $route->methods(),
                static fn (string $method): bool => $method !== 'HEAD',
            ));
            $primaryMethod = $methods[0] ?? 'GET';
            $uri = $route->uri();
            $registryEntry = $this->registry->find($primaryMethod, $uri);
            $warnings = [];

            if ($registryEntry === null) {
                $warnings[] = 'missing_config';
            }

            if ($route->parameterNames() !== [] && ! $this->hasParameterProvider($registryEntry)) {
                $warnings[] = 'missing_parameter_provider';
            }

            $inventoryRoute = [
                'name' => $route->getName(),
                'uri' => $uri,
                'methods' => $methods,
                'action' => $route->getActionName(),
                'controller' => $this->controllerName($route->getActionName()),
                'parameters' => $route->parameterNames(),
                'classification' => (string) ($registryEntry['classification'] ?? RouteClassification::UNKNOWN),
                'configured' => $registryEntry !== null,
                'warnings' => $warnings,
            ];
            $routes[] = $inventoryRoute;

            foreach ($warnings as $warning) {
                $findings[] = [
                    'id' => $this->findingId($warning, $primaryMethod, $uri),
                    'severity' => 'medium',
                    'type' => $warning,
                    'message' => $this->message($warning),
                    'route' => $route->getName() ?: $uri,
                    'expected' => $warning === 'missing_config' ? 'route_registry entry' : 'parameter provider',
                    'actual' => 'missing',
                    'evidence' => [
                        'method' => $primaryMethod,
                        'uri' => $uri,
                        'action' => $route->getActionName(),
                    ],
                ];
            }
        }

        usort($routes, static function (array $left, array $right): int {
            return [$left['uri'], implode(',', $left['methods'])] <=> [$right['uri'], implode(',', $right['methods'])];
        });

        return [
            'tool' => 'route-inventory',
            'status' => $findings === [] ? 'pass' : 'warning',
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'total' => count($routes),
                'passed' => count(array_filter($routes, static fn (array $route): bool => $route['configured'] === true)),
                'failed' => 0,
                'warnings' => count($findings),
                'skipped' => 0,
            ],
            'routes' => $routes,
            'findings' => $findings,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $registryEntry
     */
    private function hasParameterProvider(?array $registryEntry): bool
    {
        if ($registryEntry === null) {
            return false;
        }

        return isset($registryEntry['parameter_provider']) || isset($registryEntry['parameter_providers']);
    }

    private function controllerName(string $action): string
    {
        if ($action === 'Closure') {
            return 'Closure';
        }

        return Str::before($action, '@');
    }

    private function findingId(string $type, string $method, string $uri): string
    {
        $slug = Str::of("{$method}.{$uri}")
            ->replace(['/', '{', '}'], '.')
            ->replaceMatches('/[^A-Za-z0-9_.-]+/', '-')
            ->trim('.-')
            ->lower();

        return "route-inventory.{$type}.{$slug}";
    }

    private function message(string $type): string
    {
        return match ($type) {
            'missing_parameter_provider' => 'Parameterized route has no configured parameter provider.',
            default => 'Route is not configured for quality inventory.',
        };
    }
}
