<?php

use App\Contracts\EmbeddingGenerator;
use App\Models\User;
use App\Services\Hades\HadesTokenService;
use App\Services\Search\EmbeddingIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    config()->set('devboard.embeddings.enabled', true);
    config()->set('devboard.embeddings.provider', 'fake');
    config()->set('devboard.embeddings.model', 'fake-vector-acceptance');
    config()->set('devboard.embeddings.dimensions', 1536);
    config()->set('devboard.vector_score_weight', 20);
});

it('retrieves semantic-only memory candidates through pgvector with scoped filters and exact limit', function (): void {
    $agent = vectorMemoryRegisteredAgent();
    $binding = vectorMemoryWorkspaceBinding($agent);
    $otherAgent = vectorMemoryRegisteredAgent();
    $otherBinding = vectorMemoryWorkspaceBinding($otherAgent);
    $now = now();
    $semanticId = (string) Str::ulid();
    $lexicalId = (string) Str::ulid();
    $rawChunkId = (string) Str::ulid();
    $otherProjectId = (string) Str::ulid();

    DB::table('project_memory_entries')->insert([
        [
            'id' => $semanticId,
            'project_id' => $agent['project_id'],
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => null,
            'source' => 'manual',
            'kind' => 'decision',
            'completeness' => 'complete',
            'summary' => 'Store durable revenue reconciliation outcome.',
            'payload' => json_encode(['evidence_refs' => [['path' => 'docs/revenue.md']]], JSON_THROW_ON_ERROR),
            'occurred_at' => $now->copy()->subHour(),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => $lexicalId,
            'project_id' => $agent['project_id'],
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => null,
            'source' => 'manual',
            'kind' => 'decision',
            'completeness' => 'complete',
            'summary' => 'pgvector-acceptance-query lexical entry.',
            'payload' => json_encode(['note' => 'lexical match'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => $rawChunkId,
            'project_id' => $agent['project_id'],
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => null,
            'source' => 'backend_wiki_import',
            'kind' => 'file_chunk',
            'completeness' => 'complete',
            'summary' => 'Raw chunk close to query embedding.',
            'payload' => json_encode(['schema' => 'hades.backend_wiki.file_chunk.v1', 'chunk_index' => 1], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => $otherProjectId,
            'project_id' => $otherAgent['project_id'],
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => null,
            'source' => 'manual',
            'kind' => 'decision',
            'completeness' => 'complete',
            'summary' => 'Cross-project vector should not appear.',
            'payload' => json_encode(['note' => 'other project'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    DB::table('hades_search_documents')->insert([
        vectorMemorySearchDocument($agent['project_id'], null, 'logbook', $semanticId, 'Store durable revenue reconciliation outcome.', [['path' => 'docs/revenue.md']]),
        vectorMemorySearchDocument($agent['project_id'], null, 'logbook', $lexicalId, 'pgvector-acceptance-query lexical entry.', []),
        vectorMemorySearchDocument($agent['project_id'], $binding['workspace_binding_id'], 'source_chunks', $rawChunkId, 'Raw chunk close to query embedding.', []),
        vectorMemorySearchDocument($otherAgent['project_id'], $otherBinding['workspace_binding_id'], 'logbook', $otherProjectId, 'Cross-project vector should not appear.', []),
    ]);

    $service = new EmbeddingIndexService;
    $service->indexDocument('project_memory_entries', $semanticId, vectorMemoryEmbedding([1.0, 0.0, 0.0]));
    $service->indexDocument('project_memory_entries', $lexicalId, vectorMemoryEmbedding([0.7, 0.7, 0.0]));
    $service->indexDocument('project_memory_entries', $rawChunkId, vectorMemoryEmbedding([1.0, 0.0, 0.0]));
    $service->indexDocument('project_memory_entries', $otherProjectId, vectorMemoryEmbedding([1.0, 0.0, 0.0]));

    $embeddingGenerator = new VectorMemoryEmbeddingGeneratorFake([vectorMemoryEmbedding([1.0, 0.0, 0.0])]);
    app()->instance(EmbeddingGenerator::class, $embeddingGenerator);

    $response = $this->getJson('/api/hades/v1/memory/search?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding['workspace_binding_id'],
        'query' => 'pgvector-acceptance-query',
        'domain' => 'logbook',
        'limit' => 2,
    ]), vectorMemoryHeaders($agent['agent_token']))
        ->assertOk()
        ->assertJsonPath('count', 2)
        ->assertJsonPath('retrieval.lexical.status', 'ok')
        ->assertJsonPath('retrieval.vector.status', 'ok')
        ->assertJsonPath('retrieval.vector.model', 'fake-vector-acceptance')
        ->json();

    expect($embeddingGenerator->calls)->toBe(1);
    expect($response['items'])->toHaveCount(2);
    $ids = collect($response['items'])->pluck('id')->all();
    expect($ids)->toContain($semanticId)->toContain($lexicalId)->not->toContain($rawChunkId)->not->toContain($otherProjectId);

    $semantic = collect($response['items'])->firstWhere('id', $semanticId);
    expect($semantic['similarity'])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
    expect($semantic['evidence_refs'])->toBe([['path' => 'docs/revenue.md']]);
    expect($semantic['needs_verification'])->toBeFalse();
    expect($semantic['score'])->toBeGreaterThan(0)->toBeLessThan(80);
});

function vectorMemoryRegisteredAgent(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $projectId = (string) Str::ulid();
    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Vector Memory Acceptance',
        'slug' => 'vector-memory-'.Str::lower(Str::random(8)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $bootstrap = app(HadesTokenService::class)->createBootstrapToken($projectId, 'Vector Memory Acceptance');
    $externalAgentId = 'vector-agent-'.Str::lower(Str::random(8));

    $registered = test()->postJson('/api/hades/v1/agents/register', [
        'project_id' => $projectId,
        'agent_id' => $externalAgentId,
        'label' => 'Vector Memory Agent',
        'platform' => 'linux-x64',
        'version' => '0.3.0',
        'capabilities' => ['read_files', 'sync_git_tree', 'populate_backend_ast'],
    ], vectorMemoryHeaders($bootstrap['plain_token']))->assertOk();

    return [
        'project_id' => $projectId,
        'external_agent_id' => $externalAgentId,
        'backend_agent_id' => $registered->json('backend_agent_id'),
        'agent_token' => $registered->json('agent_token'),
    ];
}

function vectorMemoryWorkspaceBinding(array $agent): array
{
    $bound = test()->postJson('/api/hades/v1/workspaces/bind', [
        'project_id' => $agent['project_id'],
        'agent_id' => $agent['external_agent_id'],
        'workspace_fingerprint' => 'wf_vector_'.Str::lower(Str::random(8)),
        'display_path' => '~/Code/vector-memory',
        'git_remote_display' => 'github.com/acme/vector-memory.git',
        'git_remote_hash' => hash('sha256', 'git@github.com:acme/vector-memory.git'),
        'head_commit' => str_repeat('f', 40),
    ], vectorMemoryHeaders($agent['agent_token']))->assertOk();

    return ['workspace_binding_id' => $bound->json('workspace_binding_id')];
}

function vectorMemoryHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ];
}

function vectorMemorySearchDocument(string $projectId, ?string $workspaceBindingId, string $domain, string $sourceId, string $body, array $evidenceRefs): array
{
    return [
        'id' => (string) Str::ulid(),
        'project_id' => $projectId,
        'workspace_binding_id' => $workspaceBindingId,
        'domain' => $domain,
        'kind' => $domain === 'source_chunks' ? 'file_chunk' : 'decision',
        'source_table' => 'project_memory_entries',
        'source_id' => $sourceId,
        'source_schema' => $domain === 'source_chunks' ? 'hades.backend_wiki.file_chunk.v1' : 'devboard.memory_note.v1',
        'title' => $body,
        'body' => $body,
        'metadata' => json_encode(['evidence_refs' => $evidenceRefs], JSON_THROW_ON_ERROR),
        'checksum' => hash('sha256', $sourceId.'|'.$body),
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

/**
 * @param  list<float>  $prefix
 * @return list<float>
 */
function vectorMemoryEmbedding(array $prefix): array
{
    return array_pad($prefix, 1536, 0.0);
}

class VectorMemoryEmbeddingGeneratorFake implements EmbeddingGenerator
{
    public int $calls = 0;

    /** @param list<list<float>> $responses */
    public function __construct(private array $responses) {}

    /** @return list<float> */
    public function generate(string $input): array
    {
        $this->calls++;

        return array_shift($this->responses) ?? vectorMemoryEmbedding([1.0, 0.0, 0.0]);
    }
}
