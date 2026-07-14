<?php

namespace App\Jobs;

use App\Services\Graph\CanonicalGraphProjectionService;
use App\Services\Graph\CanonicalGraphRepository;
use App\Services\Graph\Neo4jCanonicalGraphProjector;
use App\Services\Neo4jClientFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProjectCanonicalGraphToNeo4j implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $projectionId) {}

    public function handle(CanonicalGraphRepository $repository, CanonicalGraphProjectionService $projections, Neo4jCanonicalGraphProjector $projector, Neo4jClientFactory $clients): void
    {
        $projection = $projections->findForWorker($this->projectionId);
        if ($projection === null || ! in_array($projection->status, ['queued', 'projecting'], true)) {
            return;
        }
        $claim = null;
        $client = null;
        try {
            $graph = $repository->findByIdentity($projection->project_id, $projection->source_scope_type, $projection->source_scope_id, $projection->artifact_type, $projection->artifact_id);
            if ($graph === null) {
                throw new RuntimeException('artifact_missing');
            }
            if (! hash_equals($projection->checksum, $graph['identity']['checksum'])) {
                throw new RuntimeException('artifact_changed');
            }
            $client = $clients->client();
            $projections->recoverStalePublications($graph, $client, $projector);
            $claim = $projections->acquireForWorkerPublication($projection->id, $graph);
            if (! $claim['claimed']) {
                if ($claim['retry_after'] !== null) {
                    $this->release($claim['retry_after']);
                }

                return;
            }
            $candidate = $claim['projection'];
            $heartbeat = function () use ($projections, $claim): bool {
                if (! $projections->heartbeatPublicationAttempt($claim['attempt_id'], $claim['owner_token'])) {
                    throw new RuntimeException('ownership_lost');
                }

                return true;
            };
            $counts = $projector->project($graph, $candidate, $client, $heartbeat);
            $ready = $projections->publishPublicationAttempt(
                $claim['attempt_id'], $claim['owner_token'], $counts['nodes'], $counts['relationships'],
                fn () => $projector->publishCurrent($candidate, $client),
            );
            if ($ready === null) {
                throw new RuntimeException('ownership_lost');
            }
        } catch (Throwable $exception) {
            $failureCode = $this->failureCode($exception);
            if ($claim !== null && $claim['claimed'] && $client !== null) {
                $projections->markPublicationAttemptFailed($claim['attempt_id'], $claim['owner_token'], $failureCode);
                try {
                    $projections->cleanupPublicationAttempt(
                        $claim['attempt_id'], $claim['owner_token'], $client, $projector,
                    );
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            } else {
                $projections->markQueuedRetryPending($projection->id, $failureCode);
            }
            throw $exception;
        }
    }

    /**
     * Laravel invokes this hook only after the queue has exhausted retries.
     */
    public function failed(Throwable $exception): void
    {
        app(CanonicalGraphProjectionService::class)->markFailedAfterAttemptExhaustion(
            $this->projectionId,
            $this->failureCode($exception),
        );
    }

    private function failureCode(Throwable $exception): string
    {
        if (in_array($exception->getMessage(), ['artifact_missing', 'artifact_changed'], true)) {
            return $exception->getMessage();
        }

        return $this->isNeo4jUnavailable($exception) ? 'neo4j_unavailable' : 'neo4j_query_failed';
    }

    private function isNeo4jUnavailable(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $class = strtolower($current::class);
            if (str_contains($class, 'connectexception') || str_contains($class, 'serviceunavailable') || str_contains($class, 'timeoutexception') || str_contains($class, 'transportexception')) {
                return true;
            }
            $message = strtolower($current->getMessage());
            if (preg_match('/connection (?:refused|reset)|service\s*unavailable|timed?\s*out|timeout|could not resolve|name or service not known|host unreachable|network is unreachable/', $message) === 1) {
                return true;
            }
        }

        return false;
    }
}
