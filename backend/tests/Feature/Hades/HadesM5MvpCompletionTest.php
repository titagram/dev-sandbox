<?php

use App\Models\User;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DevBoardSeeder::class);
});

it('lets admins provision and revoke project scoped Hades bootstrap tokens with installer commands', function () {
    $admin = hadesM5DashboardUserWithRole('Admin');
    $project = hadesM5Project($admin);

    $snapshot = $this->actingAs($admin)
        ->getJson('/api/dashboard/admin/hades')
        ->assertOk()
        ->assertJsonStructure(['projects', 'bootstrapTokens', 'workspaces', 'jobs', 'memoryProposals'])
        ->json();

    expect(collect($snapshot['projects'])->pluck('id')->all())->toContain($project['id']);

    $response = $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/bootstrap-tokens', [
            'project_id' => $project['id'],
            'name' => 'Gabriele laptop bootstrap',
            'expires_in_days' => 30,
            'base_url' => 'https://backend.example.test',
            'project_name' => 'Hermes Dev',
        ])
        ->assertCreated()
        ->assertJsonPath('token.name', 'Gabriele laptop bootstrap')
        ->assertJsonPath('token.project_id', $project['id']);

    $plainToken = $response->json('plain_token');
    expect($plainToken)->toStartWith('hades_bootstrap_')
        ->and($response->json('install.posix'))->toContain('install.sh')
        ->and($response->json('install.posix'))->toContain('--backend-project-id '.$project['id'])
        ->and($response->json('install.posix'))->toContain('--backend-project-token')
        ->and($response->json('install.windows'))->toContain('install.ps1')
        ->and($response->json('install.windows'))->toContain('-BackendProjectId '.$project['id']);

    $storedHash = DB::table('hades_bootstrap_tokens')->where('id', $response->json('token.id'))->value('token_hash');
    expect($storedHash)->not->toContain($plainToken);

    $this->actingAs($admin)
        ->getJson('/api/dashboard/admin/hades')
        ->assertOk()
        ->assertJsonPath('bootstrapTokens.0.id', $response->json('token.id'));

    $this->actingAs($admin)
        ->deleteJson('/api/dashboard/admin/hades/bootstrap-tokens/'.$response->json('token.id'))
        ->assertOk()
        ->assertJsonPath('revoked', true);

    expect(DB::table('hades_bootstrap_tokens')->where('id', $response->json('token.id'))->value('revoked_at'))->not->toBeNull();
});

it('lets admins queue Hades jobs and review memory proposals for linked workspaces', function () {
    $admin = hadesM5DashboardUserWithRole('Admin');
    $agent = hadesM5RegisteredAgent($admin);
    $binding = hadesM5WorkspaceBinding($agent);

    $job = $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/jobs', [
            'project_id' => $agent['project_id'],
            'workspace_binding_id' => $binding['workspace_binding_id'],
            'capability' => 'read_files',
            'policy' => 'manual_review',
            'requires_confirmation' => true,
            'payload' => ['paths' => ['README.md']],
        ])
        ->assertCreated()
        ->assertJsonPath('job.project_id', $agent['project_id'])
        ->assertJsonPath('job.workspace_binding_id', $binding['workspace_binding_id'])
        ->assertJsonPath('job.status', 'queued')
        ->json('job');

    expect((int) DB::table('hades_agent_jobs')->where('id', $job['id'])->value('requires_confirmation'))->toBe(1);

    $proposalId = hadesM5MemoryProposal($agent, $binding, ['status' => 'pending']);

    $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/memory-proposals/'.$proposalId.'/review', [
            'status' => 'refused',
            'reason_code' => 'duplicate',
            'reason_message' => 'Already present in shared memory.',
        ])
        ->assertOk()
        ->assertJsonPath('proposal.id', $proposalId)
        ->assertJsonPath('proposal.status', 'refused');

    expect(DB::table('hades_memory_proposals')->where('id', $proposalId)->value('reason_code'))->toBe('duplicate');
});

