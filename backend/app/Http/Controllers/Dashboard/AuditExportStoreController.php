<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Services\AuditExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditExportStoreController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request, AuditExportService $exports): JsonResponse
    {
        abort_unless($this->canManageSystem($request), 403);

        $validated = $request->validate([
            'format' => ['required', 'string', 'in:jsonl,csv'],
            'filters' => ['nullable', 'array'],
            'filters.action' => ['nullable', 'string', 'max:255'],
            'filters.actor_type' => ['nullable', 'string', 'in:user,plugin,system'],
            'filters.from' => ['nullable', 'date'],
            'filters.to' => ['nullable', 'date', 'after_or_equal:filters.from'],
        ]);

        return response()->json(
            $exports->export($validated['filters'] ?? [], $validated['format'])
        );
    }

    private function canManageSystem(Request $request): bool
    {
        $user = $request->user();

        return $this->userHasRole($user, 'Sysadmin') || $this->userHasRole($user, 'Admin');
    }
}
