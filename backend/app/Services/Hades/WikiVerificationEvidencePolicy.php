<?php

namespace App\Services\Hades;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class WikiVerificationEvidencePolicy
{
    private const GIT_TREE_SCHEMA = 'hades.git_tree.v1';

    private const MAX_TOTAL_CLAIMS = 80;

    public function __construct(
        private readonly HadesProjectAwareness $awareness,
        private readonly HadesArtifactIntegrity $integrity,
    ) {}

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

        $bindingHead = trim((string) ($binding->head_commit ?? ''));
        if ($bindingHead === '') {
            throw $this->stale('The linked workspace head commit is unknown.');
        }

        $resolved = [];
        $gitTree = null;
        $claimCount = 0;

        foreach ($refs as $ref) {
            $kind = $ref['kind'] ?? null;
            $claims = $this->claims($ref['claims'] ?? null, $claimCount);

            if ($kind === 'artifact_ref') {
                $resolved[] = $this->resolveArtifact($projectId, $workspaceBindingId, $binding, $bindingHead, $ref) + ['claims' => $claims];

                continue;
            }

            if ($kind === 'file_ref') {
                $path = $this->safeRelativePath($ref['path'] ?? null);
                $sha256 = $this->sha256($ref['sha256'] ?? $ref['hash'] ?? null);
                $gitTree ??= $this->latestGitTree($projectId, $workspaceBindingId);
                $resolved[] = $this->resolveFile($binding, $bindingHead, $gitTree, $path, $sha256) + ['claims' => $claims];

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
        string $bindingHead,
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

        try {
            $this->integrity->validateWikiArtifact($artifact);
        } catch (InvalidArgumentException $exception) {
            throw $this->invalid($exception->getMessage());
        }

        $artifactHead = $this->currentArtifactHead($artifact, $bindingHead);

        return [
            'kind' => 'artifact_ref',
            'artifact_id' => $artifact->id,
            'schema' => $artifact->schema,
            'sha256' => $artifact->sha256,
            'workspace_binding_id' => $binding->id,
            'head_commit' => $artifactHead,
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

        try {
            $this->integrity->validateWikiArtifact($gitTree);
        } catch (InvalidArgumentException $exception) {
            throw $this->invalid($exception->getMessage());
        }

        return $gitTree;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFile(
        object $binding,
        string $bindingHead,
        object $gitTree,
        string $path,
        string $sha256,
    ): array {
        $artifactHead = $this->currentArtifactHead($gitTree, $bindingHead);
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
            'head_commit' => $artifactHead,
        ];
    }

    private function currentArtifactHead(object $artifact, string $bindingHead): string
    {
        $artifactHead = $this->awareness->artifactHeadCommit($artifact->artifact ?? null);

        if ($artifactHead === null || ! hash_equals($bindingHead, $artifactHead)) {
            throw $this->stale('Artifact evidence does not match the linked workspace head commit.');
        }

        return $artifactHead;
    }

    private function sha256(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/i', $value) !== 1) {
            throw $this->invalid('Evidence SHA-256 must be a 64-character hexadecimal hash.');
        }

        return strtolower($value);
    }

    /**
     * Normalize the agent-authored claim attestations attached to each evidence
     * reference. These mappings make the agent's reasoning auditable; the
     * server still establishes only artifact/file integrity and freshness.
     *
     * @return list<array{claim: string, proof: string}>
     */
    private function claims(mixed $value, int &$total): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 8) {
            throw $this->invalid('Evidence claims must be a non-empty bounded list.');
        }

        $claims = [];
        foreach ($value as $mapping) {
            if (! is_array($mapping)
                || count($mapping) !== 2
                || ! array_key_exists('claim', $mapping)
                || ! array_key_exists('proof', $mapping)) {
                throw $this->invalid('Evidence claim mapping is invalid.');
            }
            $claim = is_string($mapping['claim']) ? trim($mapping['claim']) : '';
            $proof = is_string($mapping['proof']) ? trim($mapping['proof']) : '';
            if ($claim === '' || $proof === '' || mb_strlen($claim) > 500 || mb_strlen($proof) > 500) {
                throw $this->invalid('Evidence claim mapping is invalid.');
            }
            $claims[] = ['claim' => $claim, 'proof' => $proof];
            $total++;
            if ($total > self::MAX_TOTAL_CLAIMS) {
                throw $this->invalid('Evidence claims exceed the total limit.');
            }
        }

        return $claims;
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
