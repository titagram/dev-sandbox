<?php

use App\Models\User;
use App\Services\Hades\HadesSourceSliceCandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => Bus::fake());

it('creates deduped read source slice jobs from artifact candidates', function () {
    [$projectId, $agent, $token, $workspaceBindingId] = hadesSourceCandidateAgent();

    $candidate = [
        'candidate_key' => hash('sha256', 'abc123|app/Http/Controllers/BookingController.php|1|25|BookingController|laravel_controller'),
        'path' => 'app/Http/Controllers/BookingController.php',
        'start_line' => 1,
        'end_line' => 25,
        'symbol' => 'BookingController',
        'reason' => 'laravel_controller',
        'priority' => 10,
        'head_commit' => str_repeat('a', 40),
        'raw_source_included' => false,
        'retention_class' => 'source_slice_candidate',
    ];

    $payload = [
        'project_id' => $projectId,
        'workspace_binding_id' => $workspaceBindingId,
        'schema' => 'hades.php_graph.v1',
        'artifact' => ['schema' => 'hades.php_graph.v1', 'source_slice_candidates' => [$candidate]],
        'sha256' => hash('sha256', json_encode(['source_slice_candidates' => [$candidate], 'routes' => []], JSON_THROW_ON_ERROR)),
    ];

    $this
        ->postJson('/api/hades/v1/artifacts', $payload, hadesSourceCandidateHeaders($token))
        ->assertCreated();

    $this
        ->postJson('/api/hades/v1/artifacts', $payload, hadesSourceCandidateHeaders($token))
        ->assertOk();

    expect(DB::table('hades_source_slice_candidates')->where('workspace_binding_id', $workspaceBindingId)->count())->toBe(1);
    expect(DB::table('hades_agent_jobs')->where('workspace_binding_id', $workspaceBindingId)->where('capability', 'read_source_slice')->count())->toBe(1);

    $job = DB::table('hades_agent_jobs')->where('workspace_binding_id', $workspaceBindingId)->where('capability', 'read_source_slice')->first();
    $jobPayload = json_decode($job->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($jobPayload['path'])->toBe('app/Http/Controllers/BookingController.php');
    expect($jobPayload['start_line'])->toBe(1);
    expect($jobPayload['end_line'])->toBe(25);
    expect($jobPayload['symbol'])->toBe('BookingController');
    expect($jobPayload['candidate_key'])->toBe($candidate['candidate_key']);
    expect($job->policy)->toBe('confirm');
    expect((bool) $job->requires_confirmation)->toBeTrue();

    $this
        ->postJson('/api/hades/v1/source-slices', [
            'project_id' => $projectId,
            'workspace_binding_id' => $workspaceBindingId,
            'job_id' => $job->id,
            'path' => 'app/Http/Controllers/BookingController.php',
            'start_line' => 1,
            'end_line' => 25,
            'language' => 'php',
            'symbol' => 'BookingController',
            'head_commit' => str_repeat('a', 40),
            'content_redacted' => "<?php\nclass BookingController {}\n",
            'redactions' => 0,
            'candidate_key' => $candidate['candidate_key'],
        ], hadesSourceCandidateHeaders($token))
        ->assertCreated();

    $candidateRow = DB::table('hades_source_slice_candidates')
        ->where('workspace_binding_id', $workspaceBindingId)
        ->where('candidate_key', $candidate['candidate_key'])
        ->first();
    expect($candidateRow->status)->toBe('slice_uploaded');
    expect($candidateRow->source_slice_id)->toBeString();
});

it('does not reset a terminal candidate during direct service ingestion', function () {
    [$projectId, $agent, $token, $workspaceBindingId] = hadesSourceCandidateAgent();
    $candidate = [
        'candidate_key' => hash('sha256', 'terminal-direct-ingest'),
        'path' => 'app/Http/Controllers/BookingController.php',
        'start_line' => 1,
        'end_line' => 25,
        'symbol' => 'BookingController',
        'reason' => 'laravel_controller',
        'priority' => 10,
        'head_commit' => str_repeat('a', 40),
    ];
    $agentRow = DB::table('hades_agents')->where('id', $agent['backend_agent_id'])->first();
    $bindingRow = DB::table('hades_workspace_bindings')->where('id', $workspaceBindingId)->first();
    $service = app(HadesSourceSliceCandidateService::class);
    $artifact = ['source_slice_candidates' => [$candidate]];

    expect($service->ingestArtifactCandidates($agentRow, $bindingRow, $artifact, str_repeat('a', 40)))
        ->toMatchArray(['candidates' => 1, 'jobs_created' => 1]);

    $jobId = DB::table('hades_source_slice_candidates')
        ->where('workspace_binding_id', $workspaceBindingId)
        ->where('candidate_key', $candidate['candidate_key'])
        ->value('job_id');
    $createdAt = DB::table('hades_source_slice_candidates')
        ->where('workspace_binding_id', $workspaceBindingId)
        ->where('candidate_key', $candidate['candidate_key'])
        ->value('created_at');
    DB::table('hades_source_slice_candidates')
        ->where('workspace_binding_id', $workspaceBindingId)
        ->where('candidate_key', $candidate['candidate_key'])
        ->update(['status' => 'rejected']);

    $changedCandidate = array_merge($candidate, [
        'path' => 'app/Http/Controllers/ChangedController.php',
        'head_commit' => str_repeat('b', 40),
    ]);
    expect($service->ingestArtifactCandidates($agentRow, $bindingRow, ['source_slice_candidates' => [$changedCandidate]], str_repeat('b', 40)))
        ->toMatchArray(['candidates' => 1, 'jobs_created' => 0]);

    $stored = DB::table('hades_source_slice_candidates')
        ->where('workspace_binding_id', $workspaceBindingId)
        ->where('candidate_key', $candidate['candidate_key'])
        ->first();
    expect($stored->status)->toBe('rejected')
        ->and($stored->job_id)->toBe($jobId)
        ->and($stored->path)->toBe($candidate['path'])
        ->and($stored->head_commit)->toBe($candidate['head_commit'])
        ->and($stored->created_at)->toBe($createdAt);
});

it('keeps direct duplicate ingestion at one candidate and one job', function () {
    [$projectId, $agent, $token, $workspaceBindingId] = hadesSourceCandidateAgent();
    $candidate = [
        'candidate_key' => hash('sha256', 'direct-duplicate-ingest'),
        'path' => 'app/Http/Controllers/BookingController.php',
        'start_line' => 1,
        'end_line' => 25,
        'symbol' => 'BookingController',
        'reason' => 'laravel_controller',
        'priority' => 10,
        'head_commit' => str_repeat('a', 40),
    ];
    $agentRow = DB::table('hades_agents')->where('id', $agent['backend_agent_id'])->first();
    $bindingRow = DB::table('hades_workspace_bindings')->where('id', $workspaceBindingId)->first();
    $service = app(HadesSourceSliceCandidateService::class);
    $artifact = ['source_slice_candidates' => [$candidate]];

    $first = $service->ingestArtifactCandidates($agentRow, $bindingRow, $artifact, str_repeat('a', 40));
    $second = $service->ingestArtifactCandidates($agentRow, $bindingRow, $artifact, str_repeat('a', 40));

    expect($first)->toMatchArray(['candidates' => 1, 'jobs_created' => 1])
        ->and($second)->toMatchArray(['candidates' => 1, 'jobs_created' => 0])
        ->and(DB::table('hades_source_slice_candidates')->where('workspace_binding_id', $workspaceBindingId)->where('candidate_key', $candidate['candidate_key'])->count())->toBe(1)
        ->and(DB::table('hades_agent_jobs')->where('workspace_binding_id', $workspaceBindingId)->where('capability', 'read_source_slice')->count())->toBe(1);
});

it('reports pending source slice candidates in project awareness', function () {
    [$projectId, $agent, $token, $workspaceBindingId] = hadesSourceCandidateAgent();

    $candidate = [
        'candidate_key' => hash('sha256', 'abc123|app/Http/Controllers/BookingController.php|1|25|BookingController|laravel_controller'),
        'path' => 'app/Http/Controllers/BookingController.php',
        'start_line' => 1,
        'end_line' => 25,
        'symbol' => 'BookingController',
        'reason' => 'laravel_controller',
        'priority' => 10,
        'head_commit' => str_repeat('a', 40),
        'raw_source_included' => false,
        'retention_class' => 'source_slice_candidate',
    ];

    $this
        ->postJson('/api/hades/v1/artifacts', [
            'project_id' => $projectId,
            'workspace_binding_id' => $workspaceBindingId,
            'schema' => 'hades.php_graph.v1',
            'artifact' => ['schema' => 'hades.php_graph.v1', 'source_slice_candidates' => [$candidate]],
            'sha256' => hash('sha256', json_encode(['source_slice_candidates' => [$candidate], 'routes' => []], JSON_THROW_ON_ERROR)),
        ], hadesSourceCandidateHeaders($token))
        ->assertCreated();

    $status = $this->getJson('/api/hades/v1/project-awareness/status?'.http_build_query([
        'project_id' => $projectId,
        'workspace_binding_id' => $workspaceBindingId,
    ]), hadesSourceCandidateHeaders($token))
        ->assertOk()
        ->assertJsonPath('coverage.source_slice_candidates.status', 'pending')
        ->assertJsonPath('coverage.source_slice_candidates.count', 1)
        ->assertJsonPath('coverage.source_slice_candidates.pending_candidates', 0)
        ->assertJsonPath('coverage.source_slice_candidates.waiting_jobs', 1)
        ->assertJsonPath('coverage.source_slices.status', 'missing')
        ->assertJsonPath('diagnosable_without_source', false)
        ->json();

    expect($status['actions'])->toContain('Approve pending source-slice jobs before precise source-free diagnosis.');
});

it('keeps a source slice candidate pending without creating a job when the agent lacks the capability', function () {
    [$projectId, $agent, $token, $workspaceBindingId] = hadesSourceCandidateAgent(false);
    $candidate = [
        'candidate_key' => hash('sha256', 'missing-read-source-slice'),
        'path' => 'app/Http/Controllers/BookingController.php',
        'start_line' => 1,
        'end_line' => 25,
        'symbol' => 'BookingController',
        'reason' => 'laravel_controller',
        'priority' => 10,
        'head_commit' => str_repeat('a', 40),
    ];

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $projectId,
        'workspace_binding_id' => $workspaceBindingId,
        'schema' => 'hades.php_graph.v1',
        'artifact' => ['schema' => 'hades.php_graph.v1', 'source_slice_candidates' => [$candidate]],
        'sha256' => hash('sha256', json_encode(['source_slice_candidates' => [$candidate], 'routes' => []], JSON_THROW_ON_ERROR)),
    ], hadesSourceCandidateHeaders($token))->assertCreated();

    $stored = DB::table('hades_source_slice_candidates')
        ->where('workspace_binding_id', $workspaceBindingId)
        ->where('candidate_key', $candidate['candidate_key'])
        ->first();

    expect($stored->status)->toBe('pending')
        ->and($stored->job_id)->toBeNull()
        ->and(DB::table('hades_agent_jobs')->where('workspace_binding_id', $workspaceBindingId)->where('capability', 'read_source_slice')->count())->toBe(0);

    $status = $this->getJson('/api/hades/v1/project-awareness/status?'.http_build_query([
        'project_id' => $projectId,
        'workspace_binding_id' => $workspaceBindingId,
    ]), hadesSourceCandidateHeaders($token))
        ->assertOk()
        ->assertJsonPath('coverage.source_slice_candidates.status', 'pending')
        ->assertJsonPath('coverage.source_slice_candidates.pending_candidates', 1)
        ->assertJsonPath('coverage.source_slice_candidates.waiting_jobs', 0)
        ->json();
    expect($status['actions'])->toContain('Poll for pending source-slice candidates with an agent that has read_source_slice enabled.');
});