it('requires dashboard Admin confirmation before an agent can complete a confirmation gated job', function () {
    $admin = hadesM5DashboardUserWithRole('Admin');
    $developer = hadesM5DashboardUserWithRole('Developer');
    $agent = hadesM5RegisteredAgent($admin);
    $binding = hadesM5WorkspaceBinding($agent);

    $jobId = $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/jobs', [
            'project_id' => $agent['project_id'],
            'workspace_binding_id' => $binding['workspace_binding_id'],
            'capability' => 'read_files',
            'policy' => 'manual_review',
            'requires_confirmation' => true,
            'payload' => ['paths' => ['README.md']],
        ])
        ->assertCreated()
        ->json('job.id');

    foreach (['received', 'waiting_confirmation'] as $status) {
        $this->postJson('/api/hades/v1/agent/jobs/'.$jobId.'/status', [
            'project_id' => $agent['project_id'],
            'agent_id' => $agent['external_agent_id'],
            'workspace_binding_id' => $binding['workspace_binding_id'],
            'status' => $status,
        ], hadesM5Headers($agent['agent_token']))->assertOk();
    }

    $this->postJson('/api/hades/v1/agent/jobs/'.$jobId.'/status', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'started',
    ], hadesM5Headers($agent['agent_token']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'job_transition_invalid');

    $this->actingAs($developer)
        ->postJson('/api/dashboard/admin/hades/jobs/'.$jobId.'/confirm')
        ->assertForbidden();

    $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/jobs/'.$jobId.'/confirm')
        ->assertOk()
        ->assertJsonPath('job.status', 'started');

    // Current Hephaistos acknowledges start before executing; after the Admin
    // confirmation this is an idempotent acknowledgement, not self-approval.
    $this->postJson('/api/hades/v1/agent/jobs/'.$jobId.'/status', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'started',
    ], hadesM5Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('job.status', 'started');

    $this->postJson('/api/hades/v1/agent/jobs/'.$jobId.'/result', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'completed',
        'result' => ['status' => 'completed', 'summary' => 'Admin-confirmed read completed.'],
    ], hadesM5Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('job.status', 'completed');

    expect(DB::table('hades_agent_job_events')->where('job_id', $jobId)->where('event_type', 'confirmation')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'hades.job_confirmed')->where('target_id', $jobId)->value('actor_user_id'))->toBe($admin->id);
});

it('normalizes gated policies and confirms legacy policy gated jobs', function (string $policy) {
    $admin = hadesM5DashboardUserWithRole('Admin');
    $agent = hadesM5RegisteredAgent($admin);
    $binding = hadesM5WorkspaceBinding($agent);

    $jobId = $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/jobs', [
            'project_id' => $agent['project_id'],
            'workspace_binding_id' => $binding['workspace_binding_id'],
            'capability' => 'read_files',
            'policy' => $policy,
            'requires_confirmation' => false,
            'payload' => ['paths' => ['README.md']],
        ])
        ->assertCreated()
        ->assertJsonPath('job.requires_confirmation', true)
        ->json('job.id');

    // Simulate a pre-normalization row while retaining the gated policy.
    DB::table('hades_agent_jobs')->where('id', $jobId)->update(['requires_confirmation' => false]);
    foreach (['received', 'waiting_confirmation'] as $status) {
        $this->postJson('/api/hades/v1/agent/jobs/'.$jobId.'/status', [
            'project_id' => $agent['project_id'],
            'agent_id' => $agent['external_agent_id'],
            'workspace_binding_id' => $binding['workspace_binding_id'],
            'status' => $status,
        ], hadesM5Headers($agent['agent_token']))->assertOk();
    }

    $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/jobs/'.$jobId.'/confirm')
        ->assertOk()
        ->assertJsonPath('job.status', 'started')
        ->assertJsonPath('job.requires_confirmation', true);

    expect((bool) DB::table('hades_agent_jobs')->where('id', $jobId)->value('requires_confirmation'))->toBeTrue();
})->with(['confirm', 'manual', 'approval_required']);

