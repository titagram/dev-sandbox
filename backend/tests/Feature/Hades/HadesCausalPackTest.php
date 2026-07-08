<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates lists shows and replays a valid causal evidence pack', function () {
    $agent = hadesCausalPackRegisteredAgent();
    $binding = hadesCausalPackWorkspaceBinding($agent);
    $headers = hadesCausalPackHeaders($agent['agent_token']);
    $projectId = $agent['project_id'];
    $bindingId = $binding['workspace_binding_id'];
    $head = str_repeat('a', 40);

    $artifactId = $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $projectId,
        'workspace_binding_id' => $bindingId,
        'schema' => 'hades.php_graph.v1',
        'artifact' => [
            'schema' => 'hades.php_graph.v1',
            'head_commit' => $head,
            'routes' => [['name' => 'bookings.store', 'handler' => 'BookingController@store']],
            'symbols' => [['name' => 'BookingController@store', 'path' => 'app/Http/Controllers/BookingController.php', 'line' => 41]],
            'edges' => [['kind' => 'handles', 'from' => 'route:bookings.store', 'to' => 'BookingController@store']],
        ],
        'truncated' => false,
        'redactions' => 0,
    ], $headers)->assertCreated()->json('artifact.id');

    $bugReportId = $this->postJson('/api/hades/v1/bug-reports', [
        'project_id' => $projectId,
        'workspace_binding_id' => $bindingId,
        'title' => 'Booking overlap',
        'symptom' => 'Booking form accepts overlapping reservations.',
        'severity' => 'high',
        'environment' => ['deploy_commit' => $head],
    ], $headers)->assertCreated()->json('bug_report.id');

    $evidenceId = $this->postJson('/api/hades/v1/bug-evidence', [
        'project_id' => $projectId,
        'workspace_binding_id' => $bindingId,
        'bug_report_id' => $bugReportId,
        'kind' => 'failing_test',
        'summary' => 'Feature test shows missing overlap validation in BookingController@store.',
        'payload' => ['schema' => 'hades.test_output.v1', 'frame_refs' => ['BookingController@store']],
        'source' => 'phpunit',
        'redactions' => 0,
        'retention_class' => 'test_failure',
    ], $headers)->assertCreated()->json('evidence.id');

    $sourceSliceId = $this->postJson('/api/hades/v1/source-slices', [
        'project_id' => $projectId,
        'workspace_binding_id' => $bindingId,
        'path' => 'app/Http/Controllers/BookingController.php',
        'start_line' => 41,
        'end_line' => 45,
        'language' => 'php',
        'symbol' => 'BookingController@store',
        'head_commit' => $head,
        'content_redacted' => "41: public function store(Request \$request)\n42: \$data = \$request->validate(['starts_at' => 'required']);\n43: Booking::create(\$data);\n44: return redirect()->route('bookings.index');\n45: }",
        'redactions' => 0,
        'truncated' => false,
        'retention_class' => 'source_slice',
        'policy' => 'manual_review',
    ], $headers)->assertCreated()->json('source_slice.id');

    $create = $this->postJson('/api/hades/v1/causal-packs', [
        'project_id' => $projectId,
        'workspace_binding_id' => $bindingId,
        'bug_report_id' => $bugReportId,
        'bug_id' => 'bug_booking_overlap',
        'root_cause_id' => 'booking-overlap-validation-gap',
        'bug_class' => 'validation',
        'failure_classification' => 'confirmed',
        'affected_refs' => ['symbol:BookingController@store', 'route:bookings.store'],
        'freshness' => ['status' => 'current', 'head_commit' => $head],
        'awareness' => ['diagnosable_without_source' => true],
        'evidence_refs' => [['type' => 'bug_evidence', 'id' => $evidenceId]],
        'graph_refs' => [['type' => 'artifact', 'id' => $artifactId, 'ref' => 'route:bookings.store']],
        'source_slice_refs' => [['type' => 'source_slice', 'id' => $sourceSliceId]],
    ], $headers);

    $packId = $create->assertCreated()
        ->assertJsonPath('causal_pack.status', 'valid')
        ->assertJsonPath('causal_pack.root_cause_id', 'booking-overlap-validation-gap')
        ->assertJsonPath('causal_pack.replay.required_refs.0.type', 'bug_evidence')
        ->json('causal_pack.id');

    $this->getJson('/api/hades/v1/causal-packs?'.http_build_query([
        'project_id' => $projectId,
        'workspace_binding_id' => $bindingId,
        'query' => 'booking overlap validation',
    ]), $headers)
        ->assertOk()
        ->assertJsonPath('items.0.id', $packId);

    $this->getJson('/api/hades/v1/causal-packs/'.$packId.'?'.http_build_query([
        'project_id' => $projectId,
        'workspace_binding_id' => $bindingId,
    ]), $headers)
        ->assertOk()
        ->assertJsonPath('causal_pack.id', $packId);

    $this->postJson('/api/hades/v1/causal-packs/'.$packId.'/replay', [
        'project_id' => $projectId,
        'workspace_binding_id' => $bindingId,
    ], $headers)
        ->assertOk()
        ->assertJsonPath('replay.replayable', true)
        ->assertJsonPath('replay.missing_refs', []);

    expect(DB::table('hades_search_documents')
        ->where('domain', 'causal_packs')
        ->where('source_table', 'hades_causal_packs')
        ->where('source_id', $packId)
        ->where('body', 'like', '%booking-overlap-validation-gap%')
        ->exists())->toBeTrue();
});

