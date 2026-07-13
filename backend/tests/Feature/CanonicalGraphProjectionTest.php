<?php

use App\Services\Graph\CanonicalGraphProjectionService;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DevBoardSeeder::class);
});

it('keeps the previous projection ready until its replacement is ready', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $first = $service->queue(canonicalProjectionGraph($projectId, 'artifact-1', str_repeat('a', 64)));
    $service->markProjecting($first->id);
    $service->markReady($first->id, 10, 5);
    $second = $service->queue(canonicalProjectionGraph($projectId, 'artifact-2', str_repeat('b', 64)));
    $service->markProjecting($second->id);
    expect($service->readyForScope($projectId, 'workspace_binding', 'binding-1')->id)->toBe($first->id);
    $service->markReady($second->id, 12, 7);
    expect($service->readyForScope($projectId, 'workspace_binding', 'binding-1')->id)->toBe($second->id)
        ->and(DB::table('canonical_graph_projections')->where('id', $first->id)->value('status'))->toBe('stale')
        ->and(DB::table('canonical_graph_projections')
            ->where('project_id', $projectId)
            ->where('source_scope_type', 'workspace_binding')
            ->where('source_scope_id', 'binding-1')
            ->where('status', 'ready')
            ->count())->toBe(1);
});

it('locks the stable project row before locking the projection candidate', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $candidate = $service->queue(canonicalProjectionGraph($projectId, 'artifact-1', str_repeat('a', 64)));
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $service->markReady($candidate->id, 10, 5);

    $projectLock = array_find_key($queries, fn (string $sql): bool => str_contains($sql, 'from "projects"'));
    $candidateLock = array_find_key($queries, fn (string $sql): bool => str_starts_with($sql, 'select *') && str_contains($sql, 'from "canonical_graph_projections"'));
    expect($projectLock)->not->toBeNull()
        ->and($candidateLock)->not->toBeNull()
        ->and($projectLock)->toBeLessThan($candidateLock);
});

it('queues an artifact identity idempotently', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $graph = canonicalProjectionGraph($projectId, 'artifact-1', str_repeat('a', 64));
    $first = $service->queue($graph);
    $second = $service->queue($graph);
    expect($second->id)->toBe($first->id)
        ->and(DB::table('canonical_graph_projections')->count())->toBe(1);
});

it('stores only bounded failure codes and rejects raw exception text', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $raw = $service->queue(canonicalProjectionGraph($projectId, 'artifact-1', str_repeat('a', 64)));
    $bounded = $service->queue(canonicalProjectionGraph($projectId, 'artifact-2', str_repeat('b', 64)));
    $service->markFailed($raw->id, 'SQLSTATE[HY000]: secret connection text');
    $service->markFailed($bounded->id, 'neo4j_timeout');
    expect(DB::table('canonical_graph_projections')->where('id', $raw->id)->value('error_code'))->toBe('projection_failed')
        ->and(DB::table('canonical_graph_projections')->where('id', $bounded->id)->value('error_code'))->toBe('neo4j_timeout');
});

it('claims a queued worker projection exactly once with an atomic state transition', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $projection = $service->queue(canonicalProjectionGraph($projectId, 'artifact-claim', str_repeat('c', 64)));

    $firstClaimed = $service->claimForWorker($projection->id);
    $secondClaimed = $service->claimForWorker($projection->id);

    expect($firstClaimed)->toBeTrue()
        ->and($secondClaimed)->toBeFalse()
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('projecting');
});

it('returns the atomic claim result without a fallible post claim read', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $projection = $service->queue(canonicalProjectionGraph($projectId, 'artifact-no-post-read', str_repeat('d', 64)));
    $rejectPostClaimRead = true;
    $failure = null;
    $claimed = null;

    DB::listen(function ($query) use (&$rejectPostClaimRead): void {
        $sql = strtolower($query->sql);
        if ($rejectPostClaimRead && str_starts_with($sql, 'select') && str_contains($sql, 'canonical_graph_projections')) {
            throw new RuntimeException('simulated post-CAS read failure');
        }
    });

    try {
        $claimed = $service->claimForWorker($projection->id);
    } catch (Throwable $exception) {
        $failure = $exception;
    } finally {
        $rejectPostClaimRead = false;
    }

    expect($failure)->toBeNull()
        ->and($claimed)->toBeTrue()
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('projecting');
});

it('ignores final failure unless the projection is still queued', function (string $status) {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $projection = $service->queue(canonicalProjectionGraph($projectId, 'artifact-final-'.$status, str_repeat('1', 64)));
    DB::table('canonical_graph_projections')->where('id', $projection->id)->update([
        'status' => $status,
        'error_code' => null,
    ]);

    $changed = $service->markFailedIfQueued($projection->id, 'neo4j_unavailable');

    expect($changed)->toBeFalse()
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe($status)
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('error_code'))->toBeNull();
})->with(['projecting', 'ready', 'stale', 'failed']);

it('refuses a stale ready transition without staling or publishing anything', function (string $candidateStatus) {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $service = app(CanonicalGraphProjectionService::class);
    $current = $service->queue(canonicalProjectionGraph($projectId, 'artifact-current', str_repeat('e', 64)));
    $service->markProjecting($current->id);
    expect($service->markReady($current->id, 10, 5))->toBeTrue();

    $candidate = $service->queue(canonicalProjectionGraph($projectId, 'artifact-stale-worker', str_repeat('f', 64)));
    DB::table('canonical_graph_projections')->where('id', $candidate->id)->update([
        'status' => $candidateStatus,
        'node_count' => null,
        'relationship_count' => null,
        'projected_at' => null,
    ]);
    $beforeCurrent = DB::table('canonical_graph_projections')->where('id', $current->id)->first();
    $beforeCandidate = DB::table('canonical_graph_projections')->where('id', $candidate->id)->first();

    $published = $service->markReady($candidate->id, 99, 88);

    $afterCurrent = DB::table('canonical_graph_projections')->where('id', $current->id)->first();
    $afterCandidate = DB::table('canonical_graph_projections')->where('id', $candidate->id)->first();
    expect($published)->toBeFalse()
        ->and($afterCurrent->status)->toBe('ready')
        ->and($afterCurrent->updated_at)->toBe($beforeCurrent->updated_at)
        ->and($afterCandidate->status)->toBe($candidateStatus)
        ->and($afterCandidate->node_count)->toBe($beforeCandidate->node_count)
        ->and($afterCandidate->relationship_count)->toBe($beforeCandidate->relationship_count)
        ->and($afterCandidate->projected_at)->toBe($beforeCandidate->projected_at)
        ->and($afterCandidate->updated_at)->toBe($beforeCandidate->updated_at);
})->with(['queued', 'failed', 'ready']);

function canonicalProjectionGraph(string $projectId, string $artifactId, string $checksum): array
{
    return [
        'identity' => [
            'project_id' => $projectId,
            'source_scope_type' => 'workspace_binding',
            'source_scope_id' => 'binding-1',
            'artifact_type' => 'hades_agent_artifact',
            'artifact_id' => $artifactId,
            'checksum' => $checksum,
        ],
        'contract' => [
            'extractor' => ['quality' => 'full'],
            'source' => ['head_commit' => 'abc123'],
        ],
    ];
}
