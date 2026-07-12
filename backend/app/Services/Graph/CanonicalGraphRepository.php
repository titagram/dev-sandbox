<?php

namespace App\Services\Graph;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class CanonicalGraphRepository
{
    private const SCOPES = ['workspace_binding', 'repository'];

    public function __construct(private readonly CanonicalGraphNormalizer $normalizer) {}

    public function latestForScope(string $projectId, string $scopeType, string $scopeId): ?array
    {
        $this->assertScope($scopeType);

        return match ($scopeType) {
            'workspace_binding' => $this->latestHades($projectId, $scopeId),
            'repository' => $this->latestSnapshot($projectId, $scopeId),
        };
    }

    public function findByIdentity(string $projectId, string $scopeType, string $scopeId, string $artifactType, string $artifactId): ?array
    {
        $this->assertScope($scopeType);

        if ($scopeType === 'workspace_binding') {
            if ($artifactType !== 'hades_agent_artifact' || ! $this->linkedBindingExists($projectId, $scopeId)) {
                return null;
            }
            $artifact = DB::table('hades_agent_artifacts')
                ->where('id', $artifactId)->where('project_id', $projectId)->where('workspace_binding_id', $scopeId)
                ->whereIn('schema', ['hades.php_graph.v1', 'hades.code_graph.v1'])->first();

            return $artifact ? $this->normalizeHades($artifact, $projectId, $scopeId) : null;
        }

        if ($artifactType !== 'legacy_artifact' || ! $this->repositoryExists($projectId, $scopeId)) {
            return null;
        }
        $artifact = DB::table('snapshots')
            ->join('artifacts', 'artifacts.id', '=', 'snapshots.graph_snapshot_artifact_id')
            ->where('snapshots.project_id', $projectId)->where('snapshots.repository_id', $scopeId)
            ->where('artifacts.id', $artifactId)->where('artifacts.project_id', $projectId)
            ->where('artifacts.repository_id', $scopeId)->select('artifacts.*')->first();

        return $artifact ? $this->normalizeSnapshot($artifact, $projectId, $scopeId) : null;
    }

    public function listScopes(string $projectId): array
    {
        $bindings = DB::table('hades_workspace_bindings')->where('project_id', $projectId)->where('status', 'linked')
            ->orderBy('id')->pluck('id')->map(fn ($id): array => ['source_scope_type' => 'workspace_binding', 'source_scope_id' => (string) $id]);
        $repositories = DB::table('repositories')->where('project_id', $projectId)->orderBy('id')->pluck('id')
            ->map(fn ($id): array => ['source_scope_type' => 'repository', 'source_scope_id' => (string) $id]);

        return $bindings->concat($repositories)->values()->all();
    }

    private function latestHades(string $projectId, string $bindingId): ?array
    {
        if (! $this->linkedBindingExists($projectId, $bindingId)) {
            return null;
        }
        $artifact = DB::table('hades_agent_artifacts')->where('project_id', $projectId)->where('workspace_binding_id', $bindingId)
            ->whereIn('schema', ['hades.php_graph.v1', 'hades.code_graph.v1'])->orderByDesc('created_at')->orderByDesc('id')->first();

        return $artifact ? $this->normalizeHades($artifact, $projectId, $bindingId) : null;
    }

    private function latestSnapshot(string $projectId, string $repositoryId): ?array
    {
        if (! $this->repositoryExists($projectId, $repositoryId)) {
            return null;
        }
        $artifact = DB::table('snapshots')->join('artifacts', 'artifacts.id', '=', 'snapshots.graph_snapshot_artifact_id')
            ->where('snapshots.project_id', $projectId)->where('snapshots.repository_id', $repositoryId)
            ->whereNotNull('snapshots.graph_snapshot_artifact_id')->where('artifacts.project_id', $projectId)
            ->where('artifacts.repository_id', $repositoryId)->orderByDesc('snapshots.created_at')->orderByDesc('snapshots.id')
            ->select('artifacts.*')->first();

        return $artifact ? $this->normalizeSnapshot($artifact, $projectId, $repositoryId) : null;
    }

    private function normalizeHades(object $artifact, string $projectId, string $bindingId): array
    {
        $json = (string) $artifact->artifact;
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $language = (string) ($payload['language'] ?? ($artifact->schema === 'hades.php_graph.v1' ? 'php' : 'unknown'));
        $payload = $this->adaptLegacy($payload, 'hades-legacy-'.$language, $language);

        return $this->normalizer->normalize($payload, $this->identity($projectId, 'workspace_binding', $bindingId, 'hades_agent_artifact', $artifact, $json));
    }

    private function normalizeSnapshot(object $artifact, string $projectId, string $repositoryId): ?array
    {
        if (! Storage::disk('local')->exists($artifact->storage_path)) {
            return null;
        }
        $json = Storage::disk('local')->get($artifact->storage_path);
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $language = (string) ($payload['language'] ?? 'unknown');
        $payload = $this->adaptLegacy($payload, 'legacy-analyzer', $language);

        return $this->normalizer->normalize($payload, $this->identity($projectId, 'repository', $repositoryId, 'legacy_artifact', $artifact, $json));
    }

    private function adaptLegacy(array $payload, string $extractor, string $language): array
    {
        if (isset($payload['graph_contract'])) {
            return $payload;
        }
        $filesTotal = is_array($payload['files'] ?? null) ? count($payload['files']) : (int) ($payload['files_total'] ?? 0);
        $payload['graph_contract'] = [
            'version' => 'hades.graph_artifact.v1',
            'extractor' => ['name' => $extractor, 'version' => '1', 'mode' => 'legacy_adapter', 'quality' => 'partial', 'fallback_reason' => 'missing_contract_metadata'],
            'coverage' => ['languages' => [$language], 'files_total' => $filesTotal, 'files_analyzed' => $filesTotal, 'files_failed' => 0],
            'source' => ['branch' => $payload['branch'] ?? null, 'head_commit' => $payload['head_commit'] ?? $payload['workspace_head_commit'] ?? null],
        ];

        return $payload;
    }

    private function identity(string $projectId, string $scopeType, string $scopeId, string $artifactType, object $artifact, string $json): array
    {
        return ['project_id' => $projectId, 'source_scope_type' => $scopeType, 'source_scope_id' => $scopeId, 'artifact_type' => $artifactType, 'artifact_id' => (string) $artifact->id, 'checksum' => (string) ($artifact->sha256 ?: hash('sha256', $json)), 'created_at' => (string) $artifact->created_at];
    }

    private function linkedBindingExists(string $projectId, string $bindingId): bool
    {
        return DB::table('hades_workspace_bindings')->where('id', $bindingId)->where('project_id', $projectId)->where('status', 'linked')->exists();
    }

    private function repositoryExists(string $projectId, string $repositoryId): bool
    {
        return DB::table('repositories')->where('id', $repositoryId)->where('project_id', $projectId)->exists();
    }

    private function assertScope(string $scopeType): void
    {
        if (! in_array($scopeType, self::SCOPES, true)) {
            throw new InvalidArgumentException("Unsupported graph source scope: {$scopeType}");
        }
    }
}
