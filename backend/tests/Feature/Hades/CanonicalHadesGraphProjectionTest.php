<?php

use App\Jobs\ProjectCanonicalGraphToNeo4j;
use App\Models\User;
use App\Services\Graph\CanonicalGraphRepository;
use App\Services\Graph\Neo4jCanonicalGraphProjector;
use App\Services\Neo4j\FakeNeo4jClient;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DevBoardSeeder::class));

it('queues one projection for a new canonical graph and none for its duplicate', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $payload = canonicalProjectionUpload($agent, $bindingId);

    $first = $this->postJson('/api/hades/v1/artifacts', $payload, ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->first();

    expect($projection)->not->toBeNull()->and($projection->artifact_id)->toBe($first->json('artifact.id'));
    Bus::assertDispatchedTimes(ProjectCanonicalGraphToNeo4j::class, 1);
    Bus::assertDispatched(fn (ProjectCanonicalGraphToNeo4j $job) => $job->projectionId === $projection->id);

    $this->postJson('/api/hades/v1/artifacts', $payload, ['Authorization' => 'Bearer '.$agent['token']])
        ->assertOk()->assertJsonPath('deduplicated', true);
    Bus::assertDispatchedTimes(ProjectCanonicalGraphToNeo4j::class, 1);
});

it('projects batches with project scope and graph version', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $response = $this->postJson('/api/hades/v1/artifacts', canonicalProjectionUpload($agent, $bindingId), ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->first();
    $graph = app(CanonicalGraphRepository::class)->findByIdentity($agent['project_id'], 'workspace_binding', $bindingId, 'hades_agent_artifact', $response->json('artifact.id'));
    $client = new FakeNeo4jClient;

    $counts = app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client);

    expect($counts)->toBe(['nodes' => 2, 'relationships' => 1]);
    foreach (['UNWIND $nodes', 'UNWIND $relationships'] as $needle) {
        $command = collect($client->commands)->first(fn (array $item) => str_contains($item['cypher'], $needle));
        expect($command['params'])->toMatchArray(['project_id' => $agent['project_id'], 'source_scope_type' => 'workspace_binding', 'source_scope_id' => $bindingId, 'graph_version' => $projection->graph_version]);
    }
});

function canonicalProjectionUpload(array $agent, string $bindingId): array
{
    return ['project_id' => $agent['project_id'], 'workspace_binding_id' => $bindingId, 'schema' => 'hades.code_graph.v1', 'artifact' => [
        'schema' => 'hades.code_graph.v1', 'language' => 'php',
        'graph_contract' => ['version' => 'hades.graph_artifact.v1', 'extractor' => ['name' => 'test', 'version' => '1', 'mode' => 'full', 'quality' => 'complete'], 'coverage' => ['languages' => ['php'], 'files_total' => 1, 'files_analyzed' => 1, 'files_failed' => 0], 'source' => ['branch' => 'main', 'head_commit' => str_repeat('a', 40)]],
        'nodes' => [['id' => 'file:a.php', 'kind' => 'file'], ['id' => 'class:A', 'kind' => 'class']],
        'relationships' => [['id' => 'declares:a', 'type' => 'DECLARES', 'source_id' => 'file:a.php', 'target_id' => 'class:A']],
    ]];
}

function canonicalProjectionAgent(): array
{
    $owner = User::factory()->create(['status' => 'active']);
    $projectId = (string) Str::ulid();
    $now = now();
    DB::table('projects')->insert(['id' => $projectId, 'name' => 'Canonical graph', 'slug' => 'canonical-'.Str::lower(Str::random(8)), 'description' => null, 'status' => 'active', 'default_code_exposure_policy' => 'full_code_artifacts', 'created_by_user_id' => $owner->id, 'created_at' => $now, 'updated_at' => $now]);
    $bootstrapId = (string) Str::ulid();
    $secret = 'canonical-secret';
    $prefix = 'hades_bootstrap_'.$bootstrapId;
    DB::table('hades_bootstrap_tokens')->insert(['id' => $bootstrapId, 'project_id' => $projectId, 'token_prefix' => $prefix, 'token_hash' => hash('sha256', $secret), 'name' => 'Canonical', 'scopes' => json_encode(['hades.bootstrap']), 'allowed_capabilities' => json_encode(['populate_backend_ast']), 'expires_at' => now()->addDay(), 'revoked_at' => null, 'last_used_at' => null, 'created_at' => $now, 'updated_at' => $now]);
    $registered = test()->postJson('/api/hades/v1/agents/register', ['project_id' => $projectId, 'agent_id' => 'canonical-agent', 'label' => 'Canonical', 'platform' => 'linux-x64', 'version' => '1', 'capabilities' => ['populate_backend_ast']], ['Authorization' => 'Bearer '.$prefix.'|'.$secret])->assertOk();
    $agent = ['project_id' => $projectId, 'external_id' => 'canonical-agent', 'token' => $registered->json('agent_token')];
    $bound = test()->postJson('/api/hades/v1/workspaces/bind', ['project_id' => $projectId, 'agent_id' => $agent['external_id'], 'workspace_fingerprint' => 'wf_'.Str::random(8), 'display_path' => '/tmp/canonical', 'git_remote_display' => 'example/repo', 'git_remote_hash' => hash('sha256', 'example/repo'), 'head_commit' => str_repeat('a', 40)], ['Authorization' => 'Bearer '.$agent['token']])->assertOk();

    return [$agent, $bound->json('workspace_binding_id')];
}
