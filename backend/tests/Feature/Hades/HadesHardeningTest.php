<?php

use App\Models\User;
use App\Services\Hades\HadesTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps default bootstrap capabilities aligned with the current Hephaistos client', function () {
    $agent = hadesHardeningAgent(['read_files']);
    $created = app(HadesTokenService::class)->createBootstrapToken(
        $agent['project_id'],
        'Current client defaults',
    );
    $allowed = json_decode($created['token']->allowed_capabilities, true, flags: JSON_THROW_ON_ERROR);

    expect($allowed)->toBe([
        'read_files',
        'read_source_slice',
        'project_inspection',
        'sync_git_tree',
        'populate_backend_ast',
        'populate_project_wiki',
    ]);
});

it('enforces effective capabilities when listing and claiming jobs', function () {
    $agent = hadesHardeningAgent(['read_files'], ['read_files', 'populate_backend_ast']);
    $binding = hadesHardeningBinding($agent);
    $forbiddenJob = hadesHardeningJob($agent, $binding, [
        'capability' => 'populate_backend_ast',
        'hades_agent_id' => null,
    ]);

    $response = $this->getJson('/api/hades/v1/agent/jobs?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'capabilities' => ['populate_backend_ast'],
    ]), hadesHardeningHeaders($agent['agent_token']))
        ->assertOk();

    expect($response->json('jobs'))->toBe([]);

    $this->postJson('/api/hades/v1/agent/jobs/'.$forbiddenJob.'/status', hadesHardeningStatusPayload($agent, $binding, 'received'), hadesHardeningHeaders($agent['agent_token']))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'job_capability_not_allowed');

    expect(DB::table('hades_agent_jobs')->where('id', $forbiddenJob)->value('status'))->toBe('queued')
        ->and(DB::table('hades_agent_jobs')->where('id', $forbiddenJob)->value('hades_agent_id'))->toBeNull()
        ->and(DB::table('hades_agent_job_events')->where('job_id', $forbiddenJob)->count())->toBe(0);
});