it('does not create a source slice job during re-registration', function () {
    [$projectId, $agent, $token, $workspaceBindingId, $bootstrapToken] = hadesSourceCandidateAgent(false);
    $candidate = [
        'candidate_key' => hash('sha256', 'grant-later-read-source-slice'),
        'path' => 'app/Http/Controllers/BookingController.php',
        'start_line' => 1,
        'end_line' => 25,
        'symbol' => 'BookingController',
        'reason' => 'laravel_controller',
        'priority' => 10,
        'head_commit' => str_repeat('a', 40),
    ];

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $projectId,
        'workspace_binding_id' => $workspaceBindingId,
        'schema' => 'hades.php_graph.v1',
        'artifact' => ['schema' => 'hades.php_graph.v1', 'source_slice_candidates' => [$candidate]],
        'sha256' => hash('sha256', json_encode(['source_slice_candidates' => [$candidate], 'routes' => []], JSON_THROW_ON_ERROR)),
    ], hadesSourceCandidateHeaders($token))->assertCreated();

    $this->postJson('/api/hades/v1/agents/register', [
        'project_id' => $projectId,
        'agent_id' => $agent['external_agent_id'],
        'label' => 'Hades Source Candidate Agent',
        'platform' => 'linux-x64',
        'version' => '0.4.0',
        'capabilities' => ['read_files', 'read_source_slice', 'sync_git_tree', 'populate_backend_ast'],
    ], hadesSourceCandidateHeaders($bootstrapToken))->assertOk();

    expect(DB::table('hades_agent_jobs')->where('workspace_binding_id', $workspaceBindingId)->where('capability', 'read_source_slice')->count())->toBe(0)
        ->and(DB::table('hades_source_slice_candidates')->where('workspace_binding_id', $workspaceBindingId)->where('candidate_key', $candidate['candidate_key'])->value('status'))->toBe('pending');

    $this->postJson('/api/hades/v1/agents/register', [
        'project_id' => $projectId,
        'agent_id' => $agent['external_agent_id'],
        'label' => 'Hades Source Candidate Agent',
        'platform' => 'linux-x64',
        'version' => '0.5.0',
        'capabilities' => ['read_files', 'read_source_slice', 'sync_git_tree', 'populate_backend_ast'],
    ], hadesSourceCandidateHeaders($bootstrapToken))->assertOk();

    expect(DB::table('hades_agent_jobs')->where('workspace_binding_id', $workspaceBindingId)->where('capability', 'read_source_slice')->count())->toBe(0)
        ->and(DB::table('hades_source_slice_candidates')->where('workspace_binding_id', $workspaceBindingId)->where('candidate_key', $candidate['candidate_key'])->value('status'))->toBe('pending');
});