it('promotes accepted note backfill proposals to project memory once', function () {
    $admin = hadesM5DashboardUserWithRole('Admin');
    $agent = hadesM5RegisteredAgent($admin);
    $binding = hadesM5WorkspaceBinding($agent);
    $proposalId = hadesM5MemoryProposal($agent, $binding, [
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
        'status' => 'pending',
    ]);

    $first = $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/memory-proposals/'.$proposalId.'/review', [
            'status' => 'accepted',
            'reason_code' => 'verified',
            'reason_message' => 'Reviewed route-handler fact.',
        ])
        ->assertOk()
        ->assertJsonPath('proposal.id', $proposalId)
        ->assertJsonPath('proposal.status', 'accepted');

    $memoryEntryId = $first->json('proposal.memory_entry_id');
    expect($memoryEntryId)->toBeString();

    $entry = DB::table('project_memory_entries')->where('id', $memoryEntryId)->first();
    expect($entry)->not->toBeNull()
        ->and($entry->kind)->toBe('verified_note_fact')
        ->and($entry->summary)->toBe('Controller.php handles 3 taxonomy routes.');

    $payload = json_decode($entry->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['intent'])->toBe('note_backfill_candidate')
        ->and($payload['provenance']['source'])->toBe('hades_note_quality')
        ->and($payload['provenance']['candidate_fact']['kind'])->toBe('route_handler_group')
        ->and($payload['freshness']['status'])->toBe('current')
        ->and($payload['freshness']['workspace_head_commit'])->toBe(str_repeat('5', 40))
        ->and($payload['freshness']['index_status'])->toBe('reviewed_note_fact');

    $this->actingAs($admin)
        ->postJson('/api/dashboard/admin/hades/memory-proposals/'.$proposalId.'/review', [
            'status' => 'accepted',
            'reason_code' => 'verified_again',
        ])
        ->assertOk()
        ->assertJsonPath('proposal.memory_entry_id', $memoryEntryId);

    expect(DB::table('project_memory_entries')->where('project_id', $agent['project_id'])->where('source', 'hades_agent')->count())->toBe(1);
});

