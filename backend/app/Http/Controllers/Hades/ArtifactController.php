<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ArtifactController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'agent_id' => ['nullable', 'string', 'max:191'],
            'workspace_binding_id' => ['required', 'string'],
            'job_id' => ['nullable', 'string'],
            'schema' => ['required', 'string', 'in:hades.git_tree.v1,hades.symbols.v1'],
            'artifact' => ['required', 'array'],
            'sha256' => ['nullable', 'string', 'size:64'],
            'truncated' => ['nullable', 'boolean'],
            'redactions' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id'], $validated['agent_id'] ?? null);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        if (($validated['job_id'] ?? null) !== null) {
            $jobExists = DB::table('hades_agent_jobs')
                ->where('id', $validated['job_id'])
                ->where('project_id', $validated['project_id'])
                ->where('workspace_binding_id', $binding->id)
                ->exists();

            if (! $jobExists) {
                return $this->error('job_not_found', 'Hades agent job was not found.', Response::HTTP_NOT_FOUND);
            }
        }

        $artifactJson = json_encode($validated['artifact'], JSON_THROW_ON_ERROR);
        $id = (string) Str::ulid();
        $now = now();

        DB::table('hades_agent_artifacts')->insert([
            'id' => $id,
            'project_id' => $validated['project_id'],
            'hades_agent_id' => $agent->id,
            'workspace_binding_id' => $binding->id,
            'job_id' => $validated['job_id'] ?? null,
            'schema' => $validated['schema'],
            'artifact' => $artifactJson,
            'sha256' => $validated['sha256'] ?? hash('sha256', $artifactJson),
            'truncated' => (bool) ($validated['truncated'] ?? false),
            'redactions' => (int) ($validated['redactions'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $artifact = DB::table('hades_agent_artifacts')->where('id', $id)->first();

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'artifact' => $this->payload($artifact),
            'server_time' => now()->toISOString(),
        ], Response::HTTP_CREATED);
    }

    private function linkedBinding(object $agent, string $projectId, string $bindingId, ?string $externalAgentId): mixed
    {
        if ($agent->project_id !== $projectId) {
            return $this->error('project_mismatch', 'Hades agent token is scoped to a different project.', Response::HTTP_FORBIDDEN);
        }

        if ($externalAgentId !== null && $externalAgentId !== $agent->external_agent_id) {
            return $this->error('agent_mismatch', 'Hades agent token is scoped to a different external agent.', Response::HTTP_FORBIDDEN);
        }

        $binding = DB::table('hades_workspace_bindings')
            ->where('id', $bindingId)
            ->where('project_id', $projectId)
            ->where('hades_agent_id', $agent->id)
            ->first();

        if (! $binding) {
            return $this->error('workspace_binding_not_found', 'Workspace binding was not found.', Response::HTTP_NOT_FOUND);
        }

        if ($binding->status !== 'linked') {
            return $this->error('workspace_binding_unlinked', 'Workspace binding is not linked.', Response::HTTP_CONFLICT);
        }

        return $binding;
    }

    private function payload(object $artifact): array
    {
        return [
            'id' => $artifact->id,
            'project_id' => $artifact->project_id,
            'workspace_binding_id' => $artifact->workspace_binding_id,
            'job_id' => $artifact->job_id,
            'schema' => $artifact->schema,
            'sha256' => $artifact->sha256,
            'truncated' => (bool) $artifact->truncated,
            'redactions' => (int) $artifact->redactions,
            'created_at' => $artifact->created_at,
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