it('reconciles pending candidates on a bounded job poll and does not duplicate on retry', function () {
    [$projectId, $agent, $token, $workspaceBindingId, $bootstrapToken] = hadesSourceCandidateAgent(false);
    $candidate = [
        'candidate_key' => hash('sha256', 'poll-read-source-slice'),
        'path' => 'app/Http/Controllers/BookingController.php',
        'start_line' => 1,
        'end_line' => 25,
        'symbol' => 'BookingController',
        'reason' => 'laravel_controller',
        'priority' => 10,
        'head_commit' => str_repeat('a', 40),
    ];

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $projectId,
        'workspace_binding_id' => $workspaceBindingId,
        'schema' => 'hades.php_graph.v1',
        'artifact' => ['schema' => 'hades.php_graph.v1', 'source_slice_candidates' => [$candidate]],
        'sha256' => hash('sha256', json_encode(['source_slice_candidates' => [$candidate], 'routes' => []], JSON_THROW_ON_ERROR)),
    ], hadesSourceCandidateHeaders($token))->assertCreated();

    $registered = $this->postJson('/api/hades/v1/agents/register', [
        'project_id' => $projectId,
        'agent_id' => $agent['external_agent_id'],
        'label' => 'Hades Source Candidate Agent',
        'platform' => 'linux-x64',
        'version' => '0.4.0',
        'capabilities' => ['read_files', 'read_source_slice', 'sync_git_tree', 'populate_backend_ast'],
    ], hadesSourceCandidateHeaders($bootstrapToken))->assertOk();
    $pollHeaders = hadesSourceCandidateHeaders($registered->json('agent_token'));
    expect(DB::table('hades_agent_jobs')->where('workspace_binding_id', $workspaceBindingId)->where('capability', 'read_source_slice')->count())->toBe(0);
    $query = http_build_query([
        'project_id' => $projectId,
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $workspaceBindingId,
        'capabilities' => ['read_source_slice'],
        'limit' => 25,
    ]);

    $first = $this->getJson('/api/hades/v1/agent/jobs?'.$query, $pollHeaders)
        ->assertOk()
        ->json();
    expect($first['jobs'])->toHaveCount(1);

    $jobId = $first['jobs'][0]['job_id'];
    expect(DB::table('hades_agent_jobs')->where('workspace_binding_id', $workspaceBindingId)->where('capability', 'read_source_slice')->count())->toBe(1)
        ->and(DB::table('hades_source_slice_candidates')->where('workspace_binding_id', $workspaceBindingId)->where('candidate_key', $candidate['candidate_key'])->value('status'))->toBe('job_created');

    $second = $this->getJson('/api/hades/v1/agent/jobs?'.$query, $pollHeaders)
        ->assertOk()
        ->json();
    expect($second['jobs'])->toHaveCount(1)
        ->and($second['jobs'][0]['job_id'])->toBe($jobId)
        ->and(DB::table('hades_agent_jobs')->where('workspace_binding_id', $workspaceBindingId)->where('capability', 'read_source_slice')->count())->toBe(1);
});

