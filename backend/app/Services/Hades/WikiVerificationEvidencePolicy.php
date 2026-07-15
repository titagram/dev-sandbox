<?php

namespace App\Services\Hades;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class WikiVerificationEvidencePolicy
{
    private const GIT_TREE_SCHEMA = 'hades.git_tree.v1';

    /**
     * @param  list<array<string, mixed>>  $refs
     * @return list<array<string, mixed>>
     */
    public function resolve(string $projectId, string $workspaceBindingId, array $refs): array
    {
        if ($refs === []) {
            throw $this->invalid('At least one code-derived evidence reference is required.');
        }

        $binding = DB::table('hades_workspace_bindings')
            ->where('id', $workspaceBindingId)
            ->where('project_id', $projectId)
            ->where('status', 'linked')
            ->lockForUpdate()
            ->first();

        if ($binding === null) {
            throw $this->invalid('The evidence workspace binding is not linked to the project.');
        }

        $resolved = [];
        $gitTree = null;

        foreach ($refs as $ref) {
            $kind = $ref['kind'] ?? null;

            if ($kind === 'artifact_ref') {
                $resolved[] = $this->resolveArtifact($projectId, $workspaceBindingId, $binding, $ref);

                continue;
            }

            if ($kind === 'file_ref') {
                $gitTree ??= $this->latestGitTree($projectId, $workspaceBindingId);
                $resolved[] = $this->resolveFile($binding, $gitTree, $ref);

                continue;
            }

            throw $this->invalid('Evidence kind is not supported.');
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $ref
     * @return array<string, mixed>
     */
    private function resolveArtifact(
        string $projectId,
        string $workspaceBindingId,
        object $binding,
        array $ref,
    ): array {
        $sha256 = $this->sha256($ref['sha256'] ?? null);
        $schema = $ref['schema'] ?? null;

        if ($schema !== null && (! is_string($schema) || $schema === '')) {
            throw $this->invalid('Artifact evidence schema is invalid.');
        }

        $artifact = DB::table('hades_agent_artifacts')
            ->where('project_id', $projectId)
            ->where('workspace_binding_id', $workspaceBindingId)
            ->where('sha256', $sha256)
            ->when($schema !== null, fn ($query) => $query->where('schema', $schema))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($artifact === null) {
            throw $this->invalid('Artifact evidence could not be resolved in the linked workspace.');
        }

        return [
            'kind' => 'artifact_ref',
            'artifact_id' => $artifact->id,
            'schema' => $artifact->schema,
            'sha256' => $artifact->sha256,
            'workspace_binding_id' => $binding->id,
            'head_commit' => $binding->head_commit,
        ];
    }

    private function latestGitTree(string $projectId, string $workspaceBindingId): object
    {
        $gitTree = DB::table('hades_agent_artifacts')
            ->where('project_id', $projectId)
            ->where('workspace_binding_id', $workspaceBindingId)
            ->where('schema', self::GIT_TREE_SCHEMA)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($gitTree === null) {
            throw $this->stale('No current git tree exists for the linked workspace.');
        }

        return $gitTree;
    }

    /**
     * @param  array<string, mixed>  $ref
     * @return array<string, mixed>
     */
    private function resolveFile(object $binding, object $gitTree, array $ref): array
    {
        $path = $this->safeRelativePath($ref['path'] ?? null);
        $sha256 = $this->sha256($ref['sha256'] ?? $ref['hash'] ?? null);
        $payload = is_string($gitTree->artifact)
            ? json_decode($gitTree->artifact, true)
            : $gitTree->artifact;
        $files = is_array($payload) && is_array($payload['files'] ?? null)
            ? $payload['files']
            : [];

        $matchesLatestTree = collect($files)->contains(function (mixed $file) use ($path, $sha256): bool {
            if (! is_array($file)) {
                return false;
            }

            $fileHash = $file['sha256'] ?? $file['hash'] ?? null;

            return ($file['path'] ?? null) === $path && $fileHash === $sha256;
        });

        if (! $matchesLatestTree) {
            throw $this->stale('File evidence is absent from the latest git tree.');
        }

        return [
            'kind' => 'file_ref',
            'artifact_id' => $gitTree->id,
            'schema' => self::GIT_TREE_SCHEMA,
            'path' => $path,
            'sha256' => $sha256,
            'workspace_binding_id' => $binding->id,
            'head_commit' => $binding->head_commit,
        ];
    }

    private function sha256(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/i', $value) !== 1) {
            throw $this->invalid('Evidence SHA-256 must be a 64-character hexadecimal hash.');
        }

        return strtolower($value);
    }

    private function safeRelativePath(mixed $value): string
    {
        if (! is_string($value)
            || $value === ''
            || strlen($value) > 2048
            || str_starts_with($value, '/')
            || str_contains($value, '\\')
            || preg_match('/\A[A-Za-z]:/', $value) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw $this->invalid('File evidence path must be a safe relative path.');
        }

        $segments = explode('/', $value);
        if (collect($segments)->contains(fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..')) {
            throw $this->invalid('File evidence path must be a safe relative path.');
        }

        return $value;
    }

    private function invalid(string $message): HadesTokenException
    {
        return new HadesTokenException('evidence_invalid', $message, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function stale(string $message): HadesTokenException
    {
        return new HadesTokenException('evidence_stale', $message, Response::HTTP_CONFLICT);
    }
}
