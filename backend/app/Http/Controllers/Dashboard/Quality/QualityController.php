<?php

namespace App\Http\Controllers\Dashboard\Quality;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Quality\Dashboard\QualityDashboardReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

final class QualityController extends Controller
{
    use ChecksDashboardRoles;

    /**
     * @var list<string>
     */
    private const READ_ROLES = ['Admin', 'Developer', 'Sysadmin'];

    /**
     * @var list<string>
     */
    private const OPERATE_ROLES = ['Admin', 'Sysadmin'];

    /**
     * @var list<string>
     */
    private const SUPPORTED_TOOLS = [
        'route-inventory',
        'route-smoke',
        'quality-gate',
        'quality-gate:pull_request',
        'quality-gate:nightly',
        'quality-gate:release',
    ];

    public function overview(Request $request, QualityDashboardReader $reader): JsonResponse
    {
        if ($response = $this->forbiddenUnlessQualityReader($request)) {
            return $response;
        }

        return response()->json($reader->qualityOverview());
    }

    public function currentState(Request $request, QualityDashboardReader $reader): JsonResponse
    {
        if ($response = $this->forbiddenUnlessQualityReader($request)) {
            return $response;
        }

        return response()->json($reader->currentState());
    }

    public function reports(Request $request, QualityDashboardReader $reader): JsonResponse
    {
        if ($response = $this->forbiddenUnlessQualityReader($request)) {
            return $response;
        }

        return response()->json($reader->qualityReports());
    }

    public function routeInventory(Request $request, QualityDashboardReader $reader): JsonResponse
    {
        if ($response = $this->forbiddenUnlessQualityReader($request)) {
            return $response;
        }

        return response()->json($reader->routeInventoryEntries());
    }

    public function routeSmoke(Request $request, QualityDashboardReader $reader): JsonResponse
    {
        if ($response = $this->forbiddenUnlessQualityReader($request)) {
            return $response;
        }

        return response()->json($reader->routeSmokeView());
    }

    public function gate(Request $request, QualityDashboardReader $reader, string $gate): JsonResponse
    {
        if ($response = $this->forbiddenUnlessQualityReader($request)) {
            return $response;
        }

        return response()->json($reader->qualityGate($gate));
    }

    public function roadmap(Request $request, QualityDashboardReader $reader): JsonResponse
    {
        if ($response = $this->forbiddenUnlessQualityReader($request)) {
            return $response;
        }

        return response()->json($reader->roadmap());
    }

    public function run(Request $request, QualityDashboardReader $reader): JsonResponse
    {
        if ($response = $this->forbiddenUnlessQualityOperator($request)) {
            return $response;
        }

        $tool = (string) $request->input('tool', '');

        if (! in_array($tool, self::SUPPORTED_TOOLS, true)) {
            return response()->json([
                'error' => [
                    'code' => 'quality_tool_not_supported',
                    'message' => 'This quality tool is not exposed through the dashboard run endpoint.',
                ],
            ], 422);
        }

        if ($request->boolean('allow_mutating') || $request->boolean('allow_destructive')) {
            return response()->json([
                'error' => [
                    'code' => 'quality_run_scope_forbidden',
                    'message' => 'Dashboard quality runs cannot enable mutating or destructive route execution.',
                ],
            ], 422);
        }

        if ($tool === 'route-inventory') {
            Artisan::call('quality:route-inventory', ['--format' => 'json']);

            return response()->json($reader->routeInventory());
        }

        if ($tool === 'route-smoke') {
            Artisan::call('quality:route-smoke', [
                '--actor' => 'guest',
                '--format' => 'json',
                '--allow-mutating' => 'false',
                '--allow-destructive' => 'false',
            ]);

            return response()->json($reader->routeSmoke());
        }

        $gate = $this->gateFromTool($tool, (string) $request->input('gate', 'pull_request'));
        $exitCode = Artisan::call('quality:check-gates', [
            '--gate' => $gate,
            '--format' => 'json',
        ]);

        return response()->json([
            ...$reader->gateReport($gate),
            'exit_code' => $exitCode,
        ]);
    }

    private function forbiddenUnlessQualityReader(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (array_intersect($this->dashboardRoles($user), self::READ_ROLES) !== []) {
            return null;
        }

        return response()->json([
            'error' => [
                'code' => 'quality_dashboard_forbidden',
                'message' => 'This dashboard role cannot read quality reports.',
            ],
        ], 403);
    }

    private function forbiddenUnlessQualityOperator(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (array_intersect($this->dashboardRoles($user), self::OPERATE_ROLES) !== []) {
            return null;
        }

        return response()->json([
            'error' => [
                'code' => 'quality_dashboard_operation_forbidden',
                'message' => 'This dashboard role cannot run quality checks.',
            ],
        ], 403);
    }

    private function gateFromTool(string $tool, string $fallback): string
    {
        if (str_starts_with($tool, 'quality-gate:')) {
            return substr($tool, strlen('quality-gate:'));
        }

        return in_array($fallback, ['pull_request', 'nightly', 'release'], true) ? $fallback : 'pull_request';
    }
}