it('does not link unrelated jobs with the same candidate idempotency key', function () {
    [$projectId, $agent, $token, $workspaceBindingId, $bootstrapToken] = hadesSourceCandidateAgent(false);
    $candidate = [
        'candidate_key' => hash('sha256', 'poll-scope-and-capability'),
        'path' => 'app/Http/Controllers/BookingController.php',
        'start_line' => 1,
        'end_line' => 25,
        'symbol' => 'BookingController',
        'reason' => 'laravel_controller',
        'priority' => 10,
        'head_commit' => str_repeat('a', 40),
    ];

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $projectId,
        'workspace_binding_id' => $workspaceBindingId,
        'schema' => 'hades.php_graph.v1',
        'artifact' => ['schema' => 'hades.php_graph.v1', 'source_slice_candidates' => [$candidate]],
        'sha256' => hash('sha256', json_encode(['source_slice_candidates' => [$candidate], 'routes' => []], JSON_THROW_ON_ERROR)),
    ], hadesSourceCandidateHeaders($token))->assertCreated();

    $registered = $this->postJson('/api/hades/v1/agents/register', [
        'project_id' => $projectId,
        'agent_id' => $agent['external_agent_id'],
        'label' => 'Hades Source Candidate Agent',
        'platform' => 'linux-x64',
        'version' => '0.4.0',
        'capabilities' => ['read_files', 'read_source_slice', 'sync_git_tree', 'populate_backend_ast'],
    ], hadesSourceCandidateHeaders($bootstrapToken))->assertOk();
    expect(DB::table('hades_agent_jobs')->where('workspace_binding_id', $workspaceBindingId)->where('capability', 'read_source_slice')->count())->toBe(0);

    $otherBindingId = $this->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $projectId,
        'agent_id' => $agent['external_agent_id'],
        'workspace_fingerprint' => 'wf_other_'.Str::lower(Str::random(8)),
        'display_path' => '~/Code/other',
        'head_commit' => str_repeat('b', 40),
    ], hadesSourceCandidateHeaders($registered->json('agent_token')))->assertOk()->json('workspace_binding_id');

    $idempotencyKey = 'source_slice_candidate:'.$workspaceBindingId.':'.$candidate['candidate_key'];
    foreach ([[$otherBindingId, 'read_source_slice'], [$workspaceBindingId, 'read_files']] as [$unrelatedBindingId, $capability]) {
        $now = now();
        DB::table('hades_agent_jobs')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'hades_agent_id' => $agent['backend_agent_id'],
            'workspace_binding_id' => $unrelatedBindingId,
            'idempotency_key' => $idempotencyKey,
            'capability' => $capability,
            'status' => 'queued',
            'policy' => 'auto',
            'priority' => 'normal',
            'payload' => json_encode([], JSON_THROW_ON_ERROR),
            'result' => null,
            'requires_confirmation' => false,
            'deadline_at' => $now->copy()->addDays(7),
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $this->getJson('/api/hades/v1/agent/jobs?'.http_build_query([
        'project_id' => $projectId,
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $workspaceBindingId,
        'capabilities' => ['read_source_slice'],
        'limit' => 25,
    ]), hadesSourceCandidateHeaders($registered->json('agent_token')))
        ->assertOk();

    $candidateRow = DB::table('hades_source_slice_candidates')->where('workspace_binding_id', $workspaceBindingId)->where('candidate_key', $candidate['candidate_key'])->first();
    expect($candidateRow->status)->toBe('job_created')
        ->and($candidateRow->job_id)->not->toBeNull()
        ->and(DB::table('hades_agent_jobs')->where('id', $candidateRow->job_id)->where('workspace_binding_id', $workspaceBindingId)->where('capability', 'read_source_slice')->count())->toBe(1);
});