it('stores invalid causal packs with explicit replay blockers', function () {
    $agent = hadesCausalPackRegisteredAgent();
    $binding = hadesCausalPackWorkspaceBinding($agent);
    $headers = hadesCausalPackHeaders($agent['agent_token']);

    $create = $this->postJson('/api/hades/v1/causal-packs', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'bug_id' => 'bug_without_slice',
        'root_cause_id' => 'booking-overlap-validation-gap',
        'bug_class' => 'validation',
        'failure_classification' => 'confirmed',
        'affected_refs' => ['symbol:BookingController@store'],
        'freshness' => ['status' => 'stale', 'head_commit' => str_repeat('b', 40)],
        'awareness' => ['diagnosable_without_source' => true],
        'evidence_refs' => ['bug_evidence:booking_log'],
        'graph_refs' => ['symbol:BookingController@store'],
        'source_slice_refs' => [],
    ], $headers);

    $create->assertCreated()
        ->assertJsonPath('causal_pack.status', 'invalid')
        ->assertJsonPath('causal_pack.blockers.0', 'freshness_not_current')
        ->assertJsonPath('causal_pack.blockers.1', 'source_slice_refs_required');
});

function hadesCausalPackHeaders(?string $token = null): array
{
    return $token === null ? [] : ['Authorization' => 'Bearer '.$token];
}

function hadesCausalPackProject(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $id = (string) Str::ulid();
    $now = now();

    DB::table('projects')->insert([
        'id' => $id,
        'name' => 'Hades Causal Pack Test Project',
        'slug' => 'hades-causal-pack-test-'.Str::lower(Str::random(8)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'selected_source_slices',
        'created_by_user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['id' => $id, 'user_id' => $user->id];
}

function hadesCausalPackBootstrapToken(?string $projectId = null): array
{
    $projectId = $projectId ?: hadesCausalPackProject()['id'];
    $id = (string) Str::ulid();
    $secret = 'hades-causal-pack-bootstrap-test-secret';
    $prefix = 'hades_bootstrap_'.$id;
    $now = now();

    DB::table('hades_bootstrap_tokens')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'name' => 'Hades Causal Pack Bootstrap Token',
        'scopes' => json_encode(['hades.bootstrap'], JSON_THROW_ON_ERROR),
        'allowed_capabilities' => json_encode(['read_files', 'read_source_slice', 'sync_git_tree', 'populate_backend_ast'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['id' => $id, 'plain_token' => $prefix.'|'.$secret, 'project_id' => $projectId];
}

function hadesCausalPackRegisteredAgent(): array
{
    $bootstrap = hadesCausalPackBootstrapToken();
    $externalAgentId = 'causal-agent-'.Str::lower(Str::random(8));
    $test = test();

    $registered = $test->postJson('/api/hades/v1/agents/register', [
        'project_id' => $bootstrap['project_id'],
        'agent_id' => $externalAgentId,
        'label' => 'Hades Causal Pack Agent',
        'platform' => 'linux-x64',
        'version' => '0.4.0',
        'capabilities' => ['read_files', 'read_source_slice', 'sync_git_tree', 'populate_backend_ast'],
    ], hadesCausalPackHeaders($bootstrap['plain_token']))->assertOk();

    return [
        'project_id' => $bootstrap['project_id'],
        'external_agent_id' => $externalAgentId,
        'backend_agent_id' => $registered->json('backend_agent_id'),
        'agent_token' => $registered->json('agent_token'),
    ];
}

function hadesCausalPackWorkspaceBinding(array $agent): array
{
    $test = test();
    $bound = $test->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_fingerprint' => 'wf_hades_causal_pack_'.Str::lower(Str::random(8)),
        'display_path' => '~/Code/hades-causal-pack-fixture',
        'git_remote_display' => 'github.com/acme/hades-causal-pack.git',
        'git_remote_hash' => hash('sha256', 'git@github.com:acme/hades-causal-pack.git'),
        'head_commit' => str_repeat('a', 40),
    ], hadesCausalPackHeaders($agent['agent_token']))->assertOk();

    return ['workspace_binding_id' => $bound->json('workspace_binding_id')];
}