it('stores Hades git tree, symbols, and PHP graph artifacts from authenticated agents', function () {
    $agent = hadesM5RegisteredAgent();
    $binding = hadesM5WorkspaceBinding($agent);

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'schema' => 'hades.git_tree.v1',
        'artifact' => [
            'schema' => 'hades.git_tree.v1',
            'head_commit' => str_repeat('a', 40),
            'files' => [
                ['path' => 'README.md', 'sha256' => str_repeat('1', 64), 'bytes' => 120],
            ],
        ],
        'sha256' => str_repeat('2', 64),
        'truncated' => false,
    ], hadesM5Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('artifact.schema', 'hades.git_tree.v1')
        ->assertJsonPath('artifact.workspace_binding_id', $binding['workspace_binding_id']);

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'schema' => 'hades.symbols.v1',
        'artifact' => [
            'schema' => 'hades.symbols.v1',
            'language' => 'php',
            'symbols' => [
                ['name' => 'HadesTokenService', 'kind' => 'class', 'path' => 'app/Services/Hades/HadesTokenService.php'],
            ],
        ],
    ], hadesM5Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('artifact.schema', 'hades.symbols.v1');

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'schema' => 'hades.php_graph.v1',
        'artifact' => [
            'schema' => 'hades.php_graph.v1',
            'head_commit' => str_repeat('a', 40),
            'routes' => [[
                'method' => 'GET',
                'uri' => '/orders/{order}',
                'handler' => 'OrderController@show',
            ]],
            'symbols' => [[
                'name' => 'App\Http\Controllers\OrderController',
                'kind' => 'class',
                'path' => 'app/Http/Controllers/OrderController.php',
            ]],
            'edges' => [[
                'kind' => 'route_handler',
                'from' => 'route:orders.show',
                'to' => 'OrderController@show',
            ]],
            'raw_source_included' => false,
        ],
    ], hadesM5Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('artifact.schema', 'hades.php_graph.v1');

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'schema' => 'hades.code_graph.v1',
        'artifact' => [
            'schema' => 'hades.code_graph.v1',
            'framework' => 'nextjs',
            'routes' => [[
                'framework' => 'nextjs',
                'method' => 'GET',
                'path' => '/api/orders',
                'handler' => 'app/api/orders/route.ts:GET',
            ]],
            'symbols' => [[
                'name' => 'OrdersPage',
                'kind' => 'component',
                'path' => 'app/orders/page.tsx',
            ]],
            'edges' => [[
                'kind' => 'imports',
                'from' => 'app/orders/page.tsx',
                'to' => '../../components/OrderTable',
            ]],
            'raw_source_included' => false,
        ],
    ], hadesM5Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('artifact.schema', 'hades.code_graph.v1');

    expect(DB::table('hades_agent_artifacts')->where('schema', 'hades.git_tree.v1')->count())->toBe(1)
        ->and(DB::table('hades_agent_artifacts')->where('schema', 'hades.symbols.v1')->count())->toBe(1)
        ->and(DB::table('hades_agent_artifacts')->where('schema', 'hades.php_graph.v1')->count())->toBe(1)
        ->and(DB::table('hades_agent_artifacts')->where('schema', 'hades.code_graph.v1')->count())->toBe(1)
        ->and(DB::table('hades_search_documents')->where('domain', 'artifacts')->count())->toBe(4)
        ->and(DB::table('hades_search_documents')->where('source_schema', 'hades.php_graph.v1')->where('body', 'like', '%OrderController%')->exists())->toBeTrue()
        ->and(DB::table('hades_search_documents')->where('source_schema', 'hades.code_graph.v1')->where('body', 'like', '%OrdersPage%')->exists())->toBeTrue();
});

it('stores compressed Hades artifacts as decoded JSON', function () {
    $agent = hadesM5RegisteredAgent();
    $binding = hadesM5WorkspaceBinding($agent);
    $artifact = [
        'schema' => 'hades.code_graph.v1',
        'framework' => 'nextjs',
        'head_commit' => str_repeat('c', 40),
        'symbols' => collect(range(1, 300))->map(fn (int $index) => [
            'kind' => 'component',
            'name' => 'OrderComponent'.$index,
            'path' => 'app/orders/page.tsx',
            'line' => $index,
        ])->all(),
        'routes' => [],
        'edges' => [],
        'raw_source_included' => false,
    ];
    $json = json_encode($artifact, JSON_THROW_ON_ERROR);
    $compressed = gzencode($json);

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'schema' => 'hades.code_graph.v1',
        'artifact_encoding' => 'gzip+base64',
        'artifact_compressed' => base64_encode($compressed),
        'artifact_uncompressed_sha256' => hash('sha256', $json),
        'artifact_uncompressed_bytes' => strlen($json),
        'artifact_compressed_bytes' => strlen($compressed),
        'sha256' => hash('sha256', $json),
    ], hadesM5Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('artifact.schema', 'hades.code_graph.v1')
        ->assertJsonPath('artifact.workspace_binding_id', $binding['workspace_binding_id']);

    $stored = DB::table('hades_agent_artifacts')->where('schema', 'hades.code_graph.v1')->first();
    $decoded = json_decode($stored->artifact, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['schema'])->toBe('hades.code_graph.v1')
        ->and($decoded['symbols'])->toHaveCount(300)
        ->and($decoded['symbols'][0]['name'])->toBe('OrderComponent1')
        ->and(DB::table('hades_search_documents')->where('source_schema', 'hades.code_graph.v1')->where('body', 'like', '%OrderComponent300%')->exists())->toBeTrue();
});