it('makes bounded progress across pending source slice candidates per job poll limit', function () {
    [$projectId, $agent, $token, $workspaceBindingId, $bootstrapToken] = hadesSourceCandidateAgent(false);
    $candidates = collect(range(1, 3))->map(fn (int $number): array => [
        'candidate_key' => hash('sha256', 'bounded-poll-'.$number),
        'path' => 'app/Http/Controllers/BookingController'.$number.'.php',
        'start_line' => 1,
        'end_line' => 5,
        'symbol' => 'BookingController'.$number,
        'reason' => 'laravel_controller',
        'priority' => 10,
        'head_commit' => str_repeat('a', 40),
    ])->all();

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $projectId,
        'workspace_binding_id' => $workspaceBindingId,
        'schema' => 'hades.php_graph.v1',
        'artifact' => ['schema' => 'hades.php_graph.v1', 'source_slice_candidates' => $candidates],
        'sha256' => hash('sha256', json_encode(['source_slice_candidates' => $candidates, 'routes' => []], JSON_THROW_ON_ERROR)),
    ], hadesSourceCandidateHeaders($token))->assertCreated();

    $registered = $this->postJson('/api/hades/v1/agents/register', [
        'project_id' => $projectId,
        'agent_id' => $agent['external_agent_id'],
        'label' => 'Hades Source Candidate Agent',
        'platform' => 'linux-x64',
        'version' => '0.4.0',
        'capabilities' => ['read_files', 'read_source_slice', 'sync_git_tree', 'populate_backend_ast'],
    ], hadesSourceCandidateHeaders($bootstrapToken))->assertOk();
    expect(DB::table('hades_agent_jobs')->where('workspace_binding_id', $workspaceBindingId)->where('capability', 'read_source_slice')->count())->toBe(0);
    $query = http_build_query([
        'project_id' => $projectId,
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $workspaceBindingId,
        'capabilities' => ['read_source_slice'],
        'limit' => 1,
    ]);

    $this->getJson('/api/hades/v1/agent/jobs?'.$query, hadesSourceCandidateHeaders($registered->json('agent_token')))->assertOk();
    expect(DB::table('hades_agent_jobs')->where('workspace_binding_id', $workspaceBindingId)->where('capability', 'read_source_slice')->count())->toBe(1);

    $this->getJson('/api/hades/v1/agent/jobs?'.$query, hadesSourceCandidateHeaders($registered->json('agent_token')))->assertOk();
    expect(DB::table('hades_agent_jobs')->where('workspace_binding_id', $workspaceBindingId)->where('capability', 'read_source_slice')->count())->toBe(2);
});

