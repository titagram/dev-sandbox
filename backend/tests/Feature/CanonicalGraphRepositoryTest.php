<?php

use App\Services\Graph\CanonicalGraphRepository;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DevBoardSeeder::class);
    Storage::fake('local');
});

it('selects exact trusted source scoped graphs and adapts legacy contracts', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('project_id', $projectId)->value('id');
    [$bindingId, $hadesId] = canonicalRepoHades($projectId);
    $legacyId = canonicalRepoSnapshot($projectId, $repositoryId);
    $repo = app(CanonicalGraphRepository::class);

    $graph = $repo->latestForScope($projectId, 'workspace_binding', $bindingId);
    expect($graph['identity']['artifact_type'])->toBe('hades_agent_artifact')
        ->and($graph['identity']['artifact_id'])->toBe($hadesId)
        ->and($graph['identity']['source_scope_id'])->toBe($bindingId)
        ->and($graph['contract']['extractor']['mode'])->toBe('legacy_adapter')
        ->and($graph['contract']['extractor']['name'])->toBe('hades-legacy-php');

    $legacy = $repo->latestForScope($projectId, 'repository', $repositoryId);
    expect($legacy['identity']['artifact_type'])->toBe('legacy_artifact')
        ->and($legacy['identity']['artifact_id'])->toBe($legacyId)
        ->and($legacy['contract']['extractor']['name'])->toBe('legacy-analyzer')
        ->and($repo->latestForScope((string) Str::ulid(), 'workspace_binding', $bindingId))->toBeNull()
        ->and($repo->findByIdentity($projectId, 'workspace_binding', $bindingId, 'hades_agent_artifact', $hadesId)['identity']['artifact_id'])->toBe($hadesId)
        ->and($repo->findByIdentity($projectId, 'repository', $repositoryId, 'legacy_artifact', $legacyId)['identity']['artifact_id'])->toBe($legacyId)
        ->and($repo->findByIdentity($projectId, 'repository', $repositoryId, 'hades_agent_artifact', $hadesId))->toBeNull();
});

it('ignores unlinked bindings and rejects unknown scope types', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    [$bindingId] = canonicalRepoHades($projectId, 'unlinked');
    $repo = app(CanonicalGraphRepository::class);
    expect($repo->latestForScope($projectId, 'workspace_binding', $bindingId))->toBeNull();
    $repo->latestForScope($projectId, 'project', $projectId);
})->throws(InvalidArgumentException::class);

it('lists each linked source separately without display paths', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('project_id', $projectId)->value('id');
    [$bindingId] = canonicalRepoHades($projectId);
    canonicalRepoSnapshot($projectId, $repositoryId);
    $scopes = app(CanonicalGraphRepository::class)->listScopes($projectId);
    expect($scopes)->toContain(
        ['source_scope_type' => 'workspace_binding', 'source_scope_id' => $bindingId],
        ['source_scope_type' => 'repository', 'source_scope_id' => $repositoryId],
    )->and(collect($scopes)->contains(fn (array $scope) => array_key_exists('display_path', $scope)))->toBeFalse();
});

function canonicalRepoHades(string $projectId, string $status = 'linked'): array
{
    $agentId = (string) Str::ulid();
    $bindingId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $now = now();
    DB::table('hades_agents')->insert(['id' => $agentId, 'project_id' => $projectId, 'external_agent_id' => 'agent-'.$agentId, 'label' => 'test', 'declared_capabilities' => '[]', 'effective_capabilities' => '[]', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('hades_workspace_bindings')->insert(['id' => $bindingId, 'project_id' => $projectId, 'hades_agent_id' => $agentId, 'external_agent_id' => 'agent-'.$agentId, 'workspace_fingerprint' => 'wf-'.$bindingId, 'display_path' => '/secret', 'status' => $status, 'created_at' => $now, 'updated_at' => $now]);
    $payload = ['language' => 'php', 'files_total' => 2, 'nodes' => [['id' => 'A']], 'relationships' => []];
    DB::table('hades_agent_artifacts')->insert(['id' => $artifactId, 'project_id' => $projectId, 'workspace_binding_id' => $bindingId, 'schema' => 'hades.php_graph.v1', 'artifact' => json_encode($payload), 'truncated' => false, 'redactions' => 0, 'created_at' => $now, 'updated_at' => $now]);

    return [$bindingId, $artifactId];
}

function canonicalRepoSnapshot(string $projectId, string $repositoryId): string
{
    $userId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $deviceId = (string) Str::ulid();
    $workspaceId = (string) Str::ulid();
    $runId = (string) Str::ulid();
    $artifactId = (string) Str::ulid();
    $now = now();
    DB::table('devices')->insert(['id' => $deviceId, 'user_id' => $userId, 'name' => 'device', 'fingerprint_hash' => 'fp-'.$deviceId, 'platform_os' => 'linux', 'platform_arch' => 'amd64', 'plugin_version' => '1', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('local_workspaces')->insert(['id' => $workspaceId, 'repository_id' => $repositoryId, 'device_id' => $deviceId, 'local_root_hash' => 'root-'.$workspaceId, 'display_path' => '/secret', 'current_branch' => 'main', 'dirty_status' => 'clean', 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('runs')->insert(['id' => $runId, 'project_id' => $projectId, 'repository_id' => $repositoryId, 'local_workspace_id' => $workspaceId, 'device_id' => $deviceId, 'started_by_user_id' => $userId, 'runtime_profile' => 'default', 'status' => 'completed', 'branch' => 'main', 'base_branch' => 'main', 'base_sha' => 'abc', 'risk_level' => 'low', 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $json = json_encode(['language' => 'javascript', 'files' => [['path' => 'a.js']], 'nodes' => [['id' => 'B']], 'relationships' => []]);
    $path = 'graphs/'.$artifactId.'.json';
    Storage::disk('local')->put($path, $json);
    DB::table('artifacts')->insert(['id' => $artifactId, 'project_id' => $projectId, 'repository_id' => $repositoryId, 'run_id' => $runId, 'artifact_type' => 'graph_snapshot', 'storage_path' => $path, 'sha256' => hash('sha256', $json), 'size_bytes' => strlen($json), 'mime_type' => 'application/json', 'schema_version' => 'v1', 'status' => 'uploaded', 'producer' => 'legacy-analyzer', 'metadata' => '[]', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('snapshots')->insert(['id' => (string) Str::ulid(), 'project_id' => $projectId, 'repository_id' => $repositoryId, 'local_workspace_id' => $workspaceId, 'source_type' => 'local_plugin_snapshot', 'branch' => 'main', 'base_sha' => 'abc', 'dirty_status' => 'clean', 'graph_snapshot_artifact_id' => $artifactId, 'created_by_run_id' => $runId, 'created_at' => $now]);

    return $artifactId;
}
