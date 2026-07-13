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

    /**
     * @return array{scopes: list<array{source_scope_type: string, source_scope_id: string, quality: string|null, head_commit: string|null, created_at: string|null, projection_status: string}>, truncated: bool}
     */
    public function listScopeMetadata(string $projectId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $bindings = DB::table('hades_workspace_bindings')
            ->where('project_id', $projectId)
            ->where('status', 'linked')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id'])
            ->map(fn (object $scope): array => [
                'source_scope_type' => 'workspace_binding',
                'source_scope_id' => (string) $scope->id,
            ]);
        $repositories = DB::table('repositories')
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id'])
            ->map(fn (object $scope): array => [
                'source_scope_type' => 'repository',
                'source_scope_id' => (string) $scope->id,
            ]);
        $allScopes = $bindings->concat($repositories)->values();
        $scopes = $allScopes->take($limit)->values();

        if ($scopes->isEmpty()) {
            return ['scopes' => [], 'truncated' => false];
        }

        $artifacts = collect();
        $bindingIds = $scopes->where('source_scope_type', 'workspace_binding')->pluck('source_scope_id');
        if ($bindingIds->isNotEmpty()) {
            $rankedHadesArtifacts = DB::table('hades_agent_artifacts')
                ->select(['workspace_binding_id as source_scope_id', 'id as artifact_id', 'created_at as artifact_created_at'])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY workspace_binding_id ORDER BY created_at DESC, id DESC) AS artifact_rank')
                ->where('project_id', $projectId)
                ->whereIn('workspace_binding_id', $bindingIds)
                ->whereIn('schema', ['hades.php_graph.v1', 'hades.code_graph.v1']);
            $artifacts = $artifacts->concat(
                DB::query()->fromSub($rankedHadesArtifacts, 'ranked_hades_artifacts')
                    ->where('artifact_rank', 1)
                    ->get()
                    ->map(fn (object $artifact): array => [
                        'source_scope_type' => 'workspace_binding',
                        'source_scope_id' => (string) $artifact->source_scope_id,
                        'artifact_type' => 'hades_agent_artifact',
                        'artifact_id' => (string) $artifact->artifact_id,
                        'created_at' => $artifact->artifact_created_at ? (string) $artifact->artifact_created_at : null,
                    ]),
            );
        }

        $repositoryIds = $scopes->where('source_scope_type', 'repository')->pluck('source_scope_id');
        if ($repositoryIds->isNotEmpty()) {
            $rankedSnapshotArtifacts = DB::table('snapshots')
                ->join('artifacts', 'artifacts.id', '=', 'snapshots.graph_snapshot_artifact_id')
                ->select([
                    'snapshots.repository_id as source_scope_id',
                    'artifacts.id as artifact_id',
                    'artifacts.created_at as artifact_created_at',
                ])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY snapshots.repository_id ORDER BY snapshots.created_at DESC, snapshots.id DESC) AS artifact_rank')
                ->where('snapshots.project_id', $projectId)
                ->whereIn('snapshots.repository_id', $repositoryIds)
                ->whereNotNull('snapshots.graph_snapshot_artifact_id')
                ->where('artifacts.project_id', $projectId)
                ->whereColumn('artifacts.repository_id', 'snapshots.repository_id');
            $artifacts = $artifacts->concat(
                DB::query()->fromSub($rankedSnapshotArtifacts, 'ranked_snapshot_artifacts')
                    ->where('artifact_rank', 1)
                    ->get()
                    ->map(fn (object $artifact): array => [
                        'source_scope_type' => 'repository',
                        'source_scope_id' => (string) $artifact->source_scope_id,
                        'artifact_type' => 'legacy_artifact',
                        'artifact_id' => (string) $artifact->artifact_id,
                        'created_at' => $artifact->artifact_created_at ? (string) $artifact->artifact_created_at : null,
                    ]),
            );
        }
        $artifacts = $artifacts->keyBy(fn (array $artifact): string => $artifact['source_scope_type']."\0".$artifact['source_scope_id']);

        $projections = collect();
        if ($artifacts->isNotEmpty()) {
            $projections = DB::table('canonical_graph_projections')
                ->where('project_id', $projectId)
                ->where(function ($query) use ($artifacts): void {
                    foreach ($artifacts->groupBy('artifact_type') as $artifactType => $typedArtifacts) {
                        $query->orWhere(function ($typedQuery) use ($artifactType, $typedArtifacts): void {
                            $typedQuery->where('artifact_type', $artifactType)
                                ->whereIn('artifact_id', $typedArtifacts->pluck('artifact_id'));
                        });
                    }
                })
                ->get(['source_scope_type', 'source_scope_id', 'artifact_type', 'artifact_id', 'quality', 'head_commit', 'status'])
                ->keyBy(fn (object $projection): string => $projection->artifact_type."\0".$projection->artifact_id);
        }

        return [
            'scopes' => $scopes->map(function (array $scope) use ($artifacts, $projections): array {
                $artifact = $artifacts->get($scope['source_scope_type']."\0".$scope['source_scope_id']);
                $projection = $artifact
                    ? $projections->get($artifact['artifact_type']."\0".$artifact['artifact_id'])
                    : null;
                if ($projection
                    && ($projection->source_scope_type !== $scope['source_scope_type']
                        || (string) $projection->source_scope_id !== $scope['source_scope_id'])) {
                    $projection = null;
                }

                return [
                    'source_scope_type' => $scope['source_scope_type'],
                    'source_scope_id' => $scope['source_scope_id'],
                    'quality' => $projection?->quality ? (string) $projection->quality : null,
                    'head_commit' => $projection?->head_commit ? (string) $projection->head_commit : null,
                    'created_at' => $artifact['created_at'] ?? null,
                    'projection_status' => $projection?->status ? (string) $projection->status : 'unavailable',
                ];
            })->all(),
            'truncated' => $allScopes->count() > $limit,
        ];
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
        $privateIdentityProvenance = $this->privateNodeIdentityProvenance($payload);

        return $this->normalizer->normalize($payload, $this->identity($projectId, 'workspace_binding', $bindingId, 'hades_agent_artifact', $artifact, $json))
            + ['private_identity_provenance' => $privateIdentityProvenance];
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
        $privateIdentityProvenance = $this->privateNodeIdentityProvenance($payload);

        return $this->normalizer->normalize($payload, $this->identity($projectId, 'repository', $repositoryId, 'legacy_artifact', $artifact, $json))
            + ['private_identity_provenance' => $privateIdentityProvenance];
    }

    /**
     * Preserve producer identity provenance before canonical normalization drops
     * presentation-irrelevant fields. This remains internal input for public
     * projection policy and is never persisted to Neo4j or returned by an API.
     *
     * @return array<string, list<string>>
     */
    private function privateNodeIdentityProvenance(array $payload): array
    {
        $rawNodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : ($payload['symbols'] ?? []);
        $provenance = [];

        foreach (array_filter($rawNodes, 'is_array') as $node) {
            $nodeId = $node['id'] ?? $node['symbol_id'] ?? null;
            if (! is_string($nodeId) || trim($nodeId) === '') {
                continue;
            }

            $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
            $values = [];
            foreach ([$node, $properties] as $identityContainer) {
                foreach (['id', 'external_id', 'symbol_id', 'source_ref', 'source_path', 'path'] as $field) {
                    if (is_string($identityContainer[$field] ?? null) && trim($identityContainer[$field]) !== '') {
                        $values[] = $identityContainer[$field];
                    }
                }

                $source = $identityContainer['source'] ?? null;
                if (! is_array($source)) {
                    continue;
                }
                foreach (['ref', 'path', 'id', 'external_id', 'symbol_id'] as $field) {
                    if (is_string($source[$field] ?? null) && trim($source[$field]) !== '') {
                        $values[] = $source[$field];
                    }
                }
            }

            $provenance[$nodeId] = array_values(array_unique([
                ...($provenance[$nodeId] ?? []),
                ...$values,
            ]));
        }

        return $provenance;
    }

    private function adaptLegacy(array $payload, string $extractor, string $language): array
    {
        if (isset($payload['graph_contract'])) {
            return $payload;
        }
        $payload = $this->adaptLegacyNodeIdentities($payload);
        $filesTotal = is_array($payload['files'] ?? null) ? count($payload['files']) : (int) ($payload['files_total'] ?? 0);
        $payload['graph_contract'] = [
            'version' => 'hades.graph_artifact.v1',
            'extractor' => ['name' => $extractor, 'version' => '1', 'mode' => 'legacy_adapter', 'quality' => 'partial', 'fallback_reason' => 'missing_contract_metadata'],
            'coverage' => ['languages' => [$language], 'files_total' => $filesTotal, 'files_analyzed' => $filesTotal, 'files_failed' => 0],
            'source' => ['branch' => $payload['branch'] ?? null, 'head_commit' => $payload['head_commit'] ?? $payload['workspace_head_commit'] ?? null],
        ];

        return $payload;
    }

    private function adaptLegacyNodeIdentities(array $payload): array
    {
        $nodeKey = is_array($payload['nodes'] ?? null) ? 'nodes' : 'symbols';
        $nodes = is_array($payload[$nodeKey] ?? null) ? array_values($payload[$nodeKey]) : [];
        $aliases = [];
        $nodeIds = [];

        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                continue;
            }

            $nodeId = $node['id'] ?? $node['symbol_id'] ?? null;
            if (! is_string($nodeId) || trim($nodeId) === '') {
                $identity = $this->legacyNodeIdentity($node);
                if ($identity === null) {
                    throw new InvalidArgumentException('Legacy graph node identity is missing.');
                }
                $nodeId = 'legacy-node:'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $node['id'] = $nodeId;
                $nodes[$index] = $node;
            }

            $nodeId = trim($nodeId);
            if (isset($nodeIds[$nodeId])) {
                throw new InvalidArgumentException('Legacy graph node identity is ambiguous.');
            }
            $nodeIds[$nodeId] = true;

            foreach ($this->legacyNodeAliases($node, $nodeId) as $alias) {
                $aliases[$alias][$nodeId] = true;
            }
        }

        $payload[$nodeKey] = $nodes;
        $edgeKey = is_array($payload['relationships'] ?? null) ? 'relationships' : 'edges';
        if (! is_array($payload[$edgeKey] ?? null)) {
            return $payload;
        }

        $payload[$edgeKey] = array_map(function ($edge) use ($aliases, $nodeIds) {
            if (! is_array($edge)) {
                return $edge;
            }

            foreach ([['source_id', 'source', 'from'], ['target_id', 'target', 'to']] as $endpointFields) {
                $endpoint = null;
                foreach ($endpointFields as $field) {
                    if (is_string($edge[$field] ?? null) && trim($edge[$field]) !== '') {
                        $endpoint = trim($edge[$field]);
                        break;
                    }
                }
                if ($endpoint === null || isset($nodeIds[$endpoint])) {
                    continue;
                }

                $candidates = array_keys($aliases[$endpoint] ?? []);
                if (count($candidates) > 1) {
                    throw new InvalidArgumentException('Legacy graph edge endpoint identity is ambiguous.');
                }
                if ($candidates !== []) {
                    $edge[$endpointFields[0]] = $candidates[0];
                }
            }

            return $edge;
        }, $payload[$edgeKey]);

        return $payload;
    }

    private function legacyNodeIdentity(array $node): ?array
    {
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $identity = [];
        foreach (['type', 'kind', 'name', 'signature', 'path'] as $field) {
            $value = $node[$field] ?? $properties[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $identity[$field] = trim($value);
            }
        }

        if (! array_intersect_key($identity, array_flip(['name', 'signature', 'path']))) {
            return null;
        }

        return $identity;
    }

    /** @return list<string> */
    private function legacyNodeAliases(array $node, string $nodeId): array
    {
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $aliases = [$nodeId];
        foreach (['id', 'symbol_id', 'name', 'signature', 'path'] as $field) {
            $value = $node[$field] ?? $properties[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $aliases[] = trim($value);
            }
        }

        return array_values(array_unique($aliases));
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
