<?php

namespace App\Quality\Dashboard;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

final class QualityDashboardReader
{
    /**
     * @var list<string>
     */
    private const FINDING_TYPES = [
        'route_5xx',
        'unexpected_status',
        'missing_config',
        'missing_parameter_provider',
        'critical_security_finding',
        'secret_detected',
    ];

    /**
     * @var list<string>
     */
    private const QUALITY_STATUSES = ['pass', 'fail', 'warning'];

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return $this->qualityOverview();
    }

    /**
     * @return array<string, mixed>
     */
    public function currentState(): array
    {
        return [
            'deterministic' => true,
            'description' => 'DevBoard quality verification is deterministic and controlled. Tests verify domain truth, not only that routes return 200.',
            'current_state' => 'Route inventory, SAFE_READ smoke, PHPStan, Composer audit, and pull request quality gates are available from DevBoard reports.',
            'desired_state' => 'Full gate coverage with verified truth registry, human-approved destructive scans, and machine-readable evidence produced near the target repository.',
            'transition_notes' => [
                'DevBoard is the control plane; target-code tests and scanners run near the target repository through the local plugin or agent.',
                'The browser UI uses /api/dashboard/* only; /api/plugin/v1 remains reserved for the local Python CLI/MCP plugin.',
                'Mutating and destructive scans stay disabled unless a whitelisted tool and explicit approval path allows them.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function reports(): array
    {
        return $this->qualityReports();
    }

    /**
     * @return array<string, mixed>
     */
    public function routeInventory(): array
    {
        return $this->report('route-inventory') ?? $this->missingReport('route-inventory');
    }

    /**
     * @return array<string, mixed>
     */
    public function routeSmoke(): array
    {
        return $this->report('route-smoke') ?? $this->missingReport('route-smoke');
    }

    /**
     * @return array<string, mixed>
     */
    public function gateReport(string $gate): array
    {
        return $this->report("quality-gate-{$gate}") ?? [
            ...$this->missingReport('quality-gate'),
            'gate' => $gate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function qualityOverview(): array
    {
        $reports = $this->qualityReports();
        $gate = $this->qualityGate('pull_request');
        $routeSmoke = $this->normalizeQualityReport($this->routeSmoke());
        $latestSecurity = $this->latestSecurityStatus($reports);
        $statuses = [
            (string) $gate['status'],
            (string) $routeSmoke['status'],
            (string) $latestSecurity['status'],
        ];

        return [
            'overall_status' => $this->combinedStatus($statuses),
            'latest_gate' => [
                'gate' => 'pull_request',
                'status' => $gate['status'],
                'generated_at' => $gate['generated_at'],
            ],
            'latest_route_smoke' => [
                'status' => $routeSmoke['status'],
                'generated_at' => $routeSmoke['generated_at'],
            ],
            'latest_security' => $latestSecurity,
            'stale_or_missing' => $this->staleOrMissing($reports),
            'counters' => $this->aggregateCounters($reports),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function qualityReports(): array
    {
        $reports = [];

        foreach (File::glob($this->reportsDirectory().'/*.json') ?: [] as $path) {
            $data = json_decode((string) File::get($path), true);

            if (! is_array($data) || ! isset($data['tool'])) {
                continue;
            }

            $reports[] = $this->normalizeQualityReport($data);
        }

        usort($reports, static function (array $left, array $right): int {
            return [
                (string) ($right['generated_at'] ?? ''),
                (string) ($left['tool'] ?? ''),
            ] <=> [
                (string) ($left['generated_at'] ?? ''),
                (string) ($right['tool'] ?? ''),
            ];
        });

        return $reports;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function routeInventoryEntries(): array
    {
        $report = $this->routeInventory();
        $routes = is_array($report['routes'] ?? null) ? $report['routes'] : [];
        $entries = [];

        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }

            $methods = is_array($route['methods'] ?? null) ? $route['methods'] : [];
            $method = $this->primaryHttpMethod($methods);
            $uri = (string) ($route['uri'] ?? '');
            $warnings = array_values(array_map('strval', is_array($route['warnings'] ?? null) ? $route['warnings'] : []));
            $hasParameters = is_array($route['parameters'] ?? null) && $route['parameters'] !== [];

            $entry = [
                'id' => $this->routeId($route, $method, $uri),
                'name' => (string) ($route['name'] ?: $uri ?: 'unnamed'),
                'method' => $method,
                'path' => $this->displayPath($uri),
                'controller_action' => (string) ($route['action'] ?? $route['controller'] ?? ''),
                'classification' => $this->frontendClassification((string) ($route['classification'] ?? 'UNKNOWN')),
                'configured' => (bool) ($route['configured'] ?? false),
                'parameter_provider' => $hasParameters
                    ? (in_array('missing_parameter_provider', $warnings, true) ? 'missing' : 'configured')
                    : 'not_required',
            ];

            if ($warnings !== []) {
                $entry['warning'] = implode(', ', $warnings);
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, matrix: list<array<string, mixed>>}
     */
    public function routeSmokeView(): array
    {
        $report = $this->routeSmoke();
        $results = is_array($report['results'] ?? null) ? $report['results'] : [];
        $actor = $this->frontendActor((string) ($report['actor'] ?? 'guest'));
        $rows = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $route = (string) ($result['name'] ?: $result['uri'] ?? 'unknown-route');
            $executed = (bool) ($result['executed'] ?? false);
            $passed = (bool) ($result['passed'] ?? false);
            $actualStatus = $result['actual_status'] ?? null;

            $rows[] = [
                'id' => $this->smokeRowId($result),
                'route' => $route,
                'actor' => $actor,
                'expected_status' => (int) ($result['expected_status'] ?? 200),
                'actual_status' => is_numeric($actualStatus) ? (int) $actualStatus : null,
                'result' => $executed ? ($passed ? 'pass' : 'fail') : 'skipped',
                'skipped_reason' => $executed ? null : (string) ($result['skip_reason'] ?? 'skipped'),
                'blocking' => $executed && ! $passed,
            ];
        }

        return [
            'rows' => $rows,
            'matrix' => $this->authorizationMatrix(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function qualityGate(string $gate): array
    {
        $report = $this->gateReport($gate);
        $findings = array_values(array_filter(
            array_map(
                fn (mixed $finding): ?array => is_array($finding) ? $this->normalizeFinding($finding) : null,
                is_array($report['findings'] ?? null) ? $report['findings'] : [],
            ),
        ));

        $blocking = [];
        $warnings = [];

        foreach ($findings as $finding) {
            if (($finding['gate_decision'] ?? '') === 'blocking') {
                $blocking[] = $this->frontendFinding($finding);
            } elseif (($finding['gate_decision'] ?? '') !== 'ignored') {
                $warnings[] = $this->frontendFinding($finding);
            }
        }

        return [
            'gate' => $this->frontendGate($gate),
            'status' => $this->normalizeStatus((string) ($report['status'] ?? 'warning')),
            'generated_at' => (string) ($report['generated_at'] ?? now()->toIso8601String()),
            'blocking_findings' => $blocking,
            'warnings' => $warnings,
            'human_approvals_required' => $this->humanApprovalsRequired($gate),
        ];
    }

    /**
     * @return array{phases: list<array<string, mixed>>, checks: list<array<string, mixed>>, truth: list<array<string, mixed>>}
     */
    public function roadmap(): array
    {
        return [
            'phases' => $this->roadmapPhases(),
            'checks' => $this->securityChecks(),
            'truth' => $this->truthRegistryEntries(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function report(string $fileName): ?array
    {
        $path = $this->reportsDirectory()."/{$fileName}.json";

        if (! File::exists($path)) {
            return null;
        }

        $data = json_decode((string) File::get($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function missingReport(string $tool): array
    {
        return [
            'tool' => $tool,
            'status' => 'warning',
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'total' => 1,
                'passed' => 0,
                'failed' => 0,
                'warnings' => 1,
                'skipped' => 0,
            ],
            'findings' => [
                [
                    'id' => "{$tool}.missing-report",
                    'severity' => 'medium',
                    'type' => 'missing_config',
                    'message' => "Report [{$tool}] has not been generated yet.",
                    'route' => '',
                    'expected' => 'generated report',
                    'actual' => 'missing report',
                    'evidence' => [],
                    'gate_decision' => 'warning',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeQualityReport(array $data): array
    {
        $normalized = [
            'tool' => (string) ($data['tool'] ?? 'unknown'),
            'status' => $this->normalizeStatus((string) ($data['status'] ?? 'warning')),
            'generated_at' => (string) ($data['generated_at'] ?? now()->toIso8601String()),
            'summary' => $this->normalizeSummary(is_array($data['summary'] ?? null) ? $data['summary'] : []),
            'findings' => array_values(array_filter(array_map(
                fn (mixed $finding): ?array => is_array($finding) ? $this->frontendFinding($this->normalizeFinding($finding)) : null,
                is_array($data['findings'] ?? null) ? $data['findings'] : [],
            ))),
        ];

        foreach ($data as $key => $value) {
            if (! array_key_exists($key, $normalized)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array{total: int, passed: int, failed: int, warnings: int, skipped: int}
     */
    private function normalizeSummary(array $summary): array
    {
        return [
            'total' => (int) ($summary['total'] ?? 0),
            'passed' => (int) ($summary['passed'] ?? 0),
            'failed' => (int) ($summary['failed'] ?? 0),
            'warnings' => (int) ($summary['warnings'] ?? 0),
            'skipped' => (int) ($summary['skipped'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $finding
     * @return array<string, mixed>
     */
    private function normalizeFinding(array $finding): array
    {
        return [
            ...$finding,
            'id' => (string) ($finding['id'] ?? 'quality.finding'),
            'severity' => $this->normalizeSeverity((string) ($finding['severity'] ?? 'info')),
            'type' => $this->normalizeFindingType((string) ($finding['type'] ?? 'missing_config')),
            'message' => (string) ($finding['message'] ?? ''),
            'route' => (string) ($finding['route'] ?? ''),
            'expected' => (string) ($finding['expected'] ?? ''),
            'actual' => (string) ($finding['actual'] ?? ''),
            'evidence' => is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $finding
     * @return array{id: string, severity: string, type: string, message: string, route: string, expected: string, actual: string, evidence: array<string, mixed>}
     */
    private function frontendFinding(array $finding): array
    {
        return [
            'id' => (string) $finding['id'],
            'severity' => (string) $finding['severity'],
            'type' => (string) $finding['type'],
            'message' => (string) $finding['message'],
            'route' => (string) $finding['route'],
            'expected' => (string) $finding['expected'],
            'actual' => (string) $finding['actual'],
            'evidence' => is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [],
        ];
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower($status);

        return in_array($status, self::QUALITY_STATUSES, true) ? $status : 'warning';
    }

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower($severity);

        return in_array($severity, ['info', 'low', 'medium', 'high', 'critical'], true) ? $severity : 'info';
    }

    private function normalizeFindingType(string $type): string
    {
        return match ($type) {
            'missing_setup' => 'missing_config',
            'composer_audit_advisory' => 'critical_security_finding',
            default => in_array($type, self::FINDING_TYPES, true) ? $type : 'missing_config',
        };
    }

    /**
     * @param list<string> $statuses
     */
    private function combinedStatus(array $statuses): string
    {
        if (in_array('fail', $statuses, true)) {
            return 'fail';
        }

        if (in_array('warning', $statuses, true)) {
            return 'warning';
        }

        return 'pass';
    }

    /**
     * @param list<array<string, mixed>> $reports
     * @return array{status: string, generated_at: string}
     */
    private function latestSecurityStatus(array $reports): array
    {
        foreach ($reports as $report) {
            $tool = (string) ($report['tool'] ?? '');

            if (str_contains($tool, 'security') || str_contains($tool, 'audit') || str_contains($tool, 'composer')) {
                return [
                    'status' => $this->normalizeStatus((string) ($report['status'] ?? 'warning')),
                    'generated_at' => (string) ($report['generated_at'] ?? now()->toIso8601String()),
                ];
            }
        }

        $summary = $this->scannerSummary();

        return [
            'status' => $summary['disabled_or_missing'] > 0 ? 'warning' : 'pass',
            'generated_at' => $this->latestReportTimestamp($reports),
        ];
    }

    /**
     * @param list<array<string, mixed>> $reports
     * @return list<array{label: string, reason: string}>
     */
    private function staleOrMissing(array $reports): array
    {
        $items = [];
        $present = [];

        foreach ($reports as $report) {
            $present[(string) ($report['tool'] ?? '')] = true;
        }

        foreach (['route-inventory', 'route-smoke'] as $tool) {
            if (! isset($present[$tool])) {
                $items[] = [
                    'label' => $tool,
                    'reason' => 'Required quality report has not been generated yet.',
                ];
            }
        }

        foreach ($this->scanners() as $key => $scanner) {
            if (! is_array($scanner)) {
                continue;
            }

            if (($scanner['state'] ?? '') !== 'configured') {
                $items[] = [
                    'label' => (string) ($scanner['label'] ?? $key),
                    'reason' => (string) ($scanner['notes'] ?? 'Scanner is not currently configured for default execution.'),
                ];
            }
        }

        return array_slice($items, 0, 6);
    }

    /**
     * @param list<array<string, mixed>> $reports
     * @return array{passed: int, failed: int, warnings: int, skipped: int}
     */
    private function aggregateCounters(array $reports): array
    {
        $counters = ['passed' => 0, 'failed' => 0, 'warnings' => 0, 'skipped' => 0];

        foreach ($reports as $report) {
            $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];

            foreach ($counters as $key => $value) {
                $counters[$key] = $value + (int) ($summary[$key] ?? 0);
            }
        }

        return $counters;
    }

    /**
     * @param list<array<string, mixed>> $reports
     */
    private function latestReportTimestamp(array $reports): string
    {
        foreach ($reports as $report) {
            if (($report['generated_at'] ?? '') !== '') {
                return (string) $report['generated_at'];
            }
        }

        return now()->toIso8601String();
    }

    /**
     * @param list<mixed> $methods
     */
    private function primaryHttpMethod(array $methods): string
    {
        foreach ($methods as $method) {
            $method = strtoupper((string) $method);

            if (in_array($method, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
                return $method;
            }
        }

        return 'GET';
    }

    /**
     * @param array<string, mixed> $route
     */
    private function routeId(array $route, string $method, string $uri): string
    {
        if (($route['name'] ?? null) !== null && $route['name'] !== '') {
            return (string) $route['name'];
        }

        return (string) Str::of("{$method}.{$uri}")
            ->replace(['/', '{', '}'], '.')
            ->replaceMatches('/[^A-Za-z0-9_.-]+/', '-')
            ->trim('.-')
            ->lower();
    }

    /**
     * @param array<string, mixed> $result
     */
    private function smokeRowId(array $result): string
    {
        return (string) Str::of(((string) ($result['method'] ?? 'GET')).'.'.((string) ($result['uri'] ?? 'route')))
            ->replace(['/', '{', '}'], '.')
            ->replaceMatches('/[^A-Za-z0-9_.-]+/', '-')
            ->trim('.-')
            ->lower();
    }

    private function displayPath(string $uri): string
    {
        if ($uri === '' || $uri === '/') {
            return '/';
        }

        return '/'.ltrim($uri, '/');
    }

    private function frontendClassification(string $classification): string
    {
        return match ($classification) {
            'SAFE_READ', 'MUTATING', 'DESTRUCTIVE', 'AUTH' => $classification,
            'EXTERNAL_SIDE_EFFECT' => 'DESTRUCTIVE',
            default => 'UNKNOWN',
        };
    }

    private function frontendActor(string $actor): string
    {
        return match (strtolower($actor)) {
            'admin' => 'admin',
            'developer' => 'developer',
            'sysadmin' => 'sysadmin',
            'pm', 'user' => 'pm',
            default => 'guest',
        };
    }

    private function frontendGate(string $gate): string
    {
        return in_array($gate, ['pull_request', 'nightly', 'release'], true) ? $gate : 'pull_request';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function authorizationMatrix(): array
    {
        $path = base_path('config/quality/authorization_matrix.yaml');
        $data = File::exists($path) ? Yaml::parseFile($path) : [];
        $rows = is_array($data) && is_array($data['decisions'] ?? null) ? $data['decisions'] : [];
        $matrix = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $decisions = [];

            foreach (array_map('strval', is_array($row['allowed'] ?? null) ? $row['allowed'] : []) as $role) {
                $decisions[$this->matrixRoleKey($role)] = 'allowed';
            }

            foreach (array_map('strval', is_array($row['read_only'] ?? null) ? $row['read_only'] : []) as $role) {
                $decisions[$this->matrixRoleKey($role)] = 'allowed';
            }

            foreach (array_map('strval', is_array($row['denied'] ?? null) ? $row['denied'] : []) as $role) {
                $decisions[$this->matrixRoleKey($role)] = 'denied';
            }

            $matrix[] = [
                'resource' => (string) ($row['capability'] ?? 'unknown'),
                'decisions' => $decisions,
            ];
        }

        return $matrix;
    }

    private function matrixRoleKey(string $role): string
    {
        return match ($role) {
            'Admin' => 'admin',
            'Developer' => 'developer',
            'Sysadmin' => 'sysadmin',
            'PM' => 'user',
            'Guest' => 'guest',
            default => strtolower($role),
        };
    }

    /**
     * @return list<array{id: string, label: string, approved: bool}>
     */
    private function humanApprovalsRequired(string $gate): array
    {
        if ($gate === 'pull_request') {
            return [];
        }

        $approvals = [];

        foreach ($this->scanners() as $key => $scanner) {
            if (! is_array($scanner)) {
                continue;
            }

            if (($scanner['approval_required'] ?? false) !== true) {
                continue;
            }

            $approvals[] = [
                'id' => (string) $key,
                'label' => (string) ($scanner['label'] ?? $key),
                'approved' => false,
            ];
        }

        return $approvals;
    }

    /**
     * @return list<array{id: string, phase: string, title: string, status: string, items: list<string>}>
     */
    private function roadmapPhases(): array
    {
        return [
            [
                'id' => 'task-0',
                'phase' => 'Task 0',
                'title' => 'Generated frontend verified separately',
                'status' => 'done',
                'items' => ['Clone/pull external frontend', 'Read README_LLM.md', 'Install dependencies and build outside DevBoard'],
            ],
            [
                'id' => 'tasks-1-7',
                'phase' => 'Tasks 1-7',
                'title' => 'Backend quality primitives and gates',
                'status' => 'done',
                'items' => ['Quality documents and registries', 'Route inventory and SAFE_READ smoke', 'PHPStan, tests, Composer audit, quality gates'],
            ],
            [
                'id' => 'tasks-8-9',
                'phase' => 'Tasks 8-9',
                'title' => 'Quality Center dashboard API',
                'status' => 'in_progress',
                'items' => ['Scanner readiness registry', 'Read-only dashboard quality endpoints', 'Approval-gated quality run endpoint'],
            ],
            [
                'id' => 'frontend-integration',
                'phase' => 'Next',
                'title' => 'Full frontend HTTP integration',
                'status' => 'planned',
                'items' => ['Map remaining /api/dashboard resources', 'Run browser smoke', 'Keep plugin API reserved for local CLI/MCP'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function securityChecks(): array
    {
        $checks = [];

        foreach ($this->scanners() as $key => $scanner) {
            if (! is_array($scanner)) {
                continue;
            }

            $checks[] = [
                'id' => (string) $key,
                'tool' => (string) ($scanner['label'] ?? $key),
                'category' => $this->scannerCategory((string) $key),
                'state' => $this->scannerState((string) ($scanner['state'] ?? 'missing_setup')),
                'requires_human_approval' => (bool) ($scanner['approval_required'] ?? false),
                'destructive' => (bool) ($scanner['destructive'] ?? false),
                'description' => (string) ($scanner['notes'] ?? ''),
                'last_run_at' => null,
            ];
        }

        return $checks;
    }

    private function scannerCategory(string $key): string
    {
        return match ($key) {
            'composer_audit', 'trivy' => 'dependency',
            'zap_baseline', 'zap_active', 'nuclei', 'wapiti', 'greenbone_openvas' => 'dast',
            'k6' => 'load',
            'playwright', 'schemathesis' => 'e2e',
            'infection' => 'mutation',
            default => 'static',
        };
    }

    private function scannerState(string $state): string
    {
        return match ($state) {
            'configured' => 'implemented',
            'configured_disabled' => 'configured_disabled',
            'warning' => 'warning',
            'blocking' => 'blocking',
            default => 'missing_setup',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function truthRegistryEntries(): array
    {
        $path = base_path('config/quality/truth_registry.yaml');
        $data = File::exists($path) ? Yaml::parseFile($path) : [];
        $entries = is_array($data) && is_array($data['entries'] ?? null) ? $data['entries'] : [];
        $truth = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $sourceStatus = (string) ($entry['source_status'] ?? 'needs_verification');

            $truth[] = [
                'id' => (string) ($entry['id'] ?? Str::slug((string) ($entry['title'] ?? 'truth-entry'))),
                'feature' => (string) ($entry['title'] ?? $entry['id'] ?? 'Truth entry'),
                'domain_rules' => array_values(array_map('strval', is_array($entry['expectations'] ?? null) ? $entry['expectations'] : [])),
                'required_tests' => $this->requiredTestsForTruthEntry((string) ($entry['id'] ?? 'truth')),
                'risk' => $this->riskForTruthEntry((string) ($entry['domain'] ?? ''), (string) ($entry['id'] ?? '')),
                'evidence' => implode(', ', array_map('strval', is_array($entry['evidence'] ?? null) ? $entry['evidence'] : [])),
                'marking' => $this->truthMarking($sourceStatus),
                'source' => $this->sourceMeta($sourceStatus, 'config/quality/truth_registry.yaml'),
            ];
        }

        return $truth;
    }

    /**
     * @return list<string>
     */
    private function requiredTestsForTruthEntry(string $id): array
    {
        return match ($id) {
            'admin.plugin_tokens' => ['Feature/PluginTokenControllerTest', 'Feature/QualityDashboardApiTest'],
            'admin.plugin_devices' => ['Feature/PluginDeviceRevocationTest'],
            'runs.review' => ['Feature/RunReviewControllerTest'],
            'system.artifact_retention' => ['Feature/ArtifactRetentionRunControllerTest'],
            'quality.control_plane_boundary' => ['Feature/QualityDashboardApiTest', 'Feature/RouteSmokeCommandTest'],
            default => ['Feature/QualityGateTest'],
        };
    }

    private function riskForTruthEntry(string $domain, string $id): string
    {
        if ($id === 'quality.control_plane_boundary') {
            return 'critical';
        }

        return match ($domain) {
            'admin', 'system' => 'high',
            'runs' => 'medium',
            default => 'medium',
        };
    }

    private function truthMarking(string $sourceStatus): string
    {
        return match ($sourceStatus) {
            'verified_from_code' => 'verified',
            'inferred' => 'inferred',
            default => 'example',
        };
    }

    /**
     * @return array{type: string, status: string, origin: string, generated_at: string}
     */
    private function sourceMeta(string $sourceStatus, string $origin): array
    {
        $status = match ($sourceStatus) {
            'verified_from_code', 'developer_provided' => $sourceStatus,
            default => 'needs_verification',
        };

        return [
            'type' => $status === 'verified_from_code' ? 'local_analyzer' : 'user_manual',
            'status' => $status,
            'origin' => $origin,
            'generated_at' => $this->fileTimestamp(base_path($origin)),
        ];
    }

    private function fileTimestamp(string $path): string
    {
        if (! File::exists($path)) {
            return now()->toIso8601String();
        }

        return date(DATE_ATOM, File::lastModified($path));
    }

    /**
     * @return array<string, mixed>
     */
    private function scanners(): array
    {
        $path = base_path('config/quality/scanners.yaml');
        $data = File::exists($path) ? Yaml::parseFile($path) : [];

        return is_array($data) && is_array($data['scanners'] ?? null) ? $data['scanners'] : [];
    }

    /**
     * @return array{configured: int, disabled_or_missing: int, destructive_disabled: int}
     */
    private function scannerSummary(): array
    {
        $scanners = $this->scanners();
        $configured = 0;
        $disabledOrMissing = 0;
        $destructiveDisabled = 0;

        foreach ($scanners as $scanner) {
            if (! is_array($scanner)) {
                continue;
            }

            if (($scanner['state'] ?? '') === 'configured') {
                $configured++;
            } else {
                $disabledOrMissing++;
            }

            if (($scanner['destructive'] ?? false) === true && ($scanner['default_enabled'] ?? false) === false) {
                $destructiveDisabled++;
            }
        }

        return [
            'configured' => $configured,
            'disabled_or_missing' => $disabledOrMissing,
            'destructive_disabled' => $destructiveDisabled,
        ];
    }

    private function reportsDirectory(): string
    {
        return base_path('var/quality/reports');
    }
}