it('looks up and deduplicates unchanged Hades artifacts by hash', function () {
    $agent = hadesM5RegisteredAgent();
    $binding = hadesM5WorkspaceBinding($agent);
    $artifact = [
        'schema' => 'hades.git_tree.v1',
        'head_commit' => str_repeat('d', 40),
        'files' => [
            ['path' => 'README.md', 'sha256' => str_repeat('1', 64), 'bytes' => 120],
        ],
    ];
    $json = json_encode($artifact, JSON_THROW_ON_ERROR);
    $sha256 = hash('sha256', $json);

    $this->getJson('/api/hades/v1/artifacts/lookup?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'schema' => 'hades.git_tree.v1',
        'sha256' => $sha256,
    ]), hadesM5Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('exists', false)
        ->assertJsonPath('delta_upload.required', true);

    $first = $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'schema' => 'hades.git_tree.v1',
        'artifact' => $artifact,
        'sha256' => $sha256,
        'truncated' => false,
    ], hadesM5Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('artifact.schema', 'hades.git_tree.v1')
        ->json('artifact.id');

    $this->getJson('/api/hades/v1/artifacts/lookup?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'schema' => 'hades.git_tree.v1',
        'sha256' => $sha256,
    ]), hadesM5Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonPath('artifact.id', $first)
        ->assertJsonPath('delta_upload.required', false);

    $this->postJson('/api/hades/v1/artifacts', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'schema' => 'hades.git_tree.v1',
        'artifact' => $artifact,
        'sha256' => $sha256,
        'truncated' => false,
    ], hadesM5Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('artifact.id', $first)
        ->assertJsonPath('deduplicated', true)
        ->assertJsonPath('delta_upload.reason', 'unchanged_on_backend');

    expect(DB::table('hades_agent_artifacts')->where('schema', 'hades.git_tree.v1')->count())->toBe(1)
        ->and(DB::table('hades_search_documents')->where('source_table', 'hades_agent_artifacts')->count())->toBe(1);
});

