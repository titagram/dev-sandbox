<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('returns a versioned shared memory snapshot for a linked workspace', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $memoryId = (string) Str::ulid();
    $now = now();

    DB::table('project_memory_entries')->insert([
        'id' => $memoryId,
        'project_id' => $agent['project_id'],
        'repository_id' => null,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => null,
        'agent_key' => 'human',
        'source' => 'manual',
        'kind' => 'decision',
        'completeness' => 'complete',
        'summary' => 'Use the backend as authoritative shared memory.',
        'payload' => json_encode(['decision' => 'backend-authoritative'], JSON_THROW_ON_ERROR),
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $response = $this->getJson('/api/hades/v1/memory/snapshot?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('project_id', $agent['project_id'])
        ->assertJsonPath('workspace_binding_id', $binding['workspace_binding_id'])
        ->assertJsonPath('items.0.id', $memoryId)
        ->assertJsonPath('items.0.summary', 'Use the backend as authoritative shared memory.')
        ->assertJsonPath('items.0.payload.decision', 'backend-authoritative');

    expect($response->json('version'))->toBeString()->not->toBe('unknown');
    expect($response->json('etag'))->toBe($response->json('version'));
});

it('preserves dashboard user inserted memory in the Hades snapshot', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $memoryId = (string) Str::ulid();
    $now = now();

    DB::table('project_memory_entries')->insert([
        'id' => $memoryId,
        'project_id' => $agent['project_id'],
        'repository_id' => null,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => null,
        'agent_key' => null,
        'source' => 'user_inserted',
        'kind' => 'decision',
        'completeness' => 'complete',
        'summary' => 'Dashboard user inserted memory remains visible to Hades.',
        'payload' => json_encode(['source_label' => 'inserito dall utente'], JSON_THROW_ON_ERROR),
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->getJson('/api/hades/v1/memory/snapshot?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('items.0.id', $memoryId)
        ->assertJsonPath('items.0.source', 'user_inserted')
        ->assertJsonPath('items.0.payload.source_label', 'inserito dall utente');
});

it('creates idempotent memory proposals and auto-accepts low risk creates', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);

    $payload = [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'local_proposal_id' => 'local-proposal-1',
        'action' => 'create',
        'intent' => 'memory_write',
        'summary' => 'Document that Hades should sync memory through Laravel.',
        'provenance' => [
            'source' => 'hades_backend_provider',
            'local_run_id' => 'run-123',
        ],
    ];

    $first = $this->postJson('/api/hades/v1/memory/proposals', $payload, hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('proposal.local_proposal_id', 'local-proposal-1')
        ->assertJsonPath('proposal.status', 'accepted')
        ->assertJsonPath('proposal.action', 'create');

    $proposalId = $first->json('proposal.id');
    $memoryEntryId = $first->json('proposal.memory_entry_id');

    expect($proposalId)->toBeString();
    expect($memoryEntryId)->toBeString();
    expect(DB::table('hades_memory_proposals')->where('id', $proposalId)->value('status'))->toBe('accepted');
    expect(DB::table('project_memory_entries')->where('id', $memoryEntryId)->value('summary'))->toBe($payload['summary']);

    $this->postJson('/api/hades/v1/memory/proposals', array_merge($payload, [
        'summary' => 'Changed after retry should not duplicate.',
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('proposal.id', $proposalId)
        ->assertJsonPath('proposal.memory_entry_id', $memoryEntryId)
        ->assertJsonPath('proposal.status', 'accepted');

    expect(DB::table('hades_memory_proposals')->where('workspace_binding_id', $binding['workspace_binding_id'])->count())->toBe(1);
    expect(DB::table('project_memory_entries')->where('project_id', $agent['project_id'])->where('source', 'hades_agent')->count())->toBe(1);
    expect(DB::table('hades_search_documents')->where('source_table', 'project_memory_entries')->where('source_id', $memoryEntryId)->whereNull('workspace_binding_id')->where('body', 'like', '%Laravel%')->exists())->toBeTrue();
});

it('keeps note backfill candidate proposals pending for manual review', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);

    $payload = [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'local_proposal_id' => 'local-note-backfill-1',
        'action' => 'create',
        'intent' => 'note_backfill_candidate',
        'summary' => 'Controller.php handles 3 taxonomy routes.',
        'provenance' => [
            'source' => 'hades_note_quality',
            'candidate_fact_fingerprint' => hash('sha256', 'route-handler-group'),
            'candidate_fact' => [
                'kind' => 'route_handler_group',
                'review_status' => 'candidate',
            ],
        ],
    ];

    $first = $this->postJson('/api/hades/v1/memory/proposals', $payload, hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('proposal.local_proposal_id', 'local-note-backfill-1')
        ->assertJsonPath('proposal.status', 'pending')
        ->assertJsonPath('proposal.reason_code', 'manual_review_required')
        ->assertJsonPath('proposal.memory_entry_id', null);

    $proposalId = $first->json('proposal.id');

    expect(DB::table('hades_memory_proposals')->where('id', $proposalId)->value('status'))->toBe('pending');
    expect(DB::table('hades_memory_proposals')->where('id', $proposalId)->value('memory_entry_id'))->toBeNull();
    expect(DB::table('project_memory_entries')->where('project_id', $agent['project_id'])->where('source', 'hades_agent')->count())->toBe(0);

    $this->postJson('/api/hades/v1/memory/proposals', array_merge($payload, [
        'summary' => 'Changed after retry should not duplicate.',
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('proposal.id', $proposalId)
        ->assertJsonPath('proposal.status', 'pending')
        ->assertJsonPath('proposal.memory_entry_id', null);

    expect(DB::table('hades_memory_proposals')->where('workspace_binding_id', $binding['workspace_binding_id'])->count())->toBe(1);
    expect(DB::table('project_memory_entries')->where('project_id', $agent['project_id'])->where('source', 'hades_agent')->count())->toBe(0);
});

it('searches shared memory with domains, scores, and freshness metadata', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $now = now();

    DB::table('project_memory_entries')->insert([
        [
            'id' => (string) Str::ulid(),
            'project_id' => $agent['project_id'],
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => null,
            'source' => 'manual',
            'kind' => 'decision',
            'completeness' => 'complete',
            'summary' => 'Backend memory search should rank Hades project routes.',
            'payload' => json_encode(['note' => 'route prefix /api/hades/v1'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => (string) Str::ulid(),
            'project_id' => $agent['project_id'],
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => 'local-agent',
            'source' => 'hades_agent',
            'kind' => 'agent_note',
            'completeness' => 'complete',
            'summary' => 'Agent note mentions Hades project routes but is not logbook.',
            'payload' => json_encode(['note' => 'agent only'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now->copy()->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $response = $this->getJson('/api/hades/v1/memory/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'Hades project routes',
        'domain' => 'logbook',
        'limit' => 5,
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('domain', 'logbook')
        ->assertJsonPath('workspace_binding_id', $binding['workspace_binding_id'])
        ->assertJsonPath('count', 1)
        ->assertJsonPath('items.0.domain', 'logbook')
        ->assertJsonPath('items.0.summary', 'Backend memory search should rank Hades project routes.')
        ->assertJsonPath('freshness.index_status', 'live_query')
        ->assertJsonPath('freshness.workspace_head_commit', str_repeat('f', 40));

    expect($response->json('items.0.score'))->toBeGreaterThan(0);
    expect($response->json('version'))->toStartWith('search_');
});

it('ranks concise multi token memory matches above newer noisy matches', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $now = now();
    $preciseId = (string) Str::ulid();
    $noisyId = (string) Str::ulid();

    DB::table('project_memory_entries')->insert([
        [
            'id' => $noisyId,
            'project_id' => $agent['project_id'],
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => null,
            'source' => 'manual',
            'kind' => 'decision',
            'completeness' => 'complete',
            'summary' => 'Checkout operational note covering many unrelated topics: deployment windows, token rotation, frontend retry copy, rate limits, mismatch alerts, queue retries, and general monitoring.',
            'payload' => json_encode(['note' => 'noisy but newer'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => $preciseId,
            'project_id' => $agent['project_id'],
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => null,
            'source' => 'manual',
            'kind' => 'decision',
            'completeness' => 'complete',
            'summary' => 'Checkout token mismatch caused 419 responses.',
            'payload' => json_encode(['note' => 'precise but older'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now->copy()->subDay(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $response = $this->getJson('/api/hades/v1/memory/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'checkout mismatch token',
        'domain' => 'logbook',
        'limit' => 2,
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('count', 2)
        ->assertJsonPath('items.0.id', $preciseId)
        ->assertJsonPath('items.1.id', $noisyId);

    expect($response->json('items.0.score'))->toBeGreaterThan($response->json('items.1.score'));
});

it('filters Hades memory search by structured fields', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $now = now();
    $matchingId = (string) Str::ulid();

    DB::table('project_memory_entries')->insert([
        [
            'id' => $matchingId,
            'project_id' => $agent['project_id'],
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => null,
            'source' => 'hades_diagnosis_report',
            'kind' => 'resolved_bug',
            'completeness' => 'complete',
            'summary' => 'Resolved bug: active() on null in OrderController.',
            'payload' => json_encode([
                'schema' => 'hades.resolved_bug.v1',
                'affected_symbols' => ['App\\Http\\Controllers\\OrderController@show'],
                'path' => 'app/Http/Controllers/OrderController.php',
                'root_cause' => 'OrderController dereferences a missing customer relation.',
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => (string) Str::ulid(),
            'project_id' => $agent['project_id'],
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => null,
            'source' => 'hades_diagnosis_report',
            'kind' => 'resolved_bug',
            'completeness' => 'complete',
            'summary' => 'Resolved bug: checkout timeout in PaymentController.',
            'payload' => json_encode([
                'schema' => 'hades.resolved_bug.v1',
                'affected_symbols' => ['App\\Http\\Controllers\\PaymentController@store'],
                'path' => 'app/Http/Controllers/PaymentController.php',
                'root_cause' => 'Payment gateway timeout was not retried.',
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $now->copy()->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $response = $this->getJson('/api/hades/v1/memory/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'resolved bug',
        'domain' => 'project_memory',
        'kind' => 'resolved_bug',
        'schema' => 'hades.resolved_bug.v1',
        'source' => 'hades_diagnosis_report',
        'symbol' => 'OrderController@show',
        'path' => 'OrderController.php',
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('filters.kind', 'resolved_bug')
        ->assertJsonPath('filters.schema', 'hades.resolved_bug.v1')
        ->assertJsonPath('filters.symbol', 'OrderController@show')
        ->assertJsonPath('items.0.id', $matchingId)
        ->assertJsonPath('items.0.kind', 'resolved_bug');

    expect($response->json('items.0.match_fields'))->toContain('kind')
        ->and($response->json('items.0.match_fields'))->toContain('schema')
        ->and($response->json('items.0.match_fields'))->toContain('source')
        ->and($response->json('items.0.match_fields'))->toContain('symbol')
        ->and($response->json('items.0.match_fields'))->toContain('path');
});

it('searches current wiki revisions through the Hades memory search endpoint', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $pageId = (string) Str::ulid();
    $revisionId = (string) Str::ulid();
    $now = now();

    DB::table('wiki_pages')->insert([
        'id' => $pageId,
        'project_id' => $agent['project_id'],
        'repository_id' => null,
        'slug' => 'architecture/hades-memory',
        'title' => 'Hades Memory Architecture',
        'page_type' => 'architecture',
        'current_revision_id' => $revisionId,
        'source_status' => 'verified_from_code',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('wiki_revisions')->insert([
        'id' => $revisionId,
        'wiki_page_id' => $pageId,
        'author_user_id' => null,
        'author_device_id' => null,
        'producer' => 'test',
        'source_type' => 'controlled_agent_tool',
        'source_status' => 'verified_from_code',
        'content_markdown' => 'The taxonomy route is documented in the Hades memory architecture wiki.',
        'evidence_refs' => json_encode([['path' => 'routes/api.php']], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    $this->getJson('/api/hades/v1/memory/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'taxonomy route',
        'domain' => 'wiki',
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('items.0.domain', 'wiki')
        ->assertJsonPath('items.0.id', $revisionId)
        ->assertJsonPath('items.0.page_slug', 'architecture/hades-memory')
        ->assertJsonPath('items.0.evidence_count', 1)
        ->assertJsonPath('items.0.source_status', 'verified_from_code');
});

it('uses materialized search documents for Hades memory search candidates', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $memoryId = (string) Str::ulid();
    $now = now();

    DB::table('project_memory_entries')->insert([
        'id' => $memoryId,
        'project_id' => $agent['project_id'],
        'repository_id' => null,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => null,
        'agent_key' => null,
        'source' => 'manual',
        'kind' => 'decision',
        'completeness' => 'complete',
        'summary' => 'Plain project memory without the indexed-only token.',
        'payload' => json_encode(['note' => 'plain memory payload'], JSON_THROW_ON_ERROR),
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('hades_search_documents')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => null,
        'domain' => 'logbook',
        'kind' => 'decision',
        'source_table' => 'project_memory_entries',
        'source_id' => $memoryId,
        'source_schema' => null,
        'title' => 'Materialized memory candidate',
        'body' => 'materialized-memory-only-needle',
        'metadata' => json_encode(['source' => 'test'], JSON_THROW_ON_ERROR),
        'checksum' => str_repeat('1', 64),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $response = $this->getJson('/api/hades/v1/memory/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'materialized-memory-only-needle',
        'domain' => 'logbook',
        'limit' => 5,
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('items.0.id', $memoryId)
        ->assertJsonPath('items.0.domain', 'logbook');

    expect($response->json('items.0.score'))->toBeGreaterThan(0);
});

it('uses materialized search documents for Hades wiki search candidates', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $pageId = (string) Str::ulid();
    $revisionId = (string) Str::ulid();
    $now = now();

    DB::table('wiki_pages')->insert([
        'id' => $pageId,
        'project_id' => $agent['project_id'],
        'repository_id' => null,
        'slug' => 'architecture/materialized-search',
        'title' => 'Materialized Search Architecture',
        'page_type' => 'architecture',
        'current_revision_id' => $revisionId,
        'source_status' => 'verified_from_code',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('wiki_revisions')->insert([
        'id' => $revisionId,
        'wiki_page_id' => $pageId,
        'author_user_id' => null,
        'author_device_id' => null,
        'producer' => 'test',
        'source_type' => 'controlled_agent_tool',
        'source_status' => 'verified_from_code',
        'content_markdown' => 'This wiki body intentionally omits the indexed-only token.',
        'evidence_refs' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    DB::table('hades_search_documents')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => null,
        'domain' => 'wiki',
        'kind' => 'wiki',
        'source_table' => 'wiki_revisions',
        'source_id' => $revisionId,
        'source_schema' => 'devboard.wiki_revision.v1',
        'title' => 'Materialized wiki candidate',
        'body' => 'materialized-wiki-only-needle',
        'metadata' => json_encode(['slug' => 'architecture/materialized-search'], JSON_THROW_ON_ERROR),
        'checksum' => str_repeat('2', 64),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $response = $this->getJson('/api/hades/v1/memory/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'materialized-wiki-only-needle',
        'domain' => 'wiki',
        'limit' => 5,
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('items.0.id', $revisionId)
        ->assertJsonPath('items.0.domain', 'wiki')
        ->assertJsonPath('items.0.page_slug', 'architecture/materialized-search');

    expect($response->json('items.0.score'))->toBeGreaterThan(0);
});

it('backfills materialized search documents for legacy Hades records', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $projectId = $agent['project_id'];
    $bindingId = $binding['workspace_binding_id'];
    $now = now();
    $memoryId = (string) Str::ulid();
    $pageId = (string) Str::ulid();
    $revisionId = (string) Str::ulid();
    $evidenceId = (string) Str::ulid();
    $sliceId = (string) Str::ulid();
    $packId = (string) Str::ulid();

    DB::table('project_memory_entries')->insert([
        'id' => $memoryId,
        'project_id' => $projectId,
        'repository_id' => null,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => null,
        'agent_key' => null,
        'source' => 'manual',
        'kind' => 'decision',
        'completeness' => 'complete',
        'summary' => 'Legacy memory should be backfilled into Hades search documents.',
        'payload' => json_encode(['schema' => 'legacy.memory.v1', 'note' => 'legacy-memory-backfill'], JSON_THROW_ON_ERROR),
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('wiki_pages')->insert([
        'id' => $pageId,
        'project_id' => $projectId,
        'repository_id' => null,
        'slug' => 'legacy/backfill',
        'title' => 'Legacy Backfill Wiki',
        'page_type' => 'architecture',
        'current_revision_id' => $revisionId,
        'source_status' => 'verified_from_code',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('wiki_revisions')->insert([
        'id' => $revisionId,
        'wiki_page_id' => $pageId,
        'author_user_id' => null,
        'author_device_id' => null,
        'producer' => 'test',
        'source_type' => 'controlled_agent_tool',
        'source_status' => 'verified_from_code',
        'content_markdown' => 'Legacy wiki should be backfilled into Hades search documents.',
        'evidence_refs' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]);

    DB::table('hades_bug_evidence_items')->insert([
        'id' => $evidenceId,
        'project_id' => $projectId,
        'bug_report_id' => null,
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $bindingId,
        'kind' => 'stack_trace',
        'summary' => 'Legacy bug evidence backfill stack trace.',
        'payload' => json_encode(['schema' => 'hades.stack_trace.v1', 'frame' => 'LegacyController@show'], JSON_THROW_ON_ERROR),
        'source' => 'laravel.log',
        'sha256' => str_repeat('d', 64),
        'redactions' => 0,
        'retention_class' => 'stack_trace',
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('hades_source_slices')->insert([
        'id' => $sliceId,
        'project_id' => $projectId,
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $bindingId,
        'job_id' => null,
        'path' => 'app/LegacyController.php',
        'start_line' => 10,
        'end_line' => 12,
        'language' => 'php',
        'symbol' => 'LegacyController@show',
        'head_commit' => str_repeat('f', 40),
        'sha256' => str_repeat('e', 64),
        'content_redacted' => 'legacy source slice backfill content',
        'redactions' => 0,
        'truncated' => false,
        'retention_class' => 'source_slice',
        'policy' => 'manual_review',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('hades_evidence_packs')->insert([
        'id' => $packId,
        'project_id' => $projectId,
        'bug_report_id' => null,
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $bindingId,
        'title' => 'Legacy evidence pack backfill',
        'summary' => 'Legacy evidence pack should be indexed by the backfill command.',
        'evidence_refs' => json_encode([['type' => 'bug_evidence', 'id' => $evidenceId]], JSON_THROW_ON_ERROR),
        'graph_refs' => json_encode([], JSON_THROW_ON_ERROR),
        'source_slice_ids' => json_encode([$sliceId], JSON_THROW_ON_ERROR),
        'payload' => json_encode(['note' => 'legacy-pack-backfill'], JSON_THROW_ON_ERROR),
        'sha256' => str_repeat('f', 64),
        'redactions' => 0,
        'retention_class' => 'diagnosis_evidence',
        'head_commit' => str_repeat('f', 40),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(DB::table('hades_search_documents')->count())->toBe(0);

    $exitCode = Artisan::call('hades:search-documents-reindex', [
        '--project' => $projectId,
        '--json' => true,
    ]);
    $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($output['indexed'])->toBeGreaterThanOrEqual(5)
        ->and(DB::table('hades_search_documents')->where('source_table', 'project_memory_entries')->where('source_id', $memoryId)->whereNull('workspace_binding_id')->exists())->toBeTrue()
        ->and(DB::table('hades_search_documents')->where('source_table', 'wiki_revisions')->where('source_id', $revisionId)->exists())->toBeTrue()
        ->and(DB::table('hades_search_documents')->where('source_table', 'hades_bug_evidence_items')->where('source_id', $evidenceId)->exists())->toBeTrue()
        ->and(DB::table('hades_search_documents')->where('source_table', 'hades_source_slices')->where('source_id', $sliceId)->exists())->toBeTrue()
        ->and(DB::table('hades_search_documents')->where('source_table', 'hades_evidence_packs')->where('source_id', $packId)->exists())->toBeTrue();
});

it('quarantines raw source chunks from memory search unless explicitly requested', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $chunkId = (string) Str::ulid();
    $now = now();

    DB::table('project_memory_entries')->insert([
        'id' => $chunkId,
        'project_id' => $agent['project_id'],
        'repository_id' => null,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => null,
        'agent_key' => null,
        'source' => 'backend_wiki_import',
        'kind' => 'file_chunk',
        'completeness' => 'complete',
        'summary' => 'Taxonomy route raw chunk should stay out of automatic memory.',
        'payload' => json_encode([
            'schema' => 'hades.backend_wiki.file_chunk.v1',
            'path' => 'graphify-sidecar/carnovali-facts.md',
            'chunk_index' => 245,
            'chunk_count' => 267,
        ], JSON_THROW_ON_ERROR),
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->getJson('/api/hades/v1/memory/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'taxonomy route raw chunk',
        'domain' => 'source_chunks',
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('count', 0)
        ->assertJsonPath('raw_chunks_omitted', 1);

    $this->getJson('/api/hades/v1/memory/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'taxonomy route raw chunk',
        'domain' => 'source_chunks',
        'include_raw_chunks' => true,
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('raw_chunks_omitted', 0)
        ->assertJsonPath('items.0.id', $chunkId)
        ->assertJsonPath('items.0.domain', 'source_chunks')
        ->assertJsonPath('items.0.raw_chunk', true)
        ->assertJsonPath('items.0.schema', 'hades.backend_wiki.file_chunk.v1')
        ->assertJsonPath('items.0.source', 'graphify-sidecar/carnovali-facts.md');
});

it('searches project index artifacts through the Hades memory search endpoint', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $artifactId = (string) Str::ulid();
    $now = now();

    DB::table('hades_agent_artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $agent['project_id'],
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'job_id' => null,
        'schema' => 'hades.git_tree.v1',
        'artifact' => json_encode([
            'schema' => 'hades.git_tree.v1',
            'root' => 'hades',
            'project_index' => [
                'schema' => 'hades.project_index.v1',
                'routes' => [[
                    'method' => 'GET',
                    'uri' => '/hades/memory',
                    'handler' => 'MemoryController@index',
                    'name' => 'hades.memory',
                    'path' => 'routes/api.php',
                ]],
                'dependency_manifests' => [[
                    'manager' => 'composer',
                    'path' => 'composer.json',
                    'packages' => ['laravel/framework'],
                ]],
                'database' => [
                    'migrations' => ['database/migrations/2026_07_06_000000_create_hades_memory.php'],
                ],
            ],
            'files' => [],
            'omitted' => [],
            'raw_source_included' => false,
        ], JSON_THROW_ON_ERROR),
        'sha256' => str_repeat('3', 64),
        'truncated' => false,
        'redactions' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $response = $this->getJson('/api/hades/v1/memory/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'hades memory route laravel',
        'domain' => 'artifacts',
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('items.0.id', $artifactId)
        ->assertJsonPath('items.0.domain', 'artifacts')
        ->assertJsonPath('items.0.schema', 'hades.git_tree.v1')
        ->assertJsonPath('items.0.source', 'hades.git_tree.v1');

    expect($response->json('items.0.summary'))->toContain('GET /hades/memory');
    expect($response->json('items.0.payload_excerpt'))->toContain('laravel/framework');
});

it('searches PHP graph artifacts through the Hades memory search endpoint', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $artifactId = (string) Str::ulid();
    $now = now();

    DB::table('hades_agent_artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $agent['project_id'],
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'job_id' => null,
        'schema' => 'hades.php_graph.v1',
        'artifact' => json_encode([
            'schema' => 'hades.php_graph.v1',
            'head_commit' => str_repeat('f', 40),
            'routes' => [[
                'method' => 'GET',
                'uri' => '/orders/{order}',
                'handler' => 'OrderController@show',
                'name' => 'orders.show',
                'path' => 'routes/web.php',
            ]],
            'symbols' => [[
                'kind' => 'class',
                'name' => 'App\Http\Controllers\OrderController',
                'role' => 'controller',
                'path' => 'app/Http/Controllers/OrderController.php',
            ], [
                'kind' => 'method',
                'name' => 'OrderController@show',
                'class' => 'App\Http\Controllers\OrderController',
                'role' => 'controller',
                'path' => 'app/Http/Controllers/OrderController.php',
            ]],
            'edges' => [[
                'kind' => 'route_handler',
                'from' => 'route:orders.show',
                'to' => 'OrderController@show',
            ], [
                'kind' => 'eloquent_relation',
                'from' => 'App\Models\Order',
                'to' => 'App\Models\Customer',
            ]],
            'raw_source_included' => false,
        ], JSON_THROW_ON_ERROR),
        'sha256' => str_repeat('4', 64),
        'truncated' => false,
        'redactions' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $response = $this->getJson('/api/hades/v1/memory/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'orders show controller graph',
        'domain' => 'artifacts',
        'schema' => 'hades.php_graph.v1',
        'symbol' => 'OrderController@show',
        'path' => 'OrderController.php',
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('items.0.id', $artifactId)
        ->assertJsonPath('items.0.domain', 'artifacts')
        ->assertJsonPath('items.0.schema', 'hades.php_graph.v1')
        ->assertJsonPath('items.0.source', 'hades.php_graph.v1');

    expect($response->json('items.0.summary'))->toContain('GET /orders/{order}')
        ->and($response->json('items.0.summary'))->toContain('OrderController@show')
        ->and($response->json('items.0.summary'))->toContain('route_handler:1')
        ->and($response->json('items.0.payload_excerpt'))->toContain('hades.php_graph.v1')
        ->and($response->json('items.0.match_fields'))->toContain('schema')
        ->and($response->json('items.0.match_fields'))->toContain('symbol')
        ->and($response->json('items.0.match_fields'))->toContain('path');
});

it('searches generic code graph artifacts through the Hades memory search endpoint', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $artifactId = (string) Str::ulid();
    $now = now();

    DB::table('hades_agent_artifacts')->insert([
        'id' => $artifactId,
        'project_id' => $agent['project_id'],
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'job_id' => null,
        'schema' => 'hades.code_graph.v1',
        'artifact' => json_encode([
            'schema' => 'hades.code_graph.v1',
            'framework' => 'nextjs',
            'head_commit' => str_repeat('e', 40),
            'routes' => [[
                'framework' => 'nextjs',
                'method' => 'GET',
                'path' => '/api/orders',
                'handler' => 'app/api/orders/route.ts:GET',
                'source_path' => 'app/api/orders/route.ts',
            ]],
            'symbols' => [[
                'kind' => 'component',
                'name' => 'OrdersPage',
                'path' => 'app/orders/page.tsx',
            ]],
            'edges' => [[
                'kind' => 'imports',
                'from' => 'app/api/orders/route.ts',
                'to' => '../../../server/orders',
            ]],
            'raw_source_included' => false,
        ], JSON_THROW_ON_ERROR),
        'sha256' => str_repeat('6', 64),
        'truncated' => false,
        'redactions' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $response = $this->getJson('/api/hades/v1/memory/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'orders nextjs route component graph',
        'domain' => 'artifacts',
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('items.0.id', $artifactId)
        ->assertJsonPath('items.0.schema', 'hades.code_graph.v1');

    expect($response->json('items.0.summary'))->toContain('GET /api/orders')
        ->and($response->json('items.0.summary'))->toContain('OrdersPage')
        ->and($response->json('items.0.summary'))->toContain('framework: nextjs')
        ->and($response->json('items.0.payload_excerpt'))->toContain('hades.code_graph.v1');
});

it('traverses PHP graph artifacts through a bounded Hades graph endpoint', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $head = str_repeat('f', 40);
    $artifactId = hadesM3Artifact($agent, $binding, 'hades.php_graph.v1', [
        'schema' => 'hades.php_graph.v1',
        'head_commit' => $head,
        'routes' => [[
            'method' => 'GET',
            'uri' => '/orders/{order}',
            'handler' => 'OrderController@show',
            'name' => 'orders.show',
            'path' => 'routes/web.php',
        ]],
        'symbols' => [[
            'kind' => 'method',
            'name' => 'OrderController@show',
            'class' => 'App\Http\Controllers\OrderController',
            'role' => 'controller',
            'path' => 'app/Http/Controllers/OrderController.php',
        ], [
            'kind' => 'class',
            'name' => 'App\Services\OrderPresenter',
            'role' => 'service',
            'path' => 'app/Services/OrderPresenter.php',
        ], [
            'kind' => 'class',
            'name' => 'App\Models\Order',
            'role' => 'model',
            'path' => 'app/Models/Order.php',
        ]],
        'edges' => [[
            'kind' => 'route_handler',
            'from' => 'route:orders.show',
            'to' => 'OrderController@show',
        ], [
            'kind' => 'static_call',
            'from' => 'OrderController@show',
            'to' => 'App\Services\OrderPresenter',
        ], [
            'kind' => 'model_use',
            'from' => 'App\Services\OrderPresenter',
            'to' => 'App\Models\Order',
        ]],
        'raw_source_included' => false,
    ]);

    $response = $this->getJson('/api/hades/v1/graph/traverse?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'start' => 'orders.show',
        'max_depth' => 2,
        'limit' => 10,
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('artifact_id', $artifactId)
        ->assertJsonPath('schema', 'hades.php_graph.v1')
        ->assertJsonPath('head_commit', $head)
        ->assertJsonPath('freshness.status', 'current')
        ->assertJsonPath('provenance.artifact_id', $artifactId)
        ->json();

    expect(collect($response['nodes'])->pluck('id')->all())
        ->toContain('route:orders.show')
        ->toContain('OrderController@show')
        ->toContain('App\Services\OrderPresenter')
        ->not->toContain('App\Models\Order');
    expect(collect($response['edges'])->pluck('kind')->all())
        ->toContain('route_handler')
        ->toContain('static_call')
        ->not->toContain('model_use');
    expect($response['match_fields'])->toContain('id')
        ->and($response['truncated'])->toBeFalse();
});

it('reports missing graph traversal when no graph artifact exists', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);

    $this->getJson('/api/hades/v1/graph/traverse?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'start' => 'orders.show',
    ]), hadesM3Headers($agent['agent_token']))
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'graph_artifact_not_found');
});

it('quarantines raw chunk import bundle entries instead of creating memory proposals', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);

    $this->postJson('/api/hades/v1/memory/import-bundles', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'source' => ['kind' => 'backend_wiki_import'],
        'entries' => [[
            'source_hash' => 'sha256:raw-chunk-1',
            'kind' => 'file_chunk',
            'summary' => 'Route::get raw taxonomy chunk should not become a note.',
            'payload' => [
                'schema' => 'hades.backend_wiki.file_chunk.v1',
                'path' => 'graphify-sidecar/carnovali-facts.md',
                'chunk_index' => 245,
                'chunk_count' => 267,
            ],
        ]],
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('import_batch.counts.proposals_created', 0)
        ->assertJsonPath('import_batch.counts.quarantined_raw_chunks', 1)
        ->assertJsonPath('import_batch.items.0.status', 'raw_chunk_quarantined');

    expect(DB::table('hades_memory_proposals')->where('project_id', $agent['project_id'])->count())->toBe(0);
});

it('rejects shared memory access for an unlinked workspace binding', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);

    DB::table('hades_workspace_bindings')->where('id', $binding['workspace_binding_id'])->update([
        'status' => 'unlinked',
        'unlinked_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson('/api/hades/v1/memory/snapshot?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ]), hadesM3Headers($agent['agent_token']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'workspace_binding_unlinked');

    $this->postJson('/api/hades/v1/memory/proposals', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'local_proposal_id' => 'local-proposal-unlinked',
        'action' => 'create',
        'intent' => 'memory_write',
        'summary' => 'Should not be accepted after unlink.',
        'provenance' => ['source' => 'test'],
    ], hadesM3Headers($agent['agent_token']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'workspace_binding_unlinked');
});

it('stores bug reports and searchable evidence for linked workspaces', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $otherBinding = hadesM3WorkspaceBinding($agent);
    $observedAt = now()->subMinutes(5)->toISOString();

    $report = $this->postJson('/api/hades/v1/bug-reports', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'title' => 'Taxonomy activity page returns 500',
        'symptom' => 'Opening the security activity category page returns HTTP 500.',
        'severity' => 'high',
        'environment' => ['app_env' => 'testing', 'release' => '2026.07.07'],
        'affected_refs' => [['type' => 'route', 'name' => 'taxonomy.security_activity.show']],
        'observed_at' => $observedAt,
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('bug_report.title', 'Taxonomy activity page returns 500')
        ->assertJsonPath('bug_report.severity', 'high')
        ->assertJsonPath('bug_report.environment.release', '2026.07.07')
        ->json('bug_report');

    $evidence = $this->postJson('/api/hades/v1/bug-evidence', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'bug_report_id' => $report['id'],
        'kind' => 'stack_trace',
        'summary' => 'Call to member function active() on null in SecurityActivityCategoryController.',
        'payload' => [
            'exception' => 'Error',
            'frames' => [[
                'file' => 'app/Http/Controllers/Taxonomy/SecurityActivityCategoryController.php',
                'line' => 42,
                'function' => 'show',
            ]],
        ],
        'source' => 'laravel.log',
        'redactions' => 1,
        'retention_class' => 'stack_trace',
        'occurred_at' => $observedAt,
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('evidence.kind', 'stack_trace')
        ->assertJsonPath('evidence.bug_report_id', $report['id'])
        ->assertJsonPath('evidence.payload.frames.0.line', 42)
        ->assertJsonPath('evidence.redactions', 1)
        ->json('evidence');

    expect($evidence['sha256'])->toBeString()->toHaveLength(64);

    $search = $this->getJson('/api/hades/v1/bug-evidence/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'SecurityActivityCategoryController active null',
        'kind' => 'stack_trace',
        'limit' => 5,
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('count', 1)
        ->assertJsonPath('items.0.id', $evidence['id'])
        ->assertJsonPath('items.0.bug_report_id', $report['id'])
        ->assertJsonPath('freshness.workspace_head_commit', str_repeat('f', 40))
        ->assertJsonPath('freshness.index_status', 'live_query')
        ->json();

    expect($search['items'][0]['score'])->toBeGreaterThan(0)
        ->and($search['version'])->toStartWith('bug_evidence_search_');

    $this->getJson('/api/hades/v1/bug-evidence/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $otherBinding['workspace_binding_id'],
        'query' => 'SecurityActivityCategoryController',
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('count', 0);

    $this->getJson('/api/hades/v1/bug-reports/'.$report['id'].'?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('bug_report.id', $report['id'])
        ->assertJsonPath('evidence.0.id', $evidence['id']);
});

it('stores and fetches bounded source slices for linked workspaces', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $content = <<<'SOURCE'
41: public function show(Order $order) {
42:     return ***;
43: }
SOURCE;

    $created = $this->postJson('/api/hades/v1/source-slices', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'path' => 'app/Http/Controllers/OrderController.php',
        'start_line' => 41,
        'end_line' => 43,
        'language' => 'php',
        'symbol' => 'OrderController@show',
        'head_commit' => str_repeat('f', 40),
        'content_redacted' => $content,
        'redactions' => 1,
        'truncated' => false,
        'retention_class' => 'source_slice',
        'policy' => 'manual_review',
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('source_slice.path', 'app/Http/Controllers/OrderController.php')
        ->assertJsonPath('source_slice.start_line', 41)
        ->assertJsonPath('source_slice.end_line', 43)
        ->assertJsonPath('source_slice.content_redacted', $content)
        ->assertJsonPath('source_slice.redactions', 1)
        ->json('source_slice');

    $response = $this->getJson('/api/hades/v1/source-slices?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'path' => 'app/Http/Controllers/OrderController.php',
        'line' => 42,
        'query' => 'OrderController show',
        'limit' => 5,
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('items.0.id', $created['id'])
        ->assertJsonPath('items.0.symbol', 'OrderController@show')
        ->assertJsonPath('items.0.content_redacted', $content)
        ->assertJsonPath('freshness.workspace_head_commit', str_repeat('f', 40));

    expect($response->json('items.0.score'))->toBeGreaterThan(0)
        ->and(DB::table('project_memory_entries')->where('project_id', $agent['project_id'])->count())->toBe(0);
});

it('rejects bug evidence for reports outside the linked workspace', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $otherBinding = hadesM3WorkspaceBinding($agent);

    $report = $this->postJson('/api/hades/v1/bug-reports', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'title' => 'Queue worker failure',
        'symptom' => 'Worker exits before processing the job.',
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->json('bug_report');

    $this->postJson('/api/hades/v1/bug-evidence', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $otherBinding['workspace_binding_id'],
        'bug_report_id' => $report['id'],
        'kind' => 'log_excerpt',
        'summary' => 'This evidence should not attach across workspace bindings.',
        'payload' => ['line' => 'worker failed'],
    ], hadesM3Headers($agent['agent_token']))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'bug_report_not_found');
});

it('reports missing project awareness coverage for a newly linked workspace', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);

    $this->getJson('/api/hades/v1/project-awareness/status?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('project_id', $agent['project_id'])
        ->assertJsonPath('workspace_binding_id', $binding['workspace_binding_id'])
        ->assertJsonPath('freshness.status', 'missing')
        ->assertJsonPath('freshness.stale_reason', 'artifacts_missing')
        ->assertJsonPath('coverage.memory.status', 'missing')
        ->assertJsonPath('coverage.artifacts.status', 'missing')
        ->assertJsonPath('coverage.bug_evidence.status', 'missing')
        ->assertJsonPath('coverage.source_slices.status', 'missing')
        ->assertJsonPath('coverage.code_graph.status', 'missing')
        ->assertJsonPath('diagnosable_without_source', false)
        ->assertJsonPath('overall_status', 'missing_index');
});

it('reports current artifact freshness and partial graph coverage', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $now = now();
    $head = str_repeat('f', 40);

    DB::table('project_memory_entries')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $agent['project_id'],
        'repository_id' => null,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => null,
        'agent_key' => null,
        'source' => 'manual',
        'kind' => 'decision',
        'completeness' => 'complete',
        'summary' => 'Shared memory is available for project awareness.',
        'payload' => json_encode(['note' => 'coverage'], JSON_THROW_ON_ERROR),
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    hadesM3Artifact($agent, $binding, 'hades.git_tree.v1', [
        'schema' => 'hades.git_tree.v1',
        'head_commit' => $head,
        'project_index' => [
            'schema' => 'hades.project_index.v1',
            'routes' => [['method' => 'GET', 'uri' => '/api/hades/v1/health']],
        ],
    ]);

    DB::table('hades_bug_evidence_items')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $agent['project_id'],
        'bug_report_id' => null,
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'kind' => 'failing_test',
        'summary' => 'Feature test reproduces the failure.',
        'payload' => json_encode(['test' => 'HadesTest'], JSON_THROW_ON_ERROR),
        'source' => 'phpunit',
        'sha256' => str_repeat('a', 64),
        'redactions' => 0,
        'retention_class' => 'test_failure',
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $response = $this->getJson('/api/hades/v1/project-awareness/status?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('freshness.status', 'current')
        ->assertJsonPath('freshness.workspace_head_commit', $head)
        ->assertJsonPath('freshness.artifact_head_commit', $head)
        ->assertJsonPath('coverage.memory.status', 'current')
        ->assertJsonPath('coverage.memory.count', 1)
        ->assertJsonPath('coverage.artifacts.status', 'current')
        ->assertJsonPath('coverage.bug_evidence.status', 'current')
        ->assertJsonPath('coverage.code_graph.status', 'partial')
        ->assertJsonPath('coverage.code_graph.coverage_type', 'metadata_or_symbol_index')
        ->assertJsonPath('diagnosable_without_source', false)
        ->assertJsonPath('overall_status', 'partial')
        ->json();

    expect($response['coverage']['artifacts']['schemas']['hades.git_tree.v1'])->toBe(1);
});

it('reports current PHP graph coverage for current graph artifacts', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $head = str_repeat('f', 40);

    hadesM3Artifact($agent, $binding, 'hades.php_graph.v1', [
        'schema' => 'hades.php_graph.v1',
        'head_commit' => $head,
        'routes' => [['method' => 'GET', 'uri' => '/orders/{order}', 'handler' => 'OrderController@show']],
        'symbols' => [['kind' => 'class', 'name' => 'App\Http\Controllers\OrderController']],
        'edges' => [['kind' => 'route_handler', 'from' => 'route:orders.show', 'to' => 'OrderController@show']],
        'raw_source_included' => false,
    ]);

    $response = $this->getJson('/api/hades/v1/project-awareness/status?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('freshness.status', 'current')
        ->assertJsonPath('coverage.artifacts.status', 'current')
        ->assertJsonPath('coverage.code_graph.status', 'current')
        ->assertJsonPath('coverage.code_graph.schema', 'hades.php_graph.v1')
        ->assertJsonPath('coverage.code_graph.coverage_type', 'code_graph')
        ->assertJsonPath('diagnosable_without_source', false)
        ->json();

    expect($response['coverage']['artifacts']['schemas']['hades.php_graph.v1'])->toBe(1);
});

it('reports ready project awareness when source slices and evidence are current', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $now = now();
    $head = str_repeat('f', 40);

    DB::table('project_memory_entries')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $agent['project_id'],
        'repository_id' => null,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => null,
        'agent_key' => null,
        'source' => 'manual',
        'kind' => 'decision',
        'completeness' => 'complete',
        'summary' => 'Project memory is available for ready awareness.',
        'payload' => json_encode(['note' => 'ready'], JSON_THROW_ON_ERROR),
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    hadesM3Artifact($agent, $binding, 'hades.php_graph.v1', [
        'schema' => 'hades.php_graph.v1',
        'head_commit' => $head,
        'routes' => [['method' => 'GET', 'uri' => '/orders/{order}', 'handler' => 'OrderController@show']],
        'symbols' => [['kind' => 'class', 'name' => 'App\Http\Controllers\OrderController']],
        'edges' => [['kind' => 'route_handler', 'from' => 'route:orders.show', 'to' => 'OrderController@show']],
        'raw_source_included' => false,
    ]);

    DB::table('hades_bug_evidence_items')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $agent['project_id'],
        'bug_report_id' => null,
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'kind' => 'stack_trace',
        'summary' => 'OrderController show frame points to line 42.',
        'payload' => json_encode(['frame' => ['path' => 'app/Http/Controllers/OrderController.php', 'line' => 42]], JSON_THROW_ON_ERROR),
        'source' => 'laravel.log',
        'sha256' => str_repeat('b', 64),
        'redactions' => 0,
        'retention_class' => 'stack_trace',
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('hades_source_slices')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $agent['project_id'],
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'job_id' => null,
        'path' => 'app/Http/Controllers/OrderController.php',
        'start_line' => 41,
        'end_line' => 43,
        'language' => 'php',
        'symbol' => 'OrderController@show',
        'head_commit' => $head,
        'sha256' => str_repeat('c', 64),
        'content_redacted' => '41: public function show() {
42:     return ***;
43: }',
        'redactions' => 1,
        'truncated' => false,
        'retention_class' => 'source_slice',
        'policy' => 'manual_review',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $response = $this->getJson('/api/hades/v1/project-awareness/status?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('freshness.status', 'current')
        ->assertJsonPath('coverage.memory.status', 'current')
        ->assertJsonPath('coverage.artifacts.status', 'current')
        ->assertJsonPath('coverage.bug_evidence.status', 'current')
        ->assertJsonPath('coverage.code_graph.status', 'current')
        ->assertJsonPath('coverage.source_slices.status', 'current')
        ->assertJsonPath('coverage.source_slices.source_slice_head_commit', $head)
        ->assertJsonPath('diagnosable_without_source', true)
        ->assertJsonPath('overall_status', 'ready')
        ->json();

    expect($response['coverage']['source_slices']['count'])->toBe(1)
        ->and($response['coverage']['source_slices']['redactions'])->toBe(1);
});

it('rejects unredacted bug evidence and oversized payloads', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);

    $this->postJson('/api/hades/v1/bug-evidence', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'kind' => 'log_excerpt',
        'summary' => 'Authorization: Bearer abcdefghijklmnopqrstuvwxyz',
        'payload' => ['line' => 'request failed'],
    ], hadesM3Headers($agent['agent_token']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'unredacted_secret_detected');

    $this->postJson('/api/hades/v1/bug-evidence', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'kind' => 'log_excerpt',
        'summary' => 'Large but redacted log payload.',
        'payload' => ['line' => str_repeat('x', 70000)],
    ], hadesM3Headers($agent['agent_token']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'evidence_payload_too_large');
});

it('rejects unredacted source slices and diagnosis reports', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);

    $this->postJson('/api/hades/v1/source-slices', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'path' => 'app/Http/Controllers/OrderController.php',
        'start_line' => 10,
        'end_line' => 12,
        'content_redacted' => 'OPENAI_API_KEY=sk-live-secretvalue12345',
        'redactions' => 0,
    ], hadesM3Headers($agent['agent_token']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'unredacted_secret_detected');

    $this->postJson('/api/hades/v1/diagnosis-reports', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'final',
        'confidence' => 'low',
        'root_cause' => 'The request carried Authorization: Bearer abcdefghijklmnopqrstuvwxyz.',
    ], hadesM3Headers($agent['agent_token']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'unredacted_secret_detected');
});

it('stores diagnosis reports with evidence refs for linked workspaces', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $dataset = hadesM3PrivacyDataset($agent, $binding);
    hadesM3CurrentGraphArtifact($agent, $binding);

    $response = $this->postJson('/api/hades/v1/diagnosis-reports', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'final',
        'confidence' => 'high',
        'root_cause' => 'OrderController calls a service with a null dependency.',
        'mechanism' => 'The stack trace frame and source slice both point to the same call site.',
        'evidence_refs' => [
            ['type' => 'bug_evidence', 'id' => $dataset['bug_evidence_id']],
            ['type' => 'source_slice', 'id' => $dataset['source_slice_id']],
        ],
        'freshness' => ['status' => 'current', 'workspace_head_commit' => str_repeat('f', 40)],
        'payload' => ['next_verification' => 'Run the failing feature test.'],
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('diagnosis_report.status', 'final')
        ->assertJsonPath('diagnosis_report.confidence', 'high')
        ->assertJsonPath('diagnosis_report.evidence_refs.0.type', 'bug_evidence')
        ->assertJsonPath('diagnosis_report.freshness.status', 'current')
        ->json('diagnosis_report');

    expect(DB::table('hades_diagnosis_reports')->where('id', $response['id'])->value('root_cause'))
        ->toBe('OrderController calls a service with a null dependency.');
});

it('blocks precise diagnosis reports without current freshness and evidence refs', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);

    $this->postJson('/api/hades/v1/diagnosis-reports', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'final',
        'confidence' => 'high',
        'root_cause' => 'OrderController dereferences a missing customer relation.',
        'evidence_refs' => [['type' => 'bug_evidence', 'id' => 'evidence_1']],
        'freshness' => ['status' => 'stale'],
    ], hadesM3Headers($agent['agent_token']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'diagnosis_freshness_not_current');

    $this->postJson('/api/hades/v1/diagnosis-reports', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'final',
        'confidence' => 'medium',
        'root_cause' => 'OrderController dereferences a missing customer relation.',
        'freshness' => ['status' => 'current'],
    ], hadesM3Headers($agent['agent_token']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'diagnosis_evidence_refs_required');

    $this->postJson('/api/hades/v1/diagnosis-reports', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'final',
        'confidence' => 'low',
        'root_cause' => 'Possible cause needs current evidence before precision.',
        'freshness' => ['status' => 'stale'],
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('diagnosis_report.confidence', 'low');
});

it('promotes final diagnosis reports to resolved bug memory and search surfaces staleness', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $dataset = hadesM3PrivacyDataset($agent, $binding);
    hadesM3CurrentGraphArtifact($agent, $binding);

    $bugReport = $this->postJson('/api/hades/v1/bug-reports', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'title' => 'Order page returns 500',
        'symptom' => 'Order show fails with active() on null for archived customers.',
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->json('bug_report');

    $diagnosis = $this->postJson('/api/hades/v1/diagnosis-reports', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'bug_report_id' => $bugReport['id'],
        'status' => 'final',
        'confidence' => 'high',
        'root_cause' => 'OrderController dereferences a missing customer relation before checking active().',
        'mechanism' => 'The stack trace frame and source slice both point to OrderController@show calling active() on a nullable relation.',
        'evidence_refs' => [
            ['type' => 'bug_evidence', 'id' => $dataset['bug_evidence_id']],
            ['type' => 'source_slice', 'id' => $dataset['source_slice_id']],
        ],
        'freshness' => ['status' => 'current', 'workspace_head_commit' => str_repeat('f', 40)],
        'payload' => ['next_verification' => 'Run OrderControllerTest::test_archived_customer_show.'],
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->json('diagnosis_report');

    $created = $this->postJson('/api/hades/v1/diagnosis-reports/'.$diagnosis['id'].'/promote', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'verification_status' => 'test_passed',
        'fix_commit' => str_repeat('a', 40),
        'affected_symbols' => ['OrderController@show'],
        'regression_tests' => ['OrderControllerTest::test_archived_customer_show'],
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('already_promoted', false)
        ->assertJsonPath('resolved_bug_memory.kind', 'resolved_bug')
        ->assertJsonPath('resolved_bug_memory.payload.schema', 'hades.resolved_bug.v1')
        ->assertJsonPath('resolved_bug_memory.payload.root_cause', 'OrderController dereferences a missing customer relation before checking active().')
        ->assertJsonPath('resolved_bug_memory.payload.verification_status', 'test_passed')
        ->assertJsonPath('resolved_bug_memory.payload.affected_symbols.0', 'OrderController@show')
        ->json('resolved_bug_memory');

    expect(DB::table('project_memory_entries')->where('id', $created['id'])->value('kind'))->toBe('resolved_bug')
        ->and(DB::table('project_memory_entries')->where('id', $created['id'])->value('source'))->toBe('hades_diagnosis_report')
        ->and(DB::table('project_memory_links')->where('memory_entry_id', $created['id'])->where('target_type', 'hades_diagnosis_report')->where('target_id', $diagnosis['id'])->exists())->toBeTrue();

    $this->postJson('/api/hades/v1/diagnosis-reports/'.$diagnosis['id'].'/promote', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'verification_status' => 'test_passed',
    ], hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('already_promoted', true)
        ->assertJsonPath('resolved_bug_memory.id', $created['id']);

    $this->getJson('/api/hades/v1/memory/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'active null archived customer',
        'domain' => 'project_memory',
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('items.0.id', $created['id'])
        ->assertJsonPath('items.0.kind', 'resolved_bug')
        ->assertJsonPath('items.0.stale', false);

    DB::table('hades_workspace_bindings')
        ->where('id', $binding['workspace_binding_id'])
        ->update(['head_commit' => str_repeat('b', 40), 'updated_at' => now()]);

    $this->getJson('/api/hades/v1/memory/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'active null archived customer',
        'domain' => 'project_memory',
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('items.0.id', $created['id'])
        ->assertJsonPath('items.0.stale', true)
        ->assertJsonPath('items.0.stale_reason', 'workspace_head_changed');
});

it('refuses to promote unverified diagnosis reports to resolved bug memory', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);

    $diagnosis = $this->postJson('/api/hades/v1/diagnosis-reports', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'final',
        'confidence' => 'insufficient',
        'root_cause' => 'not determined',
        'freshness' => ['status' => 'partial'],
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->json('diagnosis_report');

    $this->postJson('/api/hades/v1/diagnosis-reports/'.$diagnosis['id'].'/promote', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'verification_status' => 'manual_review',
    ], hadesM3Headers($agent['agent_token']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'diagnosis_confidence_too_low');

    expect(DB::table('project_memory_entries')->where('project_id', $agent['project_id'])->where('kind', 'resolved_bug')->exists())->toBeFalse();
});

it('stores and searches Hades evidence packs for source-free diagnosis', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $head = str_repeat('f', 40);

    $bug = $this->postJson('/api/hades/v1/bug-reports', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'title' => 'Order page returns 500',
        'symptom' => 'Opening an archived order throws a 500.',
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->json('bug_report');

    $evidence = $this->postJson('/api/hades/v1/bug-evidence', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'bug_report_id' => $bug['id'],
        'kind' => 'stack_trace',
        'summary' => 'OrderController show fails on archived customer at line 42.',
        'payload' => ['frame' => ['path' => 'app/Http/Controllers/OrderController.php', 'line' => 42]],
        'source' => 'laravel.log',
        'retention_class' => 'stack_trace',
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->json('evidence');

    $slice = $this->postJson('/api/hades/v1/source-slices', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'path' => 'app/Http/Controllers/OrderController.php',
        'start_line' => 41,
        'end_line' => 43,
        'language' => 'php',
        'symbol' => 'OrderController@show',
        'head_commit' => $head,
        'content_redacted' => "41: public function show() {\n42:     return ***;\n43: }",
        'redactions' => 1,
        'policy' => 'manual_review',
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->json('source_slice');

    $pack = $this->postJson('/api/hades/v1/evidence-packs', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'bug_report_id' => $bug['id'],
        'title' => 'Archived order 500 evidence pack',
        'summary' => 'Stack trace, graph route, and source slice point to OrderController archived customer handling.',
        'evidence_refs' => [['type' => 'bug_evidence', 'id' => $evidence['id']]],
        'graph_refs' => [['type' => 'route_handler', 'from' => 'route:orders.show', 'to' => 'OrderController@show']],
        'source_slice_ids' => [$slice['id']],
        'payload' => ['next_verification' => 'Run OrderControllerTest::test_archived_order_show'],
        'redactions' => 1,
        'head_commit' => $head,
    ], hadesM3Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('evidence_pack.bug_report_id', $bug['id'])
        ->assertJsonPath('evidence_pack.source_slice_ids.0', $slice['id'])
        ->assertJsonPath('evidence_pack.evidence_refs.0.id', $evidence['id'])
        ->json('evidence_pack');

    $this->getJson('/api/hades/v1/evidence-packs?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'archived customer',
        'limit' => 5,
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('items.0.id', $pack['id'])
        ->assertJsonPath('items.0.payload.next_verification', 'Run OrderControllerTest::test_archived_order_show');

    $this->getJson('/api/hades/v1/project-awareness/status?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('coverage.evidence_packs.status', 'current')
        ->assertJsonPath('coverage.evidence_packs.count', 1);

    $this->postJson('/api/hades/v1/evidence-packs', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'title' => 'Authorization: Bearer abcdefghijklmnopqrstuvwxyz',
        'summary' => 'Unredacted token should be refused.',
        'payload' => [],
    ], hadesM3Headers($agent['agent_token']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'unredacted_secret_detected');
});

it('exports Hades diagnosis data scoped to a workspace binding', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $otherBinding = hadesM3WorkspaceBinding($agent);
    $dataset = hadesM3PrivacyDataset($agent, $binding);
    $other = hadesM3PrivacyDataset($agent, $otherBinding);

    $metadataOnly = $this->getJson('/api/hades/v1/privacy/export?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'include_content' => false,
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('scope', 'workspace_binding')
        ->assertJsonPath('include_content', false)
        ->assertJsonPath('counts.bug_reports', 1)
        ->assertJsonPath('counts.bug_evidence', 1)
        ->assertJsonPath('counts.source_slices', 1)
        ->assertJsonPath('counts.evidence_packs', 1)
        ->assertJsonPath('counts.diagnosis_reports', 1)
        ->assertJsonPath('collections.bug_reports.0.id', $dataset['bug_report_id'])
        ->json();

    expect($metadataOnly['collections']['source_slices'][0])->not->toHaveKey('content_redacted')
        ->and($metadataOnly['collections']['bug_evidence'][0])->not->toHaveKey('payload')
        ->and(json_encode($metadataOnly, JSON_THROW_ON_ERROR))->not->toContain($other['bug_report_id']);

    $withContent = $this->getJson('/api/hades/v1/privacy/export?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('include_content', true)
        ->assertJsonPath('collections.source_slices.0.content_redacted', 'line 42: return ***;')
        ->assertJsonPath('collections.bug_evidence.0.payload.frame.line', 42)
        ->json();

    expect(json_encode($withContent, JSON_THROW_ON_ERROR))->not->toContain($other['source_slice_id']);

    $auditPayloads = DB::table('audit_logs')
        ->where('action', 'hades.privacy_exported')
        ->orderBy('created_at')
        ->pluck('payload')
        ->map(fn (string $payload): array => json_decode($payload, true, flags: JSON_THROW_ON_ERROR))
        ->all();

    expect($auditPayloads)->toHaveCount(2)
        ->and(json_encode($auditPayloads, JSON_THROW_ON_ERROR))->not->toContain('line 42: return ***;');
});

it('deletes Hades diagnosis data only after confirmation and keeps other workspaces', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $otherBinding = hadesM3WorkspaceBinding($agent);
    hadesM3PrivacyDataset($agent, $binding);
    hadesM3PrivacyDataset($agent, $otherBinding);

    $this->postJson('/api/hades/v1/privacy/delete', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ], hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('dry_run', true)
        ->assertJsonPath('would_delete.hades_bug_reports', 1)
        ->assertJsonPath('would_delete.hades_source_slices', 1);

    expect(DB::table('hades_bug_reports')->where('workspace_binding_id', $binding['workspace_binding_id'])->count())->toBe(1);

    $this->postJson('/api/hades/v1/privacy/delete', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'dry_run' => false,
    ], hadesM3Headers($agent['agent_token']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'delete_confirmation_required');

    $this->postJson('/api/hades/v1/privacy/delete', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'dry_run' => false,
        'confirm' => true,
    ], hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('dry_run', false)
        ->assertJsonPath('deleted.hades_bug_reports', 1)
        ->assertJsonPath('deleted.hades_diagnosis_reports', 1);

    foreach (['hades_bug_reports', 'hades_bug_evidence_items', 'hades_source_slices', 'hades_evidence_packs', 'hades_diagnosis_reports'] as $table) {
        expect(DB::table($table)->where('workspace_binding_id', $binding['workspace_binding_id'])->count())->toBe(0)
            ->and(DB::table($table)->where('workspace_binding_id', $otherBinding['workspace_binding_id'])->count())->toBe(1);
    }

    $auditPayloads = DB::table('audit_logs')
        ->where('action', 'hades.privacy_deleted')
        ->orderBy('created_at')
        ->pluck('payload')
        ->map(fn (string $payload): array => json_decode($payload, true, flags: JSON_THROW_ON_ERROR))
        ->all();

    expect($auditPayloads)->toHaveCount(2)
        ->and($auditPayloads[0]['dry_run'])->toBeTrue()
        ->and($auditPayloads[1]['dry_run'])->toBeFalse()
        ->and($auditPayloads[1]['counts']['hades_bug_reports'])->toBe(1)
        ->and(json_encode($auditPayloads, JSON_THROW_ON_ERROR))->not->toContain('OrderController');
});

it('cleans up Hades diagnosis data by retention age with dry-run safety', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    hadesM3PrivacyDataset($agent, $binding, now()->subDays(45));
    hadesM3PrivacyDataset($agent, $binding, now());

    $this->postJson('/api/hades/v1/privacy/retention-cleanup', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'retention_days' => 30,
    ], hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('dry_run', true)
        ->assertJsonPath('would_delete.hades_bug_reports', 1)
        ->assertJsonPath('would_delete.hades_source_slices', 1);

    expect(DB::table('hades_bug_reports')->where('workspace_binding_id', $binding['workspace_binding_id'])->count())->toBe(2);

    $this->postJson('/api/hades/v1/privacy/retention-cleanup', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'retention_days' => 30,
        'dry_run' => false,
    ], hadesM3Headers($agent['agent_token']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'retention_cleanup_confirmation_required');

    $this->postJson('/api/hades/v1/privacy/retention-cleanup', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'retention_days' => 30,
        'dry_run' => false,
        'confirm' => true,
    ], hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('dry_run', false)
        ->assertJsonPath('deleted.hades_bug_reports', 1)
        ->assertJsonPath('deleted.hades_evidence_packs', 1);

    foreach (['hades_bug_reports', 'hades_bug_evidence_items', 'hades_source_slices', 'hades_evidence_packs', 'hades_diagnosis_reports'] as $table) {
        expect(DB::table($table)->where('workspace_binding_id', $binding['workspace_binding_id'])->count())->toBe(1);
    }

    $auditPayloads = DB::table('audit_logs')
        ->where('action', 'hades.retention_cleaned')
        ->orderBy('created_at')
        ->pluck('payload')
        ->map(fn (string $payload): array => json_decode($payload, true, flags: JSON_THROW_ON_ERROR))
        ->all();

    expect($auditPayloads)->toHaveCount(2)
        ->and($auditPayloads[0]['dry_run'])->toBeTrue()
        ->and($auditPayloads[1]['dry_run'])->toBeFalse()
        ->and($auditPayloads[1]['retention_days'])->toBe(30)
        ->and($auditPayloads[1]['counts']['hades_source_slices'])->toBe(1)
        ->and(json_encode($auditPayloads, JSON_THROW_ON_ERROR))->not->toContain('line 42: return ***;');
});

it('reports stale project awareness when indexed artifacts are from another commit', function () {
    $agent = hadesM3RegisteredAgent();
    $binding = hadesM3WorkspaceBinding($agent);
    $staleHead = str_repeat('e', 40);

    hadesM3Artifact($agent, $binding, 'hades.git_tree.v1', [
        'schema' => 'hades.git_tree.v1',
        'head_commit' => $staleHead,
        'files' => [['path' => 'README.md', 'sha256' => str_repeat('1', 64), 'bytes' => 100]],
    ]);

    $response = $this->getJson('/api/hades/v1/project-awareness/status?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ]), hadesM3Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('freshness.status', 'stale')
        ->assertJsonPath('freshness.stale_reason', 'artifact_head_mismatch')
        ->assertJsonPath('freshness.artifact_head_commit', $staleHead)
        ->assertJsonPath('coverage.artifacts.status', 'stale')
        ->assertJsonPath('coverage.code_graph.status', 'stale')
        ->assertJsonPath('overall_status', 'stale')
        ->json();

    expect($response['actions'])->toContain('Run `hades backend sync` from the current checkout so indexed artifacts match workspace HEAD.');
});

function hadesM3Headers(?string $token = null): array
{
    return $token === null ? [] : ['Authorization' => 'Bearer '.$token];
}

/**
 * @return array{id: string, user_id: int}
 */
function hadesM3Project(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $id = (string) Str::ulid();
    $now = now();

    DB::table('projects')->insert([
        'id' => $id,
        'name' => 'Hades M3 Test Project',
        'slug' => 'hades-m3-test-'.Str::lower(Str::random(8)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['id' => $id, 'user_id' => $user->id];
}

/**
 * @return array{id: string, plain_token: string, project_id: string}
 */
function hadesM3BootstrapToken(?string $projectId = null): array
{
    $projectId = $projectId ?: hadesM3Project()['id'];
    $id = (string) Str::ulid();
    $secret = 'hades-m3-bootstrap-test-secret';
    $prefix = 'hades_bootstrap_'.$id;
    $now = now();

    DB::table('hades_bootstrap_tokens')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'name' => 'Hades M3 Bootstrap Token',
        'scopes' => json_encode(['hades.bootstrap'], JSON_THROW_ON_ERROR),
        'allowed_capabilities' => json_encode(['read_files', 'sync_git_tree', 'populate_backend_ast'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['id' => $id, 'plain_token' => $prefix.'|'.$secret, 'project_id' => $projectId];
}

/**
 * @return array{project_id: string, external_agent_id: string, backend_agent_id: string, agent_token: string}
 */
function hadesM3RegisteredAgent(): array
{
    $bootstrap = hadesM3BootstrapToken();
    $externalAgentId = 'local-agent-'.Str::lower(Str::random(8));
    $test = test();

    $registered = $test->postJson('/api/hades/v1/agents/register', [
        'project_id' => $bootstrap['project_id'],
        'agent_id' => $externalAgentId,
        'label' => 'Hades M3 Agent',
        'platform' => 'linux-x64',
        'version' => '0.3.0',
        'capabilities' => ['read_files', 'sync_git_tree', 'populate_backend_ast'],
    ], hadesM3Headers($bootstrap['plain_token']))->assertOk();

    return [
        'project_id' => $bootstrap['project_id'],
        'external_agent_id' => $externalAgentId,
        'backend_agent_id' => $registered->json('backend_agent_id'),
        'agent_token' => $registered->json('agent_token'),
    ];
}

/**
 * @param  array{project_id: string, external_agent_id: string, backend_agent_id: string, agent_token: string}  $agent
 * @return array{workspace_binding_id: string}
 */
function hadesM3WorkspaceBinding(array $agent): array
{
    $test = test();
    $bound = $test->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_fingerprint' => 'wf_hades_m3_'.Str::lower(Str::random(8)),
        'display_path' => '~/Code/hades-m3',
        'git_remote_display' => 'github.com/acme/hades-m3.git',
        'git_remote_hash' => hash('sha256', 'git@github.com:acme/hades-m3.git'),
        'head_commit' => str_repeat('f', 40),
    ], hadesM3Headers($agent['agent_token']))->assertOk();

    return ['workspace_binding_id' => $bound->json('workspace_binding_id')];
}

/**
 * @param  array{project_id: string, external_agent_id: string, backend_agent_id: string, agent_token: string}  $agent
 * @param  array{workspace_binding_id: string}  $binding
 * @return array{bug_report_id: string, bug_evidence_id: string, source_slice_id: string, evidence_pack_id: string, diagnosis_report_id: string}
 */
function hadesM3PrivacyDataset(array $agent, array $binding, ?Carbon $createdAt = null): array
{
    $createdAt ??= now();
    $bugReportId = (string) Str::ulid();
    $bugEvidenceId = (string) Str::ulid();
    $sourceSliceId = (string) Str::ulid();
    $evidencePackId = (string) Str::ulid();
    $diagnosisReportId = (string) Str::ulid();

    DB::table('hades_bug_reports')->insert([
        'id' => $bugReportId,
        'project_id' => $agent['project_id'],
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'title' => 'Privacy export fixture bug',
        'symptom' => 'Order show returns 500.',
        'severity' => 'high',
        'status' => 'open',
        'environment' => json_encode(['app_env' => 'testing'], JSON_THROW_ON_ERROR),
        'affected_refs' => json_encode([['type' => 'route', 'name' => 'orders.show']], JSON_THROW_ON_ERROR),
        'observed_at' => $createdAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    DB::table('hades_bug_evidence_items')->insert([
        'id' => $bugEvidenceId,
        'project_id' => $agent['project_id'],
        'bug_report_id' => $bugReportId,
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'kind' => 'stack_trace',
        'summary' => 'OrderController fails at line 42.',
        'payload' => json_encode(['frame' => ['path' => 'app/Http/Controllers/OrderController.php', 'line' => 42]], JSON_THROW_ON_ERROR),
        'source' => 'laravel.log',
        'sha256' => str_repeat('1', 64),
        'redactions' => 1,
        'retention_class' => 'stack_trace',
        'occurred_at' => $createdAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    DB::table('hades_source_slices')->insert([
        'id' => $sourceSliceId,
        'project_id' => $agent['project_id'],
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'job_id' => null,
        'path' => 'app/Http/Controllers/OrderController.php',
        'start_line' => 42,
        'end_line' => 42,
        'language' => 'php',
        'symbol' => 'OrderController@show',
        'head_commit' => str_repeat('f', 40),
        'sha256' => str_repeat('2', 64),
        'content_redacted' => 'line 42: return ***;',
        'redactions' => 1,
        'truncated' => false,
        'retention_class' => 'source_slice',
        'policy' => 'manual_review',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    DB::table('hades_evidence_packs')->insert([
        'id' => $evidencePackId,
        'project_id' => $agent['project_id'],
        'bug_report_id' => $bugReportId,
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'title' => 'Privacy evidence pack',
        'summary' => 'Stack trace and source slice identify the order failure.',
        'evidence_refs' => json_encode([['type' => 'bug_evidence', 'id' => $bugEvidenceId]], JSON_THROW_ON_ERROR),
        'graph_refs' => json_encode([['type' => 'route', 'id' => 'orders.show']], JSON_THROW_ON_ERROR),
        'source_slice_ids' => json_encode([$sourceSliceId], JSON_THROW_ON_ERROR),
        'payload' => json_encode(['next_verification' => 'run feature test'], JSON_THROW_ON_ERROR),
        'sha256' => str_repeat('3', 64),
        'redactions' => 1,
        'retention_class' => 'diagnosis_evidence',
        'head_commit' => str_repeat('f', 40),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    DB::table('hades_diagnosis_reports')->insert([
        'id' => $diagnosisReportId,
        'project_id' => $agent['project_id'],
        'bug_report_id' => $bugReportId,
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'final',
        'confidence' => 'high',
        'root_cause' => 'OrderController dereferences a nullable relation.',
        'mechanism' => 'The stack frame and source slice point to the same line.',
        'evidence_refs' => json_encode([['type' => 'bug_evidence', 'id' => $bugEvidenceId], ['type' => 'source_slice', 'id' => $sourceSliceId]], JSON_THROW_ON_ERROR),
        'freshness' => json_encode(['status' => 'current', 'workspace_head_commit' => str_repeat('f', 40)], JSON_THROW_ON_ERROR),
        'payload' => json_encode(['affected_symbols' => ['OrderController@show']], JSON_THROW_ON_ERROR),
        'redactions' => 1,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return [
        'bug_report_id' => $bugReportId,
        'bug_evidence_id' => $bugEvidenceId,
        'source_slice_id' => $sourceSliceId,
        'evidence_pack_id' => $evidencePackId,
        'diagnosis_report_id' => $diagnosisReportId,
    ];
}

/**
 * @param  array{project_id: string, external_agent_id: string, backend_agent_id: string, agent_token: string}  $agent
 * @param  array{workspace_binding_id: string}  $binding
 * @param  array<string, mixed>  $artifact
 */
function hadesM3CurrentGraphArtifact(array $agent, array $binding): string
{
    return hadesM3Artifact($agent, $binding, 'hades.php_graph.v1', [
        'schema' => 'hades.php_graph.v1',
        'head_commit' => str_repeat('f', 40),
        'raw_source_included' => false,
        'symbols' => [['name' => 'OrderController@show', 'kind' => 'method', 'path' => 'app/Http/Controllers/OrderController.php', 'line' => 42]],
        'edges' => [['kind' => 'handles', 'from' => 'route:orders.show', 'to' => 'OrderController@show']],
    ]);
}

function hadesM3Artifact(array $agent, array $binding, string $schema, array $artifact): string
{
    $id = (string) Str::ulid();
    $artifactJson = json_encode($artifact, JSON_THROW_ON_ERROR);
    $now = now();

    DB::table('hades_agent_artifacts')->insert([
        'id' => $id,
        'project_id' => $agent['project_id'],
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'job_id' => null,
        'schema' => $schema,
        'artifact' => $artifactJson,
        'sha256' => hash('sha256', $artifactJson),
        'truncated' => false,
        'redactions' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}