it('enforces confirmation as a server side barrier and prevents duplicate claim', function () {
    $agent = hadesHardeningAgent(['read_files']);
    $binding = hadesHardeningBinding($agent);
    $job = hadesHardeningJob($agent, $binding, ['requires_confirmation' => true]);

    $this->postJson('/api/hades/v1/agent/jobs/'.$job.'/status', hadesHardeningStatusPayload($agent, $binding, 'received'), hadesHardeningHeaders($agent['agent_token']))
        ->assertOk();

    $this->postJson('/api/hades/v1/agent/jobs/'.$job.'/status', hadesHardeningStatusPayload($agent, $binding, 'received'), hadesHardeningHeaders($agent['agent_token']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'job_transition_invalid');

    $this->postJson('/api/hades/v1/agent/jobs/'.$job.'/status', hadesHardeningStatusPayload($agent, $binding, 'started'), hadesHardeningHeaders($agent['agent_token']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'job_confirmation_required');

    $this->postJson('/api/hades/v1/agent/jobs/'.$job.'/status', hadesHardeningStatusPayload($agent, $binding, 'waiting_confirmation'), hadesHardeningHeaders($agent['agent_token']))
        ->assertOk();
    $this->postJson('/api/hades/v1/agent/jobs/'.$job.'/status', hadesHardeningStatusPayload($agent, $binding, 'started'), hadesHardeningHeaders($agent['agent_token']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'job_transition_invalid');

    $this->postJson('/api/hades/v1/agent/jobs/'.$job.'/status', hadesHardeningStatusPayload($agent, $binding, 'completed'), hadesHardeningHeaders($agent['agent_token']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect(DB::table('hades_agent_job_events')->where('job_id', $job)->where('status', 'received')->count())->toBe(1)
        ->and(DB::table('hades_agent_jobs')->where('id', $job)->value('status'))->toBe('waiting_confirmation')
        ->and(DB::table('hades_agent_job_events')->where('job_id', $job)->where('status', 'started')->count())->toBe(0);
});

it('rejects job result schemas that do not match capability and job type', function () {
    $agent = hadesHardeningAgent(['populate_project_wiki']);
    $binding = hadesHardeningBinding($agent);
    $job = hadesHardeningJob($agent, $binding, [
        'capability' => 'populate_project_wiki',
        'job_type' => 'wiki_refresh',
        'status' => 'started',
        'claimed_at' => now(),
        'started_at' => now(),
    ]);

    $this->postJson('/api/hades/v1/agent/jobs/'.$job.'/result', hadesHardeningStatusPayload($agent, $binding, 'completed') + [
        'result' => ['schema' => 'hades.git_tree.v1', 'status' => 'completed', 'pages' => []],
    ], hadesHardeningHeaders($agent['agent_token']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'job_result_schema_mismatch');

    expect(DB::table('hades_agent_jobs')->where('id', $job)->value('status'))->toBe('started')
        ->and(DB::table('hades_agent_job_events')->where('job_id', $job)->count())->toBe(0);
});

it('does not allow direct result submission for normal or confirmation gated jobs', function () {
    $agent = hadesHardeningAgent(['read_files', 'populate_project_wiki']);
    $binding = hadesHardeningBinding($agent);
    $normalJob = hadesHardeningJob($agent, $binding);
    $confirmedWikiJob = hadesHardeningJob($agent, $binding, [
        'capability' => 'populate_project_wiki',
        'job_type' => 'wiki_refresh',
        'requires_confirmation' => true,
    ]);

    $this->postJson('/api/hades/v1/agent/jobs/'.$normalJob.'/result', hadesHardeningStatusPayload($agent, $binding, 'completed') + [
        'result' => ['status' => 'completed', 'summary' => 'Bypass attempt.'],
    ], hadesHardeningHeaders($agent['agent_token']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'job_transition_invalid');

    $this->postJson('/api/hades/v1/agent/jobs/'.$confirmedWikiJob.'/result', hadesHardeningStatusPayload($agent, $binding, 'completed') + [
        'result' => ['schema' => 'devboard.wiki_refresh_result.v1', 'status' => 'completed', 'pages' => []],
    ], hadesHardeningHeaders($agent['agent_token']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'job_transition_invalid');

    expect(DB::table('hades_agent_jobs')->whereIn('id', [$normalJob, $confirmedWikiJob])->pluck('status')->all())
        ->each->toBe('queued');
});

it('atomically records implicit states for a direct non confirmation wiki result', function () {
    $agent = hadesHardeningAgent(['populate_project_wiki']);
    $binding = hadesHardeningBinding($agent);
    $job = hadesHardeningJob($agent, $binding, [
        'capability' => 'populate_project_wiki',
        'job_type' => 'wiki_refresh',
        'policy' => 'manual_review',
        'requires_confirmation' => false,
    ]);

    $this->postJson('/api/hades/v1/agent/jobs/'.$job.'/result', hadesHardeningStatusPayload($agent, $binding, 'completed') + [
        'result' => ['schema' => 'devboard.wiki_refresh_result.v1', 'status' => 'completed', 'pages' => []],
    ], hadesHardeningHeaders($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('job.status', 'completed');

    $stored = DB::table('hades_agent_jobs')->where('id', $job)->first();
    expect($stored->claimed_at)->not->toBeNull()
        ->and($stored->started_at)->not->toBeNull()
        ->and($stored->completed_at)->not->toBeNull()
        ->and(DB::table('hades_agent_job_events')->where('job_id', $job)->where('status', 'received')->count())->toBe(1)
        ->and(DB::table('hades_agent_job_events')->where('job_id', $job)->where('status', 'started')->count())->toBe(1)
        ->and(DB::table('hades_agent_job_events')->where('job_id', $job)->where('event_type', 'result')->count())->toBe(1);
});

it('bounds compressed artifact decompression and validates declared bytes', function () {
    $agent = hadesHardeningAgent(['read_files']);
    $binding = hadesHardeningBinding($agent);
    $json = '{"schema":"hades.git_tree.v1","padding":"'.str_repeat('a', 16_000_100).'"}';
    $compressed = gzencode($json);

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'schema' => 'hades.git_tree.v1',
        'artifact_encoding' => 'gzip+base64',
        'artifact_compressed' => base64_encode($compressed),
        'artifact_uncompressed_sha256' => hash('sha256', $json),
        'artifact_uncompressed_bytes' => 16_000_000,
        'artifact_compressed_bytes' => strlen($compressed),
    ], hadesHardeningHeaders($agent['agent_token']))
        ->assertStatus(413)
        ->assertExactJson(['error' => [
            'code' => 'artifact_uncompressed_too_large',
            'message' => 'Artifact exceeds the uncompressed byte limit.',
        ]]);

    expect(DB::table('hades_agent_artifacts')->count())->toBe(0);
});

it('blocks Hades mutations for archived and deleted projects while retaining reads and privacy deletion', function () {
    $agent = hadesHardeningAgent(['read_files']);
    $binding = hadesHardeningBinding($agent);
    DB::table('projects')->where('id', $agent['project_id'])->update([
        'status' => 'archived',
        'archived_at' => now(),
    ]);

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'schema' => 'hades.git_tree.v1',
        'artifact' => ['schema' => 'hades.git_tree.v1', 'files' => []],
    ], hadesHardeningHeaders($agent['agent_token']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'project_archived');

    DB::table('projects')->where('id', $agent['project_id'])->update([
        'status' => 'deleted',
        'deleted_at' => now(),
    ]);
    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'schema' => 'hades.git_tree.v1',
        'artifact' => ['schema' => 'hades.git_tree.v1', 'files' => []],
    ], hadesHardeningHeaders($agent['agent_token']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'project_deleted');

    $this->getJson('/api/hades/v1/agent/jobs?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ]), hadesHardeningHeaders($agent['agent_token']))->assertOk();

    $this->postJson('/api/hades/v1/privacy/delete', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ], hadesHardeningHeaders($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('dry_run', true);
});

it('privacy delete removes auto accepted proposal memory only for the selected workspace', function () {
    $agent = hadesHardeningAgent(['read_files']);
    $binding = hadesHardeningBinding($agent);
    $otherBinding = hadesHardeningBinding($agent);

    $createProposal = function (array $workspace, string $localId) use ($agent): string {
        return (string) $this->postJson('/api/hades/v1/memory/proposals', [
            'project_id' => $agent['project_id'],
            'workspace_binding_id' => $workspace['workspace_binding_id'],
            'local_proposal_id' => $localId,
            'action' => 'create',
            'intent' => 'shared_memory_update',
            'summary' => 'Auto accepted memory for '.$localId,
            'provenance' => ['source' => 'hardening_test'],
        ], hadesHardeningHeaders($agent['agent_token']))
            ->assertOk()
            ->assertJsonPath('proposal.status', 'accepted')
            ->json('proposal.memory_entry_id');
    };

    $memoryId = $createProposal($binding, 'privacy-selected');
    $otherMemoryId = $createProposal($otherBinding, 'privacy-other');

    $this->getJson('/api/hades/v1/privacy/export?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
    ]), hadesHardeningHeaders($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('counts.proposal_memory', 1)
        ->assertJsonPath('collections.proposal_memory.0.id', $memoryId);

    $this->postJson('/api/hades/v1/privacy/delete', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'dry_run' => false,
        'confirm' => true,
    ], hadesHardeningHeaders($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('deleted.hades_memory_proposals', 1)
        ->assertJsonPath('deleted.project_memory_entries_from_proposals', 1);

    expect(DB::table('project_memory_entries')->where('id', $memoryId)->exists())->toBeFalse()
        ->and(DB::table('hades_search_documents')->where('source_table', 'project_memory_entries')->where('source_id', $memoryId)->exists())->toBeFalse()
        ->and(DB::table('project_memory_entries')->where('id', $otherMemoryId)->exists())->toBeTrue()
        ->and(DB::table('hades_search_documents')->where('source_table', 'project_memory_entries')->where('source_id', $otherMemoryId)->exists())->toBeTrue()
        ->and(DB::table('hades_memory_proposals')->where('workspace_binding_id', $otherBinding['workspace_binding_id'])->exists())->toBeTrue();
});

it('retention cleanup preserves recent memory created by an old proposal', function () {
    $agent = hadesHardeningAgent(['read_files']);
    $binding = hadesHardeningBinding($agent);

    $proposal = $this->postJson('/api/hades/v1/memory/proposals', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'local_proposal_id' => 'old-proposal-recent-memory',
        'action' => 'create',
        'intent' => 'shared_memory_update',
        'summary' => 'Recent memory linked to an old proposal.',
        'provenance' => ['source' => 'retention_test'],
    ], hadesHardeningHeaders($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('proposal.status', 'accepted')
        ->json('proposal');

    DB::table('hades_memory_proposals')->where('id', $proposal['id'])->update([
        'created_at' => now()->subDays(60),
        'updated_at' => now()->subDays(60),
    ]);
    $linkId = (string) Str::ulid();
    DB::table('project_memory_links')->insert([
        'id' => $linkId,
        'memory_entry_id' => $proposal['memory_entry_id'],
        'target_type' => 'retention_fixture',
        'target_id' => (string) Str::ulid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/api/hades/v1/privacy/retention-cleanup', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'retention_days' => 30,
        'dry_run' => false,
        'confirm' => true,
    ], hadesHardeningHeaders($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('deleted.hades_memory_proposals', 1)
        ->assertJsonPath('deleted.project_memory_entries_from_proposals', 0);

    expect(DB::table('hades_memory_proposals')->where('id', $proposal['id'])->exists())->toBeFalse()
        ->and(DB::table('project_memory_entries')->where('id', $proposal['memory_entry_id'])->exists())->toBeTrue()
        ->and(DB::table('hades_search_documents')
            ->where('source_table', 'project_memory_entries')
            ->where('source_id', $proposal['memory_entry_id'])
            ->exists())->toBeTrue()
        ->and(DB::table('project_memory_links')->where('id', $linkId)->exists())->toBeTrue();
});

it('exports and deletes artifacts causal packs search documents jobs and related records', function () {
    $agent = hadesHardeningAgent(['read_files']);
    $binding = hadesHardeningBinding($agent);
    $job = hadesHardeningJob($agent, $binding);
    $artifact = (string) Str::ulid();
    $causal = (string) Str::ulid();
    $memory = (string) Str::ulid();
    $now = now();

    DB::table('hades_agent_job_events')->insert([
        'id' => (string) Str::ulid(),
        'job_id' => $job,
        'event_type' => 'status',
        'status' => 'received',
        'payload' => json_encode(['reason' => null], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('hades_agent_artifacts')->insert([
        'id' => $artifact,
        'project_id' => $agent['project_id'],
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'job_id' => $job,
        'schema' => 'hades.git_tree.v1',
        'artifact' => json_encode(['schema' => 'hades.git_tree.v1', 'files' => []], JSON_THROW_ON_ERROR),
        'sha256' => str_repeat('a', 64),
        'truncated' => false,
        'redactions' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('hades_causal_packs')->insert([
        'id' => $causal,
        'project_id' => $agent['project_id'],
        'bug_report_id' => null,
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'pack_key' => hash('sha256', $causal),
        'bug_id' => null,
        'root_cause_id' => 'hardening-root-cause',
        'bug_class' => 'race_condition',
        'failure_classification' => 'confirmed',
        'affected_refs' => json_encode([], JSON_THROW_ON_ERROR),
        'freshness' => json_encode(['status' => 'current'], JSON_THROW_ON_ERROR),
        'awareness' => json_encode([], JSON_THROW_ON_ERROR),
        'evidence_refs' => json_encode([], JSON_THROW_ON_ERROR),
        'graph_refs' => json_encode([], JSON_THROW_ON_ERROR),
        'source_slice_refs' => json_encode([], JSON_THROW_ON_ERROR),
        'replay' => json_encode(['replayable' => true], JSON_THROW_ON_ERROR),
        'status' => 'valid',
        'blockers' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('hades_source_slice_candidates')->insert([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'candidate_key' => hash('sha256', 'hardening-candidate'),
        'path' => 'app/Test.php',
        'start_line' => 1,
        'end_line' => 2,
        'symbol' => 'Test',
        'reason' => 'regression',
        'priority' => 1,
        'head_commit' => null,
        'status' => 'pending',
        'job_id' => $job,
        'source_slice_id' => null,
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('project_memory_entries')->insert([
        'id' => $memory,
        'project_id' => $agent['project_id'],
        'repository_id' => null,
        'task_id' => null,
        'run_id' => null,
        'author_user_id' => null,
        'agent_key' => $agent['external_agent_id'],
        'source' => 'hades_diagnosis_report',
        'kind' => 'resolved_bug',
        'completeness' => 'complete',
        'summary' => 'Workspace-scoped resolved bug.',
        'payload' => json_encode(['workspace_binding_id' => $binding['workspace_binding_id']], JSON_THROW_ON_ERROR),
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('project_memory_links')->insert([
        'id' => (string) Str::ulid(),
        'memory_entry_id' => $memory,
        'target_type' => 'hades_diagnosis_report',
        'target_id' => (string) Str::ulid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    foreach ([
        ['workspace_binding_id' => $binding['workspace_binding_id'], 'source_table' => 'hades_agent_artifacts', 'source_id' => $artifact],
        ['workspace_binding_id' => null, 'source_table' => 'project_memory_entries', 'source_id' => $memory],
    ] as $document) {
        DB::table('hades_search_documents')->insert($document + [
            'id' => (string) Str::ulid(),
            'project_id' => $agent['project_id'],
            'domain' => 'artifacts',
            'kind' => 'artifact',
            'source_schema' => 'hades.git_tree.v1',
            'title' => 'Hardening document',
            'body' => 'private indexed body',
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'checksum' => str_repeat('b', 64),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $this->getJson('/api/hades/v1/privacy/export?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'include_content' => true,
    ]), hadesHardeningHeaders($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('counts.artifacts', 1)
        ->assertJsonPath('counts.causal_packs', 1)
        ->assertJsonPath('counts.search_documents', 1)
        ->assertJsonPath('counts.agent_jobs', 1)
        ->assertJsonPath('counts.agent_job_events', 1)
        ->assertJsonPath('counts.resolved_bug_memory', 1);

    $this->postJson('/api/hades/v1/privacy/delete', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'dry_run' => false,
        'confirm' => true,
    ], hadesHardeningHeaders($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('deleted.hades_agent_artifacts', 1)
        ->assertJsonPath('deleted.hades_causal_packs', 1)
        ->assertJsonPath('deleted.hades_agent_jobs', 1)
        ->assertJsonPath('deleted.project_memory_entries', 1);

    foreach (['hades_agent_jobs', 'hades_agent_job_events', 'hades_agent_artifacts', 'hades_causal_packs', 'hades_source_slice_candidates', 'hades_search_documents'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    expect(DB::table('project_memory_entries')->where('id', $memory)->exists())->toBeFalse()
        ->and(DB::table('project_memory_links')->where('memory_entry_id', $memory)->exists())->toBeFalse();
});

function hadesHardeningHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/** @param list<string> $declared @param list<string>|null $allowed */
function hadesHardeningAgent(array $declared, ?array $allowed = null): array
{
    $user = User::factory()->create(['status' => 'active']);
    $projectId = (string) Str::ulid();
    $now = now();
    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Hades Hardening Project',
        'slug' => 'hades-hardening-'.Str::lower(Str::random(8)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $tokenId = (string) Str::ulid();
    $secret = 'hades-hardening-bootstrap-secret';
    $prefix = 'hades_bootstrap_'.$tokenId;
    DB::table('hades_bootstrap_tokens')->insert([
        'id' => $tokenId,
        'project_id' => $projectId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'name' => 'Hardening bootstrap',
        'scopes' => json_encode(['hades.bootstrap'], JSON_THROW_ON_ERROR),
        'allowed_capabilities' => json_encode($allowed ?? $declared, JSON_THROW_ON_ERROR),
        'expires_at' => now()->addDay(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $externalId = 'hardening-agent-'.Str::lower(Str::random(8));
    $registered = test()->postJson('/api/hades/v1/agents/register', [
        'project_id' => $projectId,
        'agent_id' => $externalId,
        'label' => 'Hardening agent',
        'platform' => 'linux-x64',
        'version' => '1.0.0',
        'capabilities' => $declared,
    ], hadesHardeningHeaders($prefix.'|'.$secret))->assertOk();

    return [
        'project_id' => $projectId,
        'external_agent_id' => $externalId,
        'backend_agent_id' => $registered->json('backend_agent_id'),
        'agent_token' => $registered->json('agent_token'),
    ];
}

function hadesHardeningBinding(array $agent): array
{
    $bound = test()->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_fingerprint' => 'hardening-workspace-'.Str::lower(Str::random(10)),
        'display_path' => '~/Code/hardening',
        'head_commit' => str_repeat('a', 40),
    ], hadesHardeningHeaders($agent['agent_token']))->assertOk();

    return ['workspace_binding_id' => $bound->json('workspace_binding_id')];
}

function hadesHardeningJob(array $agent, array $binding, array $overrides = []): string
{
    $id = (string) Str::ulid();
    $now = now();
    DB::table('hades_agent_jobs')->insert(array_merge([
        'id' => $id,
        'project_id' => $agent['project_id'],
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'idempotency_key' => 'hardening-'.$id,
        'capability' => 'read_files',
        'job_type' => null,
        'status' => 'queued',
        'policy' => 'auto',
        'priority' => 'normal',
        'payload' => json_encode(['paths' => ['README.md']], JSON_THROW_ON_ERROR),
        'result' => null,
        'requires_confirmation' => false,
        'deadline_at' => null,
        'available_at' => null,
        'claimed_at' => null,
        'started_at' => null,
        'completed_at' => null,
        'failed_at' => null,
        'cancelled_at' => null,
        'result_applied_at' => null,
        'error_code' => null,
        'error_message' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return $id;
}

function hadesHardeningStatusPayload(array $agent, array $binding, string $status): array
{
    return [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => $status,
    ];
}
