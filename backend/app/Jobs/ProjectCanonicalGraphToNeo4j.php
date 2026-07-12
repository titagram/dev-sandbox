<?php

namespace App\Jobs;

use App\Services\Graph\CanonicalGraphProjectionService;
use App\Services\Graph\CanonicalGraphRepository;
use App\Services\Graph\Neo4jCanonicalGraphProjector;
use App\Services\Neo4jClientFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProjectCanonicalGraphToNeo4j implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $projectionId) {}

    public function handle(CanonicalGraphRepository $repository, CanonicalGraphProjectionService $projections, Neo4jCanonicalGraphProjector $projector, Neo4jClientFactory $clients): void
    {
        $projection = DB::table('canonical_graph_projections')->where('id', $this->projectionId)->first();
        if ($projection === null) {
            throw new RuntimeException('Canonical graph projection was not found.');
        }
        try {
            $graph = $repository->findByIdentity($projection->project_id, $projection->source_scope_type, $projection->source_scope_id, $projection->artifact_type, $projection->artifact_id);
            if ($graph === null) {
                throw new RuntimeException('artifact_missing');
            }
            if (! hash_equals($projection->checksum, $graph['identity']['checksum'])) {
                throw new RuntimeException('artifact_changed');
            }
            $projections->markProjecting($projection->id);
            $counts = $projector->project($graph, $projection, $clients->client());
            $projections->markReady($projection->id, $counts['nodes'], $counts['relationships']);
        } catch (Throwable $exception) {
            $code = in_array($exception->getMessage(), ['artifact_missing', 'artifact_changed'], true) ? $exception->getMessage() : (str_contains(strtolower($exception->getMessage()), 'connect') ? 'neo4j_unavailable' : 'neo4j_query_failed');
            $projections->markFailed($projection->id, $code);
            throw $exception;
        }
    }
}
