<?php

namespace App\Quality\Route;

use App\Quality\Actor\ActorRegistry;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

final readonly class RouteSmokeRunner
{
    public function __construct(
        private Router $router,
        private RouteRegistry $registry,
        private ActorRegistry $actors,
        private Kernel $kernel,
    ) {}

    /**
     * @return array{
     *     tool: string,
     *     status: string,
     *     generated_at: string,
     *     actor: string,
     *     summary: array{total: int, passed: int, failed: int, warnings: int, skipped: int},
     *     results: list<array<string, mixed>>,
     *     findings: list<array<string, mixed>>
     * }
     */
    public function run(string $actor = 'guest', bool $allowMutating = false, bool $allowDestructive = false): array
    {
        if (! $this->actors->supports($actor)) {
            return $this->unsupportedActorReport($actor);
        }

        $results = [];
        $findings = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $methods = array_values(array_filter(
                $route->methods(),
                static fn (string $method): bool => $method !== 'HEAD',
            ));
            $method = $methods[0] ?? 'GET';
            $uri = $route->uri();
            $registryEntry = $this->registry->find($method, $uri);
            $classification = (string) ($registryEntry['classification'] ?? RouteClassification::UNKNOWN);
            $skipReason = $this->skipReason($classification, $route->parameterNames(), $registryEntry, $allowMutating, $allowDestructive);
            $expectedStatus = $this->expectedStatus($registryEntry, $actor);

            $result = [
                'name' => $route->getName(),
                'uri' => $uri,
                'method' => $method,
                'action' => $route->getActionName(),
                'classification' => $classification,
                'configured' => $registryEntry !== null,
                'executed' => false,
                'expected_status' => $expectedStatus,
                'actual_status' => null,
                'passed' => false,
                'skip_reason' => $skipReason,
            ];

            if ($skipReason === null) {
                $request = Request::create('/'.$uri, $method);
                $response = $this->kernel->handle($request);
                $this->kernel->terminate($request, $response);

                $result['executed'] = true;
                $result['actual_status'] = $response->getStatusCode();
                $result['passed'] = $response->getStatusCode() === $expectedStatus;

                if (! $result['passed']) {
                    $findings[] = $this->finding(
                        type: $response->getStatusCode() >= 500 ? 'route_5xx' : 'unexpected_status',
                        severity: $response->getStatusCode() >= 500 ? 'high' : 'medium',
                        route: $route->getName() ?: $uri,
                        message: 'Route smoke returned an unexpected status.',
                        expected: (string) $expectedStatus,
                        actual: (string) $response->getStatusCode(),
                        evidence: ['method' => $method, 'uri' => $uri],
                    );
                }
            } else {
                $findings[] = $this->finding(
                    type: $skipReason === 'missing_parameter_provider' ? 'missing_parameter_provider' : 'missing_config',
                    severity: 'medium',
                    route: $route->getName() ?: $uri,
                    message: $this->skipMessage($skipReason),
                    expected: 'safe configured route',
                    actual: $skipReason,
                    evidence: [
                        'method' => $method,
                        'uri' => $uri,
                        'classification' => $classification,
                    ],
                );
            }

            $results[] = $result;
        }

        usort($results, static function (array $left, array $right): int {
            return [$left['uri'], $left['method']] <=> [$right['uri'], $right['method']];
        });

        $failed = count(array_filter($results, static fn (array $result): bool => $result['executed'] && ! $result['passed']));
        $passed = count(array_filter($results, static fn (array $result): bool => $result['executed'] && $result['passed']));
        $skipped = count(array_filter($results, static fn (array $result): bool => ! $result['executed']));

        return [
            'tool' => 'route-smoke',
            'status' => $failed > 0 ? 'fail' : ($findings === [] ? 'pass' : 'warning'),
            'generated_at' => now()->toIso8601String(),
            'actor' => $actor,
            'summary' => [
                'total' => count($results),
                'passed' => $passed,
                'failed' => $failed,
                'warnings' => count($findings),
                'skipped' => $skipped,
            ],
            'results' => $results,
            'findings' => $findings,
        ];
    }

    /**
     * @param  list<string>  $parameters
     * @param  array<string, mixed>|null  $registryEntry
     */
    private function skipReason(
        string $classification,
        array $parameters,
        ?array $registryEntry,
        bool $allowMutating,
        bool $allowDestructive,
    ): ?string {
        if ($classification === RouteClassification::MUTATING && ! $allowMutating) {
            return 'unsafe_classification';
        }

        if (in_array($classification, [RouteClassification::DESTRUCTIVE, RouteClassification::EXTERNAL_SIDE_EFFECT], true) && ! $allowDestructive) {
            return 'unsafe_classification';
        }

        if ($parameters !== [] && ! $this->hasParameterProvider($registryEntry)) {
            return 'missing_parameter_provider';
        }

        if ($registryEntry === null) {
            return 'missing_config';
        }

        if ($classification !== RouteClassification::SAFE_READ) {
            return 'unsafe_classification';
        }

        if (($registryEntry['smoke']['enabled'] ?? false) !== true) {
            return 'smoke_disabled';
        }

        return null;
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

    /**
     * @param  array<string, mixed>|null  $registryEntry
     */
    private function expectedStatus(?array $registryEntry, string $actor): int
    {
        $expected = $registryEntry['expected_status'][$actor] ?? $registryEntry['expected_status']['default'] ?? 200;

        return (int) $expected;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function finding(
        string $type,
        string $severity,
        string $route,
        string $message,
        string $expected,
        string $actual,
        array $evidence,
    ): array {
        return [
            'id' => 'route-smoke.'.$type.'.'.$this->slug($route),
            'severity' => $severity,
            'type' => $type,
            'message' => $message,
            'route' => $route,
            'expected' => $expected,
            'actual' => $actual,
            'evidence' => $evidence,
        ];
    }

    private function skipMessage(string $skipReason): string
    {
        return match ($skipReason) {
            'missing_parameter_provider' => 'Parameterized route has no configured parameter provider.',
            'unsafe_classification' => 'Route classification is not allowed for default smoke.',
            'smoke_disabled' => 'Route smoke is configured but disabled.',
            default => 'Route is not configured for smoke testing.',
        };
    }

    private function slug(string $value): string
    {
        return (string) Str::of($value)
            ->replace(['/', '{', '}'], '.')
            ->replaceMatches('/[^A-Za-z0-9_.-]+/', '-')
            ->trim('.-')
            ->lower();
    }

    /**
     * @return array{
     *     tool: string,
     *     status: string,
     *     generated_at: string,
     *     actor: string,
     *     summary: array{total: int, passed: int, failed: int, warnings: int, skipped: int},
     *     results: list<array<string, mixed>>,
     *     findings: list<array<string, mixed>>
     * }
     */
    private function unsupportedActorReport(string $actor): array
    {
        return [
            'tool' => 'route-smoke',
            'status' => 'warning',
            'generated_at' => now()->toIso8601String(),
            'actor' => $actor,
            'summary' => [
                'total' => 0,
                'passed' => 0,
                'failed' => 0,
                'warnings' => 1,
                'skipped' => 0,
            ],
            'results' => [],
            'findings' => [
                $this->finding(
                    type: 'missing_config',
                    severity: 'medium',
                    route: 'actor:'.$actor,
                    message: 'Route smoke actor is not configured for deterministic authentication.',
                    expected: 'supported actor',
                    actual: 'unsupported actor',
                    evidence: ['actor' => $actor],
                ),
            ],
        ];
    }
}
