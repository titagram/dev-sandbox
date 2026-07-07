<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ->and($response->json('items.0.payload_excerpt'))->toContain('hades.php_graph.v1');
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

    $response = $this->postJson('/api/hades/v1/diagnosis-reports', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'final',
        'confidence' => 'high',
        'root_cause' => 'OrderController calls a service with a null dependency.',
        'mechanism' => 'The stack trace frame and source slice both point to the same call site.',
        'evidence_refs' => [
            ['type' => 'bug_evidence', 'id' => 'evidence_1'],
            ['type' => 'source_slice', 'id' => 'slice_1'],
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
 * @param  array<string, mixed>  $artifact
 */
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
