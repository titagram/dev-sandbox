<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Services\ArtifactRetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArtifactRetentionRunController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request, ArtifactRetentionService $retention): JsonResponse
    {
        abort_unless($this->canManageSystem($request), 403);

        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'dry_run' => ['required', 'boolean'],
            'confirm_purge' => ['nullable', 'boolean'],
        ]);

        if (! $validated['dry_run'] && ! ($validated['confirm_purge'] ?? false)) {
            return response()->json([
                'message' => 'The confirm purge field must be accepted for a live purge.',
                'errors' => [
                    'confirm_purge' => ['Explicit confirmation is required before a live purge.'],
                ],
            ], 422);
        }

        return response()->json(
            $retention->purgeOlderThan(
                $validated['days'],
                $validated['dry_run'],
                $validated['limit'] ?? null,
            )
        );
    }

    private function canManageSystem(Request $request): bool
    {
        $user = $request->user();

        return $this->userHasRole($user, 'Sysadmin') || $this->userHasRole($user, 'Admin');
    }
}