/**
 * @return array{0: string, 1: array<string, string>, 2: string, 3: string, 4: string}
 */
function hadesSourceCandidateAgent(bool $includeReadSourceSlice = true): array
{
    $projectId = hadesSourceCandidateProjectId();
    $bootstrapToken = hadesSourceCandidateBootstrapToken($projectId);
    $externalAgentId = 'local-agent-'.Str::lower(Str::random(8));

    $capabilities = ['read_files', 'sync_git_tree', 'populate_backend_ast'];
    if ($includeReadSourceSlice) {
        $capabilities[] = 'read_source_slice';
    }

    $registered = test()->postJson('/api/hades/v1/agents/register', [
        'project_id' => $projectId,
        'agent_id' => $externalAgentId,
        'label' => 'Hades Source Candidate Agent',
        'platform' => 'linux-x64',
        'version' => '0.3.0',
        'capabilities' => $capabilities,
    ], hadesSourceCandidateHeaders($bootstrapToken))->assertOk();

    $agent = [
        'project_id' => $projectId,
        'external_agent_id' => $externalAgentId,
        'backend_agent_id' => $registered->json('backend_agent_id'),
        'agent_token' => $registered->json('agent_token'),
    ];

    $bound = test()->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $projectId,
        'agent_id' => $externalAgentId,
        'workspace_fingerprint' => 'wf_hades_source_candidate_'.Str::lower(Str::random(8)),
        'display_path' => '~/Code/hades-source-candidate',
        'git_remote_display' => 'github.com/acme/hades-source-candidate.git',
        'git_remote_hash' => hash('sha256', 'git@github.com:acme/hades-source-candidate.git'),
        'head_commit' => str_repeat('a', 40),
    ], hadesSourceCandidateHeaders($agent['agent_token']))->assertOk();

    return [$projectId, $agent, $agent['agent_token'], $bound->json('workspace_binding_id'), $bootstrapToken];
}

function hadesSourceCandidateProjectId(): string
{
    $user = User::factory()->create(['status' => 'active']);
    $id = (string) Str::ulid();
    $now = now();

    DB::table('projects')->insert([
        'id' => $id,
        'name' => 'Hades Source Candidate Test Project',
        'slug' => 'hades-source-candidate-test-'.Str::lower(Str::random(8)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function hadesSourceCandidateBootstrapToken(string $projectId): string
{
    $id = (string) Str::ulid();
    $secret = 'hades-source-candidate-bootstrap-test-secret';
    $prefix = 'hades_bootstrap_'.$id;
    $now = now();

    DB::table('hades_bootstrap_tokens')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'name' => 'Hades Source Candidate Bootstrap Token',
        'scopes' => json_encode(['hades.bootstrap'], JSON_THROW_ON_ERROR),
        'allowed_capabilities' => json_encode(['read_files', 'read_source_slice', 'sync_git_tree', 'populate_backend_ast'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $prefix.'|'.$secret;
}

function hadesSourceCandidateHeaders(?string $token = null): array
{
    return $token === null ? [] : ['Authorization' => 'Bearer '.$token];
}