it('stores explicit doctor reports and exposes a persistent Persephone inbox with polling and SSE fallback', function () {
    $agent = hadesM5RegisteredAgent();
    $binding = hadesM5WorkspaceBinding($agent);

    $this->postJson('/api/hades/v1/doctor/reports', [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'status' => 'warning',
        'payload' => [
            'checks' => [['name' => 'backend', 'status' => 'warning']],
            'reported_by' => 'hades doctor --report',
        ],
    ], hadesM5Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('report.status', 'warning')
        ->assertJsonPath('report.workspace_binding_id', $binding['workspace_binding_id']);

    $this->postJson('/api/hades/v1/persephone/messages', [
        'project_id' => $agent['project_id'],
        'event_type' => 'proposal.reviewed',
        'payload' => ['message' => 'Memory proposal refused.'],
    ], hadesM5Headers($agent['agent_token']))
        ->assertCreated()
        ->assertJsonPath('event.event_type', 'proposal.reviewed');

    $this->getJson('/api/hades/v1/persephone/inbox?'.http_build_query([
        'project_id' => $agent['project_id'],
    ]), hadesM5Headers($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('counts.total', 1)
        ->assertJsonPath('counts.unread', 1)
        ->assertJsonPath('events.0.event_type', 'proposal.reviewed');

    $this->get('/api/hades/v1/persephone/events?'.http_build_query([
        'project_id' => $agent['project_id'],
    ]), hadesM5Headers($agent['agent_token']))
        ->assertOk()
        ->assertHeader('content-type', 'text/event-stream; charset=UTF-8')
        ->assertSee('event: proposal.reviewed');

    expect(DB::table('hades_doctor_reports')->where('project_id', $agent['project_id'])->count())->toBe(1)
        ->and(DB::table('hades_persephone_events')->where('project_id', $agent['project_id'])->count())->toBe(1);
});

function hadesM5Headers(?string $token = null): array
{
    return $token === null ? [] : ['Authorization' => 'Bearer '.$token];
}

function hadesM5DashboardUserWithRole(string $roleName): User
{
    $user = User::factory()->create([
        'status' => 'active',
        'password' => Hash::make('devboard'),
    ]);
    $roleId = DB::table('roles')->where('name', $roleName)->value('id');

    DB::table('role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

/**
 * @return array{id: string, user_id: int}
 */
function hadesM5Project(?User $owner = null): array
{
    $owner ??= User::factory()->create(['status' => 'active']);
    $id = (string) Str::ulid();
    $now = now();

    DB::table('projects')->insert([
        'id' => $id,
        'name' => 'Hades M5 Test Project',
        'slug' => 'hades-m5-test-'.Str::lower(Str::random(8)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $owner->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['id' => $id, 'user_id' => $owner->id];
}

/**
 * @return array{id: string, plain_token: string, project_id: string}
 */
function hadesM5BootstrapToken(?string $projectId = null): array
{
    $projectId = $projectId ?: hadesM5Project()['id'];
    $id = (string) Str::ulid();
    $secret = 'hades-m5-bootstrap-test-secret';
    $prefix = 'hades_bootstrap_'.$id;
    $now = now();

    DB::table('hades_bootstrap_tokens')->insert([
        'id' => $id,
        'project_id' => $projectId,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'name' => 'Hades M5 Bootstrap Token',
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
function hadesM5RegisteredAgent(?User $owner = null): array
{
    $project = hadesM5Project($owner);
    $bootstrap = hadesM5BootstrapToken($project['id']);
    $externalAgentId = 'local-agent-'.Str::lower(Str::random(8));
    $test = test();

    $registered = $test->postJson('/api/hades/v1/agents/register', [
        'project_id' => $bootstrap['project_id'],
        'agent_id' => $externalAgentId,
        'label' => 'Hades M5 Agent',
        'platform' => 'linux-x64',
        'version' => '0.5.0',
        'capabilities' => ['read_files', 'sync_git_tree', 'populate_backend_ast'],
    ], hadesM5Headers($bootstrap['plain_token']))->assertOk();

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
function hadesM5WorkspaceBinding(array $agent): array
{
    $test = test();
    $bound = $test->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_fingerprint' => 'wf_hades_m5_'.Str::lower(Str::random(8)),
        'display_path' => '~/Code/hades-m5',
        'git_remote_display' => 'github.com/acme/hades-m5.git',
        'git_remote_hash' => hash('sha256', 'git@github.com:acme/hades-m5.git'),
        'head_commit' => str_repeat('5', 40),
    ], hadesM5Headers($agent['agent_token']))->assertOk();

    return ['workspace_binding_id' => $bound->json('workspace_binding_id')];
}

/**
 * @param  array{project_id: string, external_agent_id: string, backend_agent_id: string, agent_token: string}  $agent
 * @param  array{workspace_binding_id: string}  $binding
 * @param  array<string, mixed>  $overrides
 */
function hadesM5MemoryProposal(array $agent, array $binding, array $overrides = []): string
{
    $id = (string) Str::ulid();
    $now = now();
    $provenance = $overrides['provenance'] ?? ['source' => 'test'];

    DB::table('hades_memory_proposals')->insert(array_merge([
        'id' => $id,
        'project_id' => $agent['project_id'],
        'hades_agent_id' => $agent['backend_agent_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'local_proposal_id' => 'm5-proposal-'.$id,
        'action' => $overrides['action'] ?? 'update',
        'intent' => $overrides['intent'] ?? 'shared_memory_update',
        'summary' => $overrides['summary'] ?? 'Update shared memory from Hades.',
        'provenance' => json_encode($provenance, JSON_THROW_ON_ERROR),
        'base_version' => null,
        'target_memory_entry_id' => null,
        'memory_entry_id' => null,
        'status' => 'pending',
        'reason_code' => null,
        'reason_message' => null,
        'decided_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], array_filter([
        'status' => $overrides['status'] ?? null,
    ], fn ($value) => $value !== null)));

    return $id;
}
