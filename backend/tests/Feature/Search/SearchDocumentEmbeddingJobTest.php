<?php

use App\Assistants\ProviderEndpointPolicy;
use App\Assistants\ProviderHostResolver;
use App\Contracts\EmbeddingGenerator;
use App\Jobs\GenerateSearchDocumentEmbedding;
use App\Services\Hades\HadesSearchDocumentIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('devboard.embeddings.enabled', true);
    config()->set('devboard.embeddings.provider', 'openai');
    config()->set('devboard.embeddings.model', 'text-embedding-3-small');
    config()->set('devboard.embeddings.dimensions', 3);
    config()->set('devboard.embeddings.timeout', 30);
});

it('dispatches embedding generation after search document commit', function (): void {
    Queue::fake();
    $projectId = seedEmbeddingJobProject();
    $sourceId = (string) Str::ulid();

    DB::transaction(function () use ($projectId, $sourceId): void {
        app(HadesSearchDocumentIndexer::class)->indexMemoryEntry((object) [
            'id' => $sourceId,
            'project_id' => $projectId,
            'payload' => json_encode(['schema' => 'devboard.memory_note.v1', 'body' => 'semantic payload'], JSON_THROW_ON_ERROR),
            'kind' => 'agent_note',
            'summary' => 'Memory summary',
            'source' => 'hades_agent',
            'agent_key' => 'hades',
            'repository_id' => null,
            'task_id' => null,
            'run_id' => null,
            'completeness' => 'complete',
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        Queue::assertNothingPushed();
    });

    Queue::assertPushed(GenerateSearchDocumentEmbedding::class, function (GenerateSearchDocumentEmbedding $job) use ($sourceId): bool {
        return $job->sourceTable === 'project_memory_entries'
            && $job->sourceId === $sourceId
            && is_string($job->expectedChecksum)
            && strlen($job->expectedChecksum) === 64;
    });
});

it('persists one valid embedding and metadata', function (): void {
    $projectId = seedEmbeddingJobProject();
    $sourceId = seedEmbeddingSearchDocument($projectId, checksum: 'checksum-a');
    app()->instance(EmbeddingGenerator::class, new FakeEmbeddingGenerator([[0.1, 0.2, 0.3]]));

    (new GenerateSearchDocumentEmbedding('wiki_revisions', $sourceId, 'checksum-a'))->handle(app(EmbeddingGenerator::class));

    $row = DB::table('hades_search_documents')->where('source_id', $sourceId)->first();
    $metadata = json_decode((string) $row->metadata, true, flags: JSON_THROW_ON_ERROR);

    expect($row->embedding_status)->toBe('ready');
    expect($row->embedding_model)->toBe('text-embedding-3-small');
    expect($row->embedding_dimensions)->toBe(3);
    expect($row->embedding_checksum)->toBe('checksum-a');
    expect($metadata['embedding']['status'])->toBe('ready');
});

it('generates a new embedding when the source checksum changes', function (): void {
    $projectId = seedEmbeddingJobProject();
    $sourceId = seedEmbeddingSearchDocument($projectId, checksum: 'checksum-a');
    app()->instance(EmbeddingGenerator::class, new FakeEmbeddingGenerator([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]));

    (new GenerateSearchDocumentEmbedding('wiki_revisions', $sourceId, 'checksum-a'))->handle(app(EmbeddingGenerator::class));

    DB::table('hades_search_documents')->where('source_id', $sourceId)->update([
        'body' => 'Changed body',
        'checksum' => 'checksum-b',
    ]);

    (new GenerateSearchDocumentEmbedding('wiki_revisions', $sourceId, 'checksum-b'))->handle(app(EmbeddingGenerator::class));

    expect(DB::table('hades_search_documents')->where('source_id', $sourceId)->value('embedding_checksum'))->toBe('checksum-b');
});

it('does not allow a stale job to overwrite a newer checksum', function (): void {
    $projectId = seedEmbeddingJobProject();
    $sourceId = seedEmbeddingSearchDocument($projectId, checksum: 'checksum-new');
    app()->instance(EmbeddingGenerator::class, new FakeEmbeddingGenerator([[0.1, 0.2, 0.3]]));

    (new GenerateSearchDocumentEmbedding('wiki_revisions', $sourceId, 'checksum-old'))->handle(app(EmbeddingGenerator::class));

    $row = DB::table('hades_search_documents')->where('source_id', $sourceId)->first();

    expect($row->embedding_status)->not->toBe('ready');
    expect($row->embedding_checksum)->toBeNull();
});

it('keeps the lexical document available and records degraded state on provider failure', function (): void {
    $projectId = seedEmbeddingJobProject();
    $sourceId = seedEmbeddingSearchDocument($projectId, checksum: 'checksum-a');
    app()->instance(EmbeddingGenerator::class, new FakeEmbeddingGenerator(exception: new RuntimeException('provider unavailable')));

    (new GenerateSearchDocumentEmbedding('wiki_revisions', $sourceId, 'checksum-a'))->handle(app(EmbeddingGenerator::class));

    $row = DB::table('hades_search_documents')->where('source_id', $sourceId)->first();

    expect($row)->not->toBeNull();
    expect($row->body)->toContain('Searchable lexical content');
    expect($row->embedding_status)->toBe('degraded');
});

it('rejects an unsafe stored provider endpoint before sending an embedding request', function (): void {
    Http::preventStrayRequests();
    app()->instance(ProviderHostResolver::class, new class implements ProviderHostResolver
    {
        public function resolve(string $host): array
        {
            return ['127.0.0.1'];
        }
    });
    app()->forgetInstance(ProviderEndpointPolicy::class);

    config()->set('devboard.embeddings.base_url', 'https://unsafe-provider.test/v1');
    $projectId = seedEmbeddingJobProject();
    $sourceId = seedEmbeddingSearchDocument($projectId, checksum: 'checksum-a');

    (new GenerateSearchDocumentEmbedding('wiki_revisions', $sourceId, 'checksum-a'))->handle(app(EmbeddingGenerator::class));

    expect(DB::table('hades_search_documents')->where('source_id', $sourceId)->value('embedding_status'))->toBe('degraded');
});

it('rejects wrong dimensions strings nan and infinity', function (array $embedding): void {
    $projectId = seedEmbeddingJobProject();
    $sourceId = seedEmbeddingSearchDocument($projectId, checksum: 'checksum-a');
    app()->instance(EmbeddingGenerator::class, new FakeEmbeddingGenerator([$embedding]));

    (new GenerateSearchDocumentEmbedding('wiki_revisions', $sourceId, 'checksum-a'))->handle(app(EmbeddingGenerator::class));

    expect(DB::table('hades_search_documents')->where('source_id', $sourceId)->value('embedding_status'))->toBe('degraded');
})->with([
    'wrong dimensions' => [[0.1, 0.2]],
    'strings' => [[0.1, '0.2', 0.3]],
    'nan' => [[0.1, NAN, 0.3]],
    'infinity' => [[0.1, INF, 0.3]],
]);

it('backfills embeddings from the reindex command', function (): void {
    $projectId = seedEmbeddingJobProject();
    $memoryId = (string) Str::ulid();
    seedEmbeddingMemoryEntry($projectId, $memoryId);
    app()->instance(EmbeddingGenerator::class, new FakeEmbeddingGenerator([[0.1, 0.2, 0.3]]));

    $exitCode = Artisan::call('hades:search-documents-reindex', [
        '--project' => $projectId,
        '--domain' => ['memory'],
        '--embeddings' => true,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(DB::table('hades_search_documents')->where('source_id', $memoryId)->value('embedding_status'))->toBe('ready');
});

it('indexes run summaries', function (): void {
    $projectId = seedEmbeddingJobProject();
    $runId = seedEmbeddingRun($projectId);
    $memoryId = (string) Str::ulid();
    seedEmbeddingMemoryEntry($projectId, $memoryId, $runId);

    app(HadesSearchDocumentIndexer::class)->indexMemoryEntry(DB::table('project_memory_entries')->where('id', $memoryId)->first());

    $metadata = json_decode((string) DB::table('hades_search_documents')->where('source_id', $memoryId)->value('metadata'), true, flags: JSON_THROW_ON_ERROR);

    expect($metadata['run_id'])->toBe($runId);
    expect(DB::table('hades_search_documents')->where('source_id', $memoryId)->value('body'))->toContain('Run completed successfully');
});

class FakeEmbeddingGenerator implements EmbeddingGenerator
{
    /** @param list<list<mixed>> $responses */
    public function __construct(private array $responses = [], private ?Throwable $exception = null) {}

    /** @return list<float> */
    public function generate(string $input): array
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return array_shift($this->responses) ?? [0.1, 0.2, 0.3];
    }
}

function seedEmbeddingJobProject(): string
{
    $userId = DB::table('users')->insertGetId([
        'name' => 'Embedding User',
        'email' => 'embedding-'.Str::lower(Str::random(8)).'@example.test',
        'password' => 'test',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $projectId = (string) Str::ulid();
    DB::table('projects')->insert([
        'id' => $projectId,
        'name' => 'Embedding Project',
        'slug' => 'embedding-'.Str::lower(Str::random(8)),
        'description' => null,
        'status' => 'active',
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $projectId;
}

function seedEmbeddingSearchDocument(string $projectId, string $checksum): string
{
    $sourceId = (string) Str::ulid();
    DB::table('hades_search_documents')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $projectId,
        'workspace_binding_id' => null,
        'domain' => 'wiki',
        'kind' => 'wiki',
        'source_table' => 'wiki_revisions',
        'source_id' => $sourceId,
        'source_schema' => 'devboard.wiki_revision.v1',
        'title' => 'Search document',
        'body' => 'Searchable lexical content for embeddings.',
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'checksum' => $checksum,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $sourceId;
}

function seedEmbeddingMemoryEntry(string $projectId, string $memoryId, ?string $runId = null): void
{
    DB::table('project_memory_entries')->insert([
        'id' => $memoryId,
        'project_id' => $projectId,
        'repository_id' => null,
        'task_id' => null,
        'run_id' => $runId,
        'kind' => 'agent_note',
        'summary' => 'Run completed successfully',
        'payload' => json_encode(['schema' => 'devboard.run_summary.v1', 'summary' => 'Run completed successfully'], JSON_THROW_ON_ERROR),
        'source' => 'hades_agent',
        'agent_key' => 'hades',
        'completeness' => 'complete',
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function seedEmbeddingRun(string $projectId): string
{
    $repositoryId = (string) Str::ulid();
    DB::table('repositories')->insert([
        'id' => $repositoryId,
        'project_id' => $projectId,
        'name' => 'repo',
        'slug' => 'repo-'.Str::lower(Str::random(8)),
        'default_branch' => 'main',
        'local_only' => true,
        'code_exposure_policy' => 'full_code_artifacts',
        'protected_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'excluded_paths' => json_encode([], JSON_THROW_ON_ERROR),
        'stack_hints' => json_encode([], JSON_THROW_ON_ERROR),
        'graph_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $userId = DB::table('projects')->where('id', $projectId)->value('created_by_user_id');
    $deviceId = (string) Str::ulid();
    DB::table('devices')->insert([
        'id' => $deviceId,
        'user_id' => $userId,
        'name' => 'Embedding Device',
        'fingerprint_hash' => hash('sha256', $deviceId),
        'platform_os' => 'linux',
        'platform_arch' => 'amd64',
        'plugin_version' => 'test',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = (string) Str::ulid();
    DB::table('runs')->insert([
        'id' => $runId,
        'project_id' => $projectId,
        'repository_id' => $repositoryId,
        'local_workspace_id' => null,
        'task_id' => null,
        'device_id' => $deviceId,
        'started_by_user_id' => $userId,
        'runtime_profile' => 'test',
        'status' => 'completed',
        'branch' => 'main',
        'base_branch' => 'main',
        'base_sha' => str_repeat('a', 40),
        'head_sha' => str_repeat('b', 40),
        'summary' => 'Run completed successfully',
        'risk_level' => 'low',
        'started_at' => now(),
        'finished_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $runId;
}
