<?php

use App\Models\User;
use App\Services\Search\EmbeddingIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('constructs and reports vector search availability', function () {
    $service = new EmbeddingIndexService;

    $avail = $service->supportsVectorSearch();
    $embeds = $service->supportsEmbeddings();

    expect($avail)->toBeBool();
    expect($embeds)->toBeBool();

    if (DB::connection()->getDriverName() !== 'pgsql') {
        expect($avail)->toBeFalse();
        expect($embeds)->toBeFalse();
    }
});

it('returns empty fallback search results on unsupported databases', function () {
    if (DB::connection()->getDriverName() === 'pgsql') {
        $this->markTestSkipped('This test validates fallback behavior on non-pgsql databases.');
    }

    $projectId = seedSearchProject();

    $service = new EmbeddingIndexService;

    expect($service->supportsVectorSearch())->toBeFalse();

    $results = $service->searchSimilar($projectId, array_fill(0, 1536, 0.0), 10);

    expect($results)->toBeArray()->toHaveCount(0);
});

it('indexes a document embedding without error on supported databases', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Embedding storage requires PostgreSQL with pgvector extension.');
    }

    $projectId = seedSearchProject();

    $searchDocId = (string) Str::ulid();
    $sourceId = (string) Str::ulid();
    DB::table('hades_search_documents')->insert([
        'id' => $searchDocId,
        'project_id' => $projectId,
        'workspace_binding_id' => null,
        'domain' => 'wiki',
        'kind' => 'wiki',
        'source_table' => 'wiki_revisions',
        'source_id' => $sourceId,
        'title' => 'Test Document',
        'body' => 'Test body content for embedding.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $embedding = array_fill(0, 1536, 0.01);

    $service = new EmbeddingIndexService;
    $service->indexDocument('wiki_revisions', $sourceId, $embedding);

    $row = DB::table('hades_search_documents')->where('id', $searchDocId)->first();
    expect($row)->not->toBeNull();
    expect($row->embedding)->not->toBeNull();
});

it('finds similar documents by cosine similarity on pgsql', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Vector similarity search requires PostgreSQL with pgvector extension.');
    }

    $projectId = seedSearchProject();

    $sourceId1 = (string) Str::ulid();
    $sourceId2 = (string) Str::ulid();

    DB::table('hades_search_documents')->insert([
        [
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'workspace_binding_id' => null,
            'domain' => 'wiki',
            'kind' => 'wiki',
            'source_table' => 'wiki_revisions',
            'source_id' => $sourceId1,
            'title' => 'Vector Doc 1',
            'body' => 'Similar content about vectors.',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => (string) Str::ulid(),
            'project_id' => $projectId,
            'workspace_binding_id' => null,
            'domain' => 'wiki',
            'kind' => 'wiki',
            'source_table' => 'wiki_revisions',
            'source_id' => $sourceId2,
            'title' => 'Vector Doc 2',
            'body' => 'Completely different unrelated topic.',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $emb1 = array_fill(0, 1536, 0.02);
    $emb2 = array_fill(0, 1536, -0.03);

    $service = new EmbeddingIndexService;
    $service->indexDocument('wiki_revisions', $sourceId1, $emb1);
    $service->indexDocument('wiki_revisions', $sourceId2, $emb2);

    $results = $service->searchSimilar($projectId, $emb1, 2);

    expect($results)->toBeArray()->not->toHaveCount(0);
    expect($results[0])->toHaveKeys(['source_id', 'source_table', 'similarity', 'evidence_refs', 'needs_verification']);
    expect($results[0]['similarity'])->toBeFloat();
    expect($results[0]['source_id'])->toBe($sourceId1);
});

it('marks entries without evidence refs as needs_verification', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Evidence ref extraction requires PostgreSQL with pgvector extension.');
    }

    $projectId = seedSearchProject();

    $sourceId = (string) Str::ulid();

    DB::table('hades_search_documents')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $projectId,
        'workspace_binding_id' => null,
        'domain' => 'wiki',
        'kind' => 'wiki',
        'source_table' => 'wiki_revisions',
        'source_id' => $sourceId,
        'title' => 'No Evidence Doc',
        'body' => 'Content without evidence refs.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $embedding = array_fill(0, 1536, 0.01);
    $service = new EmbeddingIndexService;
    $service->indexDocument('wiki_revisions', $sourceId, $embedding);

    $results = $service->searchSimilar($projectId, $embedding, 1);

    expect($results)->toBeArray()->not->toHaveCount(0);
    expect($results[0])->toHaveKey('needs_verification');
    expect($results[0]['evidence_refs'])->toBeArray();
});

it('gracefully handles index document on unsupported databases', function () {
    if (DB::connection()->getDriverName() === 'pgsql') {
        $this->markTestSkipped('This test validates fallback behavior on non-pgsql databases.');
    }

    $service = new EmbeddingIndexService;

    $service->indexDocument('wiki_revisions', (string) Str::ulid(), array_fill(0, 1536, 0.01));

    expect(true)->toBeTrue();
});

it('gracefully handles search on unsupported databases returning empty results', function () {
    if (DB::connection()->getDriverName() === 'pgsql') {
        $this->markTestSkipped('This test validates fallback behavior on non-pgsql databases.');
    }

    $projectId = seedSearchProject();

    $service = new EmbeddingIndexService;

    $results = $service->searchSimilar($projectId, array_fill(0, 1536, 0.01), 10);

    expect($results)->toBeArray()->toHaveCount(0);
});

it('reports embeddings unsupported until feature schema dimensions extension and provider config are operational', function () {
    config()->set('devboard.embeddings.enabled', true);
    config()->set('devboard.embeddings.provider', 'openai');
    config()->set('devboard.embeddings.model', 'text-embedding-3-small');
    config()->set('devboard.embeddings.dimensions', 1536);

    $service = new EmbeddingIndexService;

    if (DB::connection()->getDriverName() !== 'pgsql') {
        expect($service->supportsEmbeddings())->toBeFalse();
    } else {
        expect($service->supportsEmbeddings())->toBeTrue();
    }
});

it('has a PostgreSQL HNSW cosine index for embeddings', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL index catalog assertions require pgsql.');
    }

    $index = DB::table('pg_indexes')
        ->where('tablename', 'hades_search_documents')
        ->where('indexname', 'hades_search_documents_embedding_hnsw_idx')
        ->value('indexdef');

    expect($index)->toBeString();
    expect($index)->toContain('hnsw');
    expect($index)->toContain('vector_cosine_ops');
});

function seedSearchProject(): string
{
    $user = User::factory()->create(['status' => 'active']);
    $id = (string) Str::ulid();
    $now = now();

    DB::table('projects')->insert([
        'id' => $id,
        'name' => 'Search Test Project',
        'slug' => 'search-test-'.Str::lower(Str::random(8)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $user->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}
