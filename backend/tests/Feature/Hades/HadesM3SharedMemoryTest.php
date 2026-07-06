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
