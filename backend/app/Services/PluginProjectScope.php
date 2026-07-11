<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class PluginProjectScope
{
    public function tokenProjectId(Request $request): ?string
    {
        $auth = $request->attributes->get('plugin_auth');
        $projectId = is_array($auth) ? ($auth['token']->project_id ?? null) : null;
        $projectId = is_string($projectId) ? trim($projectId) : '';

        return $projectId !== '' ? $projectId : null;
    }

    public function authorize(Request $request, string $projectId): ?JsonResponse
    {
        $scopedProjectId = $this->tokenProjectId($request);
        if ($scopedProjectId === null || hash_equals($scopedProjectId, $projectId)) {
            return null;
        }

        return response()->json([
            'error' => [
                'code' => 'project_scope_mismatch',
                'message' => 'Plugin token is scoped to a different project.',
            ],
        ], Response::HTTP_FORBIDDEN);
    }

    public function authorizeWorkItem(Request $request, string $workItemId): ?JsonResponse
    {
        $item = DB::table('agent_work_items')->where('id', $workItemId)->first();
        if (! $item) {
            return null;
        }

        return $this->authorize($request, (string) $item->project_id);
    }
}
