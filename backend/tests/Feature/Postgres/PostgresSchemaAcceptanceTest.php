<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('verifies PostgreSQL full-text and Embedding pgvector schema acceptance', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    expect(DB::table('pg_extension')->where('extname', 'vector')->exists())->toBeTrue();

    $columns = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'hades_search_documents')
        ->whereIn('column_name', ['search_vector', 'embedding'])
        ->pluck('data_type', 'column_name')
        ->all();

    expect($columns)->toHaveKey('search_vector')
        ->and($columns['search_vector'])->toBe('tsvector')
        ->and($columns)->toHaveKey('embedding')
        ->and($columns['embedding'])->toBe('USER-DEFINED');

    $ginIndexExists = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'hades_search_documents')
        ->where('indexname', 'hades_search_documents_tsvector_idx')
        ->where('indexdef', 'like', '%USING gin (search_vector)%')
        ->exists();

    expect($ginIndexExists)->toBeTrue();

    $hnswIndexDefinition = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'hades_search_documents')
        ->where('indexname', 'hades_search_documents_embedding_hnsw_idx')
        ->value('indexdef');

    expect($hnswIndexDefinition)->toBeString()
        ->and($hnswIndexDefinition)->toContain('hnsw')
        ->and($hnswIndexDefinition)->toContain('vector_cosine_ops');

    $triggerExists = DB::table('pg_trigger as t')
        ->join('pg_class as c', 'c.oid', '=', 't.tgrelid')
        ->where('c.relname', 'hades_search_documents')
        ->where('t.tgname', 'hades_search_documents_tsvector_update')
        ->where('t.tgenabled', 'O')
        ->exists();

    expect($triggerExists)->toBeTrue();

    $userId = DB::table('users')->insertGetId([
        'name' => 'Postgres Acceptance',
        'email' => 'postgres-acceptance@example.test',
        'password' => 'unused',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $projectId = (string) Str::ulid();
    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Postgres Acceptance',
        'slug' => 'postgres-acceptance',
        'created_by_user_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $documentId = (string) Str::ulid();
    DB::table('hades_search_documents')->insert([
        'id' => $documentId,
        'project_id' => $projectId,
        'domain' => 'acceptance',
        'kind' => 'schema',
        'source_table' => 'acceptance_sources',
        'source_id' => (string) Str::ulid(),
        'title' => 'alpha title',
        'body' => 'bravo body',
        'source_schema' => 'charlie schema',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(searchVectorMatches($documentId, 'alpha & bravo & charlie'))->toBeTrue();

    DB::table('hades_search_documents')->where('id', $documentId)->update([
        'title' => 'delta title',
        'body' => 'echo body',
        'source_schema' => 'foxtrot schema',
        'updated_at' => now(),
    ]);

    expect(searchVectorMatches($documentId, 'delta & echo & foxtrot'))->toBeTrue();

    $distance = DB::selectOne("SELECT '[1,0,0]'::vector <=> '[0,1,0]'::vector AS distance")->distance;
    expect((float) $distance)->toBeGreaterThan(0.0);
});

function searchVectorMatches(string $documentId, string $query): bool
{
    return DB::table('hades_search_documents')
        ->where('id', $documentId)
        ->whereRaw("search_vector @@ to_tsquery('english', ?)", [$query])
        ->exists();
}
