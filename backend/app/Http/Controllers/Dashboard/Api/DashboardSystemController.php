<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Dashboard\DashboardApiReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Services\ArtifactRetentionService;
use App\Services\AuditExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardSystemController extends Controller
{
    use ChecksDashboardRoles;

    public function show(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessSystemOperator($request);

        return response()->json($reader->systemStatus());
    }

    public function retention(Request $request, DashboardApiReader $reader, ArtifactRetentionService $retention): JsonResponse
    {
        $this->abortUnlessSystemOperator($request);

        $validated = $request->validate([
            'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'auto_purge_enabled' => ['required', 'boolean'],
        ]);

        $retention->purgeOlderThan(
            days: $validated['retention_days'],
            dryRun: ! $validated['auto_purge_enabled'],
            limit: 500,
        );

        return response()->json($reader->systemStatus([
            'label' => 'Artifact retention',
            'status' => 'ok',
            'at' => now()->toIso8601String(),
        ]));
    }

    public function auditExport(Request $request, DashboardApiReader $reader, AuditExportService $exports): JsonResponse
    {
        $this->abortUnlessSystemOperator($request);

        $validated = $request->validate([
            'range_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        $exports->export([
            'from' => now()->subDays($validated['range_days'])->toDateTimeString(),
            'to' => now()->toDateTimeString(),
        ], 'jsonl');

        return response()->json($reader->systemStatus([
            'label' => 'Audit export',
            'status' => 'ok',
            'at' => now()->toIso8601String(),
        ]));
    }

    private function abortUnlessSystemOperator(Request $request): void
    {
        abort_unless(
            $this->userHasRole($request->user(), 'Admin')
            || $this->userHasRole($request->user(), 'Sysadmin'),
            403,
        );
    }
}
