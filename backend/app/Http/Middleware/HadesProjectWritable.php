<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class HadesProjectWritable
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || $request->is('api/hades/v1/causal-packs/*/replay')
            || $request->is('api/hades/v1/privacy/delete')
            || $request->is('api/hades/v1/privacy/retention-cleanup')) {
            return $next($request);
        }

        $projectId = trim((string) $request->input('project_id', ''));
        if ($projectId === '') {
            return $next($request);
        }

        $project = DB::table('projects')->where('id', $projectId)->first(['status', 'archived_at', 'deleted_at']);
        if (! $project) {
            return $next($request);
        }

        if ($project->deleted_at !== null || $project->status === 'deleted') {
            return $this->error('project_deleted', 'Hades mutations are disabled for deleted projects.');
        }

        if ($project->archived_at !== null || $project->status === 'archived') {
            return $this->error('project_archived', 'Hades mutations are disabled for archived projects.');
        }

        return $next($request);
    }

    private function error(string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], Response::HTTP_CONFLICT);
    }
}
