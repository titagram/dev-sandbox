<?php

namespace App\Services\Graph;

use App\Services\Neo4j\Neo4jClient;
use Illuminate\Support\Facades\DB;

class GraphQueryService
{
    public function __construct(private readonly ?Neo4jClient $client = null, private readonly ?CanonicalGraphQueryService $canonical = null) {}

    /** @param array<string, mixed> $params */
    public function query(string $type, array $params): array
    {
        $result = ($this->canonical ?? new CanonicalGraphQueryService($this->client))->query(
            (string) ($params['project_id'] ?? ''),
            (string) ($params['scope_type'] ?? 'repository'),
            (string) ($params['scope_id'] ?? $params['repository_id'] ?? $this->repositoryId((string) ($params['project_id'] ?? ''))),
            $type,
            $params,
        );
        if (($result['reason'] ?? null) === 'graph_projection_not_ready') {
            $result['reason'] = 'graph_snapshot_not_found';
        }

        return $result;
    }

    private function repositoryId(string $projectId): string
    {
        return (string) DB::table('repositories')->where('project_id', $projectId)->orderBy('id')->value('id');
    }
}
