<?php

namespace App\Services\Graph;

use App\Services\Neo4j\Neo4jClient;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CanonicalGraphProjectionService
{
    private const FORCED_REBUILD_LEASE_SECONDS = 300;

    /**
     * Fetch exact projection identities with one bounded query.
     *
     * @param  list<array>  $graphs
     * @return array<string, object>
     */
    public function findForGraphs(array $graphs, bool $lockForUpdate = false): array
    {
        if ($graphs === []) {
            return [];
        }

        $identities = collect($graphs)->map(fn (array $graph): array => $graph['identity']);
        $query = DB::table('canonical_graph_projections')
            ->where(function ($query) use ($identities): void {
                foreach ($identities->groupBy('artifact_type') as $artifactType => $typedIdentities) {
                    $query->orWhere(function ($typedQuery) use ($artifactType, $typedIdentities): void {
                        $typedQuery->where('artifact_type', $artifactType)
                            ->whereIn('artifact_id', $typedIdentities->pluck('artifact_id'));
                    });
                }
            });
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get()->keyBy(fn (object $projection): string => $this->identityKey(
            (string) $projection->artifact_type,
            (string) $projection->artifact_id,
        ))->all();
    }

    /**
     * Atomically claim missing and failed projections for reconciliation.
     * The returned `claimed` bit is true only for the caller that won the
     * unique insert or the locked failed-to-queued transition.
     *
     * @param  list<array>  $graphs
     * @return array<string, array{projection: object, claimed: bool, conflict: bool}>
     */
    public function claimForReconcile(array $graphs): array
    {
        if ($graphs === []) {
            return [];
        }

        $projectIds = collect($graphs)->pluck('identity.project_id')->unique()->values();
        if ($projectIds->count() !== 1) {
            throw new RuntimeException('A reconciliation claim must target one project.');
        }

        return DB::transaction(function () use ($graphs, $projectIds): array {
            DB::table('projects')->where('id', (string) $projectIds->first())->lockForUpdate()->firstOrFail();
            $existing = $this->findForGraphs($graphs, lockForUpdate: true);
            $now = now();
            $proposals = [];

            foreach ($graphs as $graph) {
                $identity = $graph['identity'];
                $key = $this->identityKey((string) $identity['artifact_type'], (string) $identity['artifact_id']);
                if (isset($existing[$key])) {
                    continue;
                }

                $proposals[$key] = $this->projectionAttributes($graph, (string) Str::ulid(), $now);
            }

            if ($proposals !== []) {
                DB::table('canonical_graph_projections')->insertOrIgnore(array_values($proposals));
            }

            $current = $this->findForGraphs($graphs, lockForUpdate: true);
            $failedIds = [];
            foreach ($graphs as $graph) {
                $identity = $graph['identity'];
                $key = $this->identityKey((string) $identity['artifact_type'], (string) $identity['artifact_id']);
                $projection = $current[$key];
                $insertWinner = isset($proposals[$key]) && $projection->id === $proposals[$key]['id'];
                if (! $insertWinner && $this->matchesGraph($projection, $graph) && $projection->status === 'failed') {
                    $failedIds[] = (string) $projection->id;
                }
            }

            if ($failedIds !== []) {
                DB::table('canonical_graph_projections')
                    ->whereIn('id', $failedIds)
                    ->where('status', 'failed')
                    ->update([
                        'status' => 'queued',
                        'error_code' => null,
                        'updated_at' => $now,
                    ]);
                $current = $this->findForGraphs($graphs, lockForUpdate: true);
            }

            $claims = [];
            foreach ($graphs as $graph) {
                $identity = $graph['identity'];
                $key = $this->identityKey((string) $identity['artifact_type'], (string) $identity['artifact_id']);
                $projection = $current[$key];
                $claims[$key] = [
                    'projection' => $projection,
                    'claimed' => (isset($proposals[$key]) && $projection->id === $proposals[$key]['id'])
                        || in_array((string) $projection->id, $failedIds, true),
                    'conflict' => ! $this->matchesGraph($projection, $graph),
                ];
            }

            return $claims;
        });
    }

    public function queue(array $graph): object
    {
        $identity = $graph['identity'];
        $artifactType = (string) $identity['artifact_type'];
        $artifactId = (string) $identity['artifact_id'];
        $checksum = (string) $identity['checksum'];
        $now = now();

        DB::table('canonical_graph_projections')->insertOrIgnore(
            $this->projectionAttributes($graph, (string) Str::ulid(), $now),
        );

        return DB::table('canonical_graph_projections')
            ->where('artifact_type', $artifactType)
            ->where('artifact_id', $artifactId)
            ->firstOrFail();
    }

    /**
     * Acquire a separate physical attempt while the published projection row
     * remains queryable. The stable project lock serializes competing force
     * callers without stealing queued or projecting worker deliveries.
     *
     * @return array{projection: object, claimed: bool, conflict: bool, attempt_id: string|null, owner_token: string|null}
     */
    public function acquireForForcedRebuild(array $graph): array
    {
        return DB::transaction(function () use ($graph): array {
            $identity = $graph['identity'];
            DB::table('projects')->where('id', (string) $identity['project_id'])->lockForUpdate()->firstOrFail();

            $key = $this->identityKey((string) $identity['artifact_type'], (string) $identity['artifact_id']);
            $proposal = $this->projectionAttributes($graph, (string) Str::ulid(), now());
            DB::table('canonical_graph_projections')->insertOrIgnore($proposal);
            $existing = $this->findForGraphs([$graph], lockForUpdate: true)[$key];
            $conflict = ! $this->matchesGraph($existing, $graph);
            $inserted = (string) $existing->id === $proposal['id'];
            $inactive = $inserted || in_array($existing->status, ['ready', 'stale', 'failed'], true);
            $activeAttempt = DB::table('canonical_graph_projection_attempts')
                ->where('projection_id', $existing->id)
                ->where(function ($query): void {
                    $query->whereIn('status', ['projecting', 'publishing'])
                        ->orWhere(function ($terminal): void {
                            $terminal->whereIn('status', ['failed', 'superseded', 'abandoned'])
                                ->where('publication_stage', '!=', 'cleaned');
                        });
                })
                ->exists();
            if ($conflict || ! $inactive || $activeAttempt) {
                return ['projection' => $existing, 'claimed' => false, 'conflict' => $conflict, 'attempt_id' => null, 'owner_token' => null];
            }

            $attemptId = (string) Str::ulid();
            $ownerToken = hash('sha256', Str::random(64));
            $candidateVersion = hash('sha256', $existing->graph_version.'|forced|'.$attemptId);
            $now = now();
            $ready = DB::table('canonical_graph_projections')
                ->where('project_id', $existing->project_id)
                ->where('source_scope_type', $existing->source_scope_type)
                ->where('source_scope_id', $existing->source_scope_id)
                ->where('status', 'ready')
                ->lockForUpdate()
                ->first();
            DB::table('canonical_graph_projection_attempts')->insert([
                'id' => $attemptId,
                'projection_id' => $existing->id,
                'candidate_graph_version' => $candidateVersion,
                'owner_token' => $ownerToken,
                'expected_ready_projection_id' => $ready?->id,
                'expected_active_graph_version' => $ready?->active_graph_version,
                'status' => 'projecting',
                'publication_stage' => 'building',
                'node_count' => null,
                'relationship_count' => null,
                'error_code' => null,
                'started_at' => $now,
                'heartbeat_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds(self::FORCED_REBUILD_LEASE_SECONDS),
                'finished_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $projection = clone $existing;
            $projection->logical_graph_version = $existing->graph_version;
            $projection->graph_version = $candidateVersion;
            $projection->attempt_id = $attemptId;
            $projection->owner_token = $ownerToken;

            return ['projection' => $projection, 'claimed' => true, 'conflict' => false, 'attempt_id' => $attemptId, 'owner_token' => $ownerToken];
        });
    }

    public function findForWorker(string $id): ?object
    {
        return DB::table('canonical_graph_projections')->where('id', $id)->first();
    }

    public function forcedRebuildActive(string $projectionId): bool
    {
        return DB::table('canonical_graph_projection_attempts')
            ->where('projection_id', $projectionId)
            ->where(function ($query): void {
                $query->whereIn('status', ['projecting', 'publishing'])
                    ->orWhere(function ($terminal): void {
                        $terminal->whereIn('status', ['failed', 'superseded', 'abandoned'])
                            ->where('publication_stage', '!=', 'cleaned');
                    });
            })
            ->exists();
    }

    public function heartbeatForcedRebuild(string $attemptId, string $ownerToken): bool
    {
        $now = now();

        return DB::table('canonical_graph_projection_attempts')
            ->where('id', $attemptId)
            ->where('owner_token', $ownerToken)
            ->whereIn('status', ['projecting', 'publishing'])
            ->where('lease_expires_at', '>', $now)
            ->update([
                'heartbeat_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds(self::FORCED_REBUILD_LEASE_SECONDS),
                'updated_at' => $now,
            ]) === 1;
    }

    /**
     * Atomically claim one queued projection for a queue worker.
     *
     * False means the projection is missing or another worker/job already
     * owns or completed it. The result comes directly from the conditional
     * update so a fallible read cannot make a successful claim ambiguous.
     */
    public function claimForWorker(string $id): bool
    {
        $now = now();

        return DB::table('canonical_graph_projections')
            ->where('id', $id)
            ->where('status', 'queued')
            ->update([
                'status' => 'projecting',
                'error_code' => null,
                'updated_at' => $now,
            ]) === 1;
    }

    /**
     * Compatibility wrapper for callers that only need the atomic claim bit.
     */
    public function claimForForcedRebuild(string $id, array $graph): bool
    {
        $existing = DB::table('canonical_graph_projections')->where('id', $id)->first();
        if ($existing === null || ! $this->matchesGraph($existing, $graph)) {
            return false;
        }

        $claim = $this->acquireForForcedRebuild($graph);

        return $claim['claimed'] && (string) $claim['projection']->id === $id;
    }

    public function markProjecting(string $id): void
    {
        DB::table('canonical_graph_projections')->where('id', $id)->update([
            'status' => 'projecting',
            'error_code' => null,
            'updated_at' => now(),
        ]);
    }

    public function markReady(string $id, int $nodes, int $relationships): bool
    {
        return $this->publishProjection($id, $nodes, $relationships, static function (): void {});
    }

    public function publishProjection(string $id, int $nodes, int $relationships, Closure $publishMarker): bool
    {
        return DB::transaction(function () use ($id, $nodes, $relationships, $publishMarker): bool {
            $projectId = DB::table('canonical_graph_projections')->where('id', $id)->value('project_id');
            if ($projectId === null) {
                throw new RuntimeException('Canonical graph projection not found.');
            }

            DB::table('projects')->where('id', $projectId)->lockForUpdate()->firstOrFail();

            $candidate = DB::table('canonical_graph_projections')->where('id', $id)->lockForUpdate()->first();
            if ($candidate === null) {
                throw new RuntimeException('Canonical graph projection not found.');
            }
            if ($candidate->status !== 'projecting') {
                return false;
            }

            $publishMarker();
            if (DB::table('canonical_graph_projections')->where('id', $id)->value('status') !== 'projecting') {
                return false;
            }
            DB::table('canonical_graph_projections')
                ->where('project_id', $candidate->project_id)
                ->where('source_scope_type', $candidate->source_scope_type)
                ->where('source_scope_id', $candidate->source_scope_id)
                ->where('status', 'ready')
                ->where('id', '!=', $id)
                ->update(['status' => 'stale', 'updated_at' => now()]);

            DB::table('canonical_graph_projections')->where('id', $id)->update([
                'status' => 'ready',
                'active_graph_version' => $candidate->graph_version,
                'node_count' => $nodes,
                'relationship_count' => $relationships,
                'error_code' => null,
                'projected_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    public function markFailed(string $id, string $code): void
    {
        $boundedCode = $this->boundedFailureCode($code);
        DB::table('canonical_graph_projections')->where('id', $id)->update([
            'status' => 'failed',
            'error_code' => $boundedCode,
            'updated_at' => now(),
        ]);
    }

    /**
     * Mark final queue exhaustion only while no newer delivery owns the row.
     */
    public function markFailedIfQueued(string $id, string $code): bool
    {
        return DB::table('canonical_graph_projections')
            ->where('id', $id)
            ->where('status', 'queued')
            ->update([
                'status' => 'failed',
                'error_code' => $this->boundedFailureCode($code),
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Release an owned projection for Laravel's next attempt without making
     * it claimable by reconciliation. Reconcile only claims final `failed`
     * records; queued records remain owned by the already-dispatched job.
     */
    public function markRetryPending(string $id, string $code): void
    {
        DB::table('canonical_graph_projections')
            ->where('id', $id)
            ->where('status', 'projecting')
            ->update([
                'status' => 'queued',
                'error_code' => $this->boundedFailureCode($code),
                'updated_at' => now(),
            ]);
    }

    /**
     * Persist publication intent, then switch Neo4j and PostgreSQL while the
     * stable project lock excludes every competing publication for the scope.
     * If the marker callback crashes, marker_pending remains committed and a
     * later reclaimer restores Neo4j from PostgreSQL's active pointer.
     */
    public function publishForcedRebuild(
        string $attemptId,
        string $ownerToken,
        int $nodes,
        int $relationships,
        Closure $publishMarker,
    ): ?object {
        $begun = DB::transaction(function () use ($attemptId, $ownerToken, $nodes, $relationships): bool {
            [$attempt, $projection] = $this->lockForcedAttempt($attemptId);
            if (! $this->ownsLiveAttempt($attempt, $ownerToken, ['projecting'])) {
                return false;
            }
            if (! $this->forcedAttemptBaseMatches($attempt, $projection)) {
                $this->finishForcedAttempt($attemptId, 'superseded', 'publication_superseded');

                return false;
            }
            $now = now();
            DB::table('canonical_graph_projection_attempts')->where('id', $attemptId)->update([
                'status' => 'publishing',
                'publication_stage' => 'marker_pending',
                'node_count' => $nodes,
                'relationship_count' => $relationships,
                'heartbeat_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds(self::FORCED_REBUILD_LEASE_SECONDS),
                'updated_at' => $now,
            ]);

            return true;
        });
        if (! $begun) {
            return null;
        }

        return DB::transaction(function () use ($attemptId, $ownerToken, $nodes, $relationships, $publishMarker): ?object {
            [$attempt, $projection] = $this->lockForcedAttempt($attemptId);
            if (! $this->ownsLiveAttempt($attempt, $ownerToken, ['publishing'])
                || $attempt->publication_stage !== 'marker_pending') {
                return null;
            }
            if (! $this->forcedAttemptBaseMatches($attempt, $projection)) {
                $this->finishForcedAttempt($attemptId, 'superseded', 'publication_superseded');

                return null;
            }

            $publishMarker();
            if (! $this->ownsLiveAttempt($attempt, $ownerToken, ['publishing'])) {
                return null;
            }
            $now = now();
            DB::table('canonical_graph_projections')
                ->where('project_id', $projection->project_id)
                ->where('source_scope_type', $projection->source_scope_type)
                ->where('source_scope_id', $projection->source_scope_id)
                ->where('status', 'ready')
                ->where('id', '!=', $projection->id)
                ->update(['status' => 'stale', 'updated_at' => $now]);
            DB::table('canonical_graph_projections')->where('id', $projection->id)->update([
                'status' => 'ready',
                'active_graph_version' => $attempt->candidate_graph_version,
                'node_count' => $nodes,
                'relationship_count' => $relationships,
                'error_code' => null,
                'projected_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('canonical_graph_projection_attempts')->where('id', $attemptId)->update([
                'status' => 'ready',
                'publication_stage' => 'published',
                'error_code' => null,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);

            return DB::table('canonical_graph_projections')->where('id', $projection->id)->firstOrFail();
        });
    }

    /** Restore stale attempts from the PostgreSQL source-of-truth pointer. */
    public function recoverStaleForcedRebuilds(
        array $graph,
        Neo4jClient $client,
        Neo4jCanonicalGraphProjector $projector,
    ): int {
        return DB::transaction(function () use ($graph, $client, $projector): int {
            $identity = $graph['identity'];
            DB::table('projects')->where('id', $identity['project_id'])->lockForUpdate()->firstOrFail();
            $stale = DB::table('canonical_graph_projection_attempts as attempts')
                ->join('canonical_graph_projections as projections', 'projections.id', '=', 'attempts.projection_id')
                ->where('projections.project_id', $identity['project_id'])
                ->where('projections.source_scope_type', $identity['source_scope_type'])
                ->where('projections.source_scope_id', $identity['source_scope_id'])
                ->where(function ($query): void {
                    $query->where(function ($active): void {
                        $active->whereIn('attempts.status', ['projecting', 'publishing'])
                            ->where('attempts.lease_expires_at', '<=', now());
                    })->orWhere(function ($terminal): void {
                        $terminal->whereIn('attempts.status', ['failed', 'superseded', 'abandoned'])
                            ->where('attempts.publication_stage', '!=', 'cleaned');
                    });
                })
                ->select('attempts.*')
                ->lockForUpdate()
                ->get();
            if ($stale->isEmpty()) {
                return 0;
            }
            $scope = DB::table('canonical_graph_projections')
                ->where('project_id', $identity['project_id'])
                ->where('source_scope_type', $identity['source_scope_type'])
                ->where('source_scope_id', $identity['source_scope_id'])
                ->orderByDesc('projected_at')
                ->lockForUpdate()
                ->firstOrFail();
            $ready = DB::table('canonical_graph_projections')
                ->where('project_id', $identity['project_id'])
                ->where('source_scope_type', $identity['source_scope_type'])
                ->where('source_scope_id', $identity['source_scope_id'])
                ->where('status', 'ready')
                ->lockForUpdate()
                ->first();
            $activeVersion = $ready?->active_graph_version;
            $projector->reconcileCurrent($scope, $activeVersion, $client);
            $now = now();
            foreach ($stale as $attempt) {
                if ($activeVersion !== null && hash_equals((string) $activeVersion, (string) $attempt->candidate_graph_version)) {
                    DB::table('canonical_graph_projection_attempts')->where('id', $attempt->id)->update([
                        'status' => 'ready', 'publication_stage' => 'published', 'finished_at' => $now, 'updated_at' => $now,
                    ]);
                } else {
                    $projector->cleanupCandidate($scope, (string) $attempt->candidate_graph_version, $client);
                    $terminalStatus = in_array($attempt->status, ['failed', 'superseded', 'abandoned'], true)
                        ? $attempt->status
                        : 'abandoned';
                    DB::table('canonical_graph_projection_attempts')->where('id', $attempt->id)->update([
                        'status' => $terminalStatus, 'publication_stage' => 'cleaned',
                        'error_code' => $attempt->error_code ?? 'lease_expired',
                        'finished_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }

            return $stale->count();
        });
    }

    /** Finalize only the forced attempt still owned by this caller. */
    public function markForcedRebuildFailed(string $attemptId, string $ownerToken, string $code): bool
    {
        return DB::transaction(function () use ($attemptId, $ownerToken, $code): bool {
            [$attempt] = $this->lockForcedAttempt($attemptId);
            if (! $this->ownsLiveAttempt($attempt, $ownerToken, ['projecting', 'publishing'])) {
                return false;
            }
            $this->finishForcedAttempt($attemptId, 'failed', $code);

            return true;
        });
    }

    /**
     * Reconcile and clean a normally-failed/superseded candidate. Owner loss
     * is a hard abort: a caller can never clean another attempt's candidate.
     */
    public function cleanupForcedRebuild(
        string $attemptId,
        string $ownerToken,
        Neo4jClient $client,
        Neo4jCanonicalGraphProjector $projector,
    ): bool {
        return DB::transaction(function () use ($attemptId, $ownerToken, $client, $projector): bool {
            [$attempt, $projection] = $this->lockForcedAttempt($attemptId);
            if (! $this->ownsLiveAttempt($attempt, $ownerToken, ['failed', 'superseded', 'abandoned'])) {
                return false;
            }
            $ready = DB::table('canonical_graph_projections')
                ->where('project_id', $projection->project_id)
                ->where('source_scope_type', $projection->source_scope_type)
                ->where('source_scope_id', $projection->source_scope_id)
                ->where('status', 'ready')
                ->lockForUpdate()
                ->first();
            $activeVersion = $ready?->active_graph_version;
            $projector->reconcileCurrent($projection, $activeVersion, $client);
            if ($activeVersion === null || ! hash_equals((string) $activeVersion, (string) $attempt->candidate_graph_version)) {
                $projector->cleanupCandidate($projection, (string) $attempt->candidate_graph_version, $client);
            }
            DB::table('canonical_graph_projection_attempts')->where('id', $attemptId)
                ->whereIn('status', ['failed', 'superseded', 'abandoned'])
                ->update(['publication_stage' => 'cleaned', 'updated_at' => now()]);

            return true;
        });
    }

    public function readyForScope(string $projectId, string $scopeType, string $scopeId): ?object
    {
        return DB::table('canonical_graph_projections')
            ->where('project_id', $projectId)
            ->where('source_scope_type', $scopeType)
            ->where('source_scope_id', $scopeId)
            ->where('status', 'ready')
            ->orderByDesc('projected_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return array{0: object|null, 1: object|null} */
    private function lockForcedAttempt(string $attemptId): array
    {
        $candidate = DB::table('canonical_graph_projection_attempts')->where('id', $attemptId)->first();
        if ($candidate === null) {
            return [null, null];
        }
        $projection = DB::table('canonical_graph_projections')->where('id', $candidate->projection_id)->first();
        if ($projection === null) {
            return [null, null];
        }
        DB::table('projects')->where('id', $projection->project_id)->lockForUpdate()->firstOrFail();
        $attempt = DB::table('canonical_graph_projection_attempts')->where('id', $attemptId)->lockForUpdate()->first();
        $projection = DB::table('canonical_graph_projections')->where('id', $candidate->projection_id)->lockForUpdate()->first();

        return [$attempt, $projection];
    }

    /** @param list<string> $statuses */
    private function ownsLiveAttempt(?object $attempt, string $ownerToken, array $statuses): bool
    {
        return $attempt !== null
            && hash_equals((string) $attempt->owner_token, $ownerToken)
            && in_array($attempt->status, $statuses, true)
            && $attempt->lease_expires_at !== null
            && now()->lt($attempt->lease_expires_at);
    }

    private function forcedAttemptBaseMatches(object $attempt, object $projection): bool
    {
        $ready = DB::table('canonical_graph_projections')
            ->where('project_id', $projection->project_id)
            ->where('source_scope_type', $projection->source_scope_type)
            ->where('source_scope_id', $projection->source_scope_id)
            ->where('status', 'ready')
            ->lockForUpdate()
            ->first();

        return ($ready?->id === $attempt->expected_ready_projection_id)
            && ($ready?->active_graph_version === $attempt->expected_active_graph_version);
    }

    private function finishForcedAttempt(string $attemptId, string $status, string $code): void
    {
        $now = now();
        DB::table('canonical_graph_projection_attempts')->where('id', $attemptId)->update([
            'status' => $status,
            'error_code' => $this->boundedFailureCode($code),
            'finished_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function identityKey(string $artifactType, string $artifactId): string
    {
        return $artifactType."\0".$artifactId;
    }

    public function matchesGraph(object $projection, array $graph): bool
    {
        $identity = $graph['identity'];
        $expectedVersion = hash(
            'sha256',
            $identity['artifact_type'].'|'.$identity['artifact_id'].'|'.$identity['checksum'],
        );

        return (string) $projection->project_id === (string) $identity['project_id']
            && (string) $projection->source_scope_type === (string) $identity['source_scope_type']
            && (string) $projection->source_scope_id === (string) $identity['source_scope_id']
            && hash_equals((string) $projection->checksum, (string) $identity['checksum'])
            && hash_equals((string) ($projection->logical_graph_version ?? $projection->graph_version), $expectedVersion);
    }

    private function projectionAttributes(array $graph, string $id, mixed $now): array
    {
        $identity = $graph['identity'];
        $artifactType = (string) $identity['artifact_type'];
        $artifactId = (string) $identity['artifact_id'];
        $checksum = (string) $identity['checksum'];

        return [
            'id' => $id,
            'project_id' => $identity['project_id'],
            'source_scope_type' => $identity['source_scope_type'],
            'source_scope_id' => $identity['source_scope_id'],
            'artifact_type' => $artifactType,
            'artifact_id' => $artifactId,
            'graph_version' => hash('sha256', $artifactType.'|'.$artifactId.'|'.$checksum),
            'active_graph_version' => null,
            'checksum' => $checksum,
            'head_commit' => $graph['contract']['source']['head_commit'] ?? null,
            'quality' => $graph['contract']['extractor']['quality'],
            'status' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function boundedFailureCode(string $code): string
    {
        return preg_match('/\A[a-z0-9_]{1,100}\z/', $code) === 1 ? $code : 'projection_failed';
    }
}
