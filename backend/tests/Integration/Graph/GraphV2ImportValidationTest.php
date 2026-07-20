<?php

use App\Jobs\AcquireGraphV2ValidationRun;
use App\Jobs\ValidateGraphV2Import;
use App\Models\HadesGraphImport;
use App\Models\HadesGraphImportChunk;
use App\Models\User;
use App\Services\ArtifactStorageService;
use App\Services\Graph\V2\GraphV2ArtifactReader;
use App\Services\Graph\V2\GraphV2ArtifactReaderContract;
use App\Services\Graph\V2\GraphV2Canonicalizer;
use App\Services\Graph\V2\GraphV2ChunkValidator;
use App\Services\Graph\V2\GraphV2IdentityValidator;
use App\Services\Graph\V2\GraphV2ImportException;
use App\Services\Graph\V2\GraphV2ImportService;
use App\Services\Graph\V2\GraphV2InfrastructureException;
use App\Services\Graph\V2\GraphV2JsonSchemaValidator;
use App\Services\Graph\V2\GraphV2ManifestValidator;
use App\Services\Graph\V2\GraphV2Normalizer;
use App\Services\Graph\V2\GraphV2NormalizerContract;
use App\Services\Graph\V2\GraphV2StoredChunkReader;
use App\Services\Graph\V2\GraphV2StoredChunkReaderContract;
use App\Services\Graph\V2\GraphV2ValidationRunService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

final class GraphV2FailingReadStream
{
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): false
    {
        return false;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return ['size' => 0];
    }
}

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
});

it('uses exactly two streaming passes with batches capped at one thousand', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    $runToken = str_repeat('t', 64);
    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'validation_attempts' => 1,
        'validation_run_token_hash' => hash('sha256', $runToken),
        'validation_lease_expires_at' => now()->addMinutes(5),
    ]);
    $runs = app(GraphV2ValidationRunService::class);
    $batches = [array_fill(0, 1000, (object) ['id' => 'record'])];
    $reader = new class($batches) implements GraphV2ArtifactReaderContract
    {
        public int $passes = 0;

        public function __construct(private readonly array $batches) {}

        public function batches(HadesGraphImport $import): iterable
        {
            $this->passes++;

            return $this->batches;
        }
    };
    $normalizer = new class implements GraphV2NormalizerContract
    {
        public int $firstPasses = 0;

        public int $secondPasses = 0;

        public function passOne(HadesGraphImport $import, iterable $batches, Closure $heartbeat): void
        {
            $this->firstPasses++;
        }

        public function passTwo(HadesGraphImport $import, iterable $batches, Closure $heartbeat): array
        {
            $this->secondPasses++;

            return ['artifact_graph_version' => str_repeat('a', 64)];
        }
    };

    (new ValidateGraphV2Import($import->id, 1, 1, $runToken))
        ->handle($reader, $normalizer, $runs);

    expect($reader->passes)->toBe(2)
        ->and($normalizer->firstPasses)->toBe(1)
        ->and($normalizer->secondPasses)->toBe(1);
});

it('acquires four separately tokenized five minute validation leases and dispatches encrypted single-try jobs', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    $service = app(GraphV2ValidationRunService::class);
    $jobs = [];

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        if ($attempt > 1) {
            DB::table('hades_graph_imports')->where('id', $import->id)->update([
                'validation_lease_expires_at' => now()->subSecond(),
            ]);
        }

        expect($service->acquireAndDispatch($import))->toBeTrue();
    }

    Bus::assertDispatched(ValidateGraphV2Import::class, function (ValidateGraphV2Import $job) use (&$jobs, $import): bool {
        $jobs[] = $job;

        return $job->importId === $import->id && $job->attemptGeneration === 1;
    });

    expect($jobs)->toHaveCount(4)
        ->and(collect($jobs)->pluck('runToken')->unique())->toHaveCount(4)
        ->and($jobs[0])->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($jobs[0]->tries)->toBe(1)
        ->and(DB::table('hades_graph_imports')->where('id', $import->id)->value('validation_run_token_hash'))
        ->toMatch('/\A[0-9a-f]{64}\z/');
});

it('uses token and import identity CAS for heartbeats and rejects reclaimed workers', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    $service = app(GraphV2ValidationRunService::class);

    expect($service->acquireAndDispatch($import))->toBeTrue();
    $first = DB::table('hades_graph_imports')->where('id', $import->id)->first();
    $firstToken = 'first-run-token';
    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'validation_run_token_hash' => hash('sha256', $firstToken),
        'validation_lease_expires_at' => now()->addMinutes(5),
    ]);
    expect($service->heartbeat($import->id, 1, 1, $firstToken))->toBeTrue();
    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'validation_lease_expires_at' => now()->subSecond(),
    ]);

    expect($service->acquireAndDispatch($import))->toBeTrue();
    $second = DB::table('hades_graph_imports')->where('id', $import->id)->first();
    expect($service->heartbeat($import->id, 1, 1, $firstToken))->toBeFalse()
        ->and($second->validation_run_token_hash)->not->toBe(hash('sha256', $firstToken));
});

it('does not heartbeat or terminally complete an expired lease', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    $service = app(GraphV2ValidationRunService::class);
    $token = 'expired-lease-token';
    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'validation_attempts' => 1,
        'validation_run_token_hash' => hash('sha256', $token),
        'validation_lease_expires_at' => now()->subSecond(),
    ]);

    expect($service->heartbeat($import->id, 1, 1, $token))->toBeFalse()
        ->and($service->recordSuccess($import->id, 1, 1, $token))->toBeFalse()
        ->and($service->recordTransientFailure($import->id, 1, 1, $token, 'storage_unavailable'))->toBeFalse()
        ->and(DB::table('hades_graph_imports')->where('id', $import->id)->value('status'))->toBe(HadesGraphImport::STATUS_VALIDATING)
        ->and(DB::table('canonical_graph_projection_heads')->where('project_id', $import->project_id)->exists())->toBeFalse();
});

it('does not let a reclaimed worker publish success, failure, or a projection request', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    $service = app(GraphV2ValidationRunService::class);
    expect($service->acquireAndDispatch($import))->toBeTrue();
    $old = DB::table('hades_graph_imports')->where('id', $import->id)->first();
    DB::table('hades_graph_imports')->where('id', $import->id)->update(['validation_lease_expires_at' => now()->subSecond()]);
    expect($service->acquireAndDispatch($import))->toBeTrue();

    expect($service->recordSuccess($import->id, 1, 1, 'old-token'))->toBeFalse()
        ->and($service->recordTransientFailure($import->id, 1, 1, 'old-token', 'storage_unavailable'))->toBeFalse();
    expect(DB::table('hades_graph_imports')->where('id', $import->id)->value('status'))->toBe(HadesGraphImport::STATUS_VALIDATING)
        ->and(DB::table('canonical_graph_projection_heads')->where('project_id', $import->project_id)->exists())->toBeFalse()
        ->and($old->validation_run_token_hash)->not->toBe(DB::table('hades_graph_imports')->where('id', $import->id)->value('validation_run_token_hash'));
});

it('schedules only the acquisition wrapper after ten, thirty, and ninety seconds for transient runs', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    $service = app(GraphV2ValidationRunService::class);

    foreach ([1 => 10, 2 => 30, 3 => 90] as $attempt => $delay) {
        DB::table('hades_graph_imports')->where('id', $import->id)->update([
            'validation_attempts' => $attempt,
            'validation_run_token_hash' => hash('sha256', "token-{$attempt}"),
            'validation_lease_expires_at' => now()->addMinutes(5),
        ]);
        expect($service->recordTransientFailure($import->id, 1, $attempt, "token-{$attempt}", 'storage_unavailable'))->toBeTrue();
        Bus::assertDispatched(AcquireGraphV2ValidationRun::class, fn (AcquireGraphV2ValidationRun $job): bool => $job->delay === $delay);
        expect(HadesGraphImport::query()->findOrFail($import->id)->failure_details)->toBeArray()
            ->and(data_get(HadesGraphImport::query()->findOrFail($import->id)->failure_details, 'next_eligible_at'))->toBeString();
    }
});

it('fails deterministic validation immediately and makes run four infrastructure failure terminal', function (): void {
    $import = validationImportFixture();
    $service = app(GraphV2ValidationRunService::class);

    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'validation_attempts' => 1,
        'validation_run_token_hash' => hash('sha256', 'deterministic'),
        'validation_lease_expires_at' => now()->addMinutes(5),
    ]);
    expect($service->recordDeterministicFailure($import->id, 1, 1, 'deterministic', 'graph_record_invalid'))->toBeTrue();
    expect(DB::table('hades_graph_imports')->where('id', $import->id)->value('status'))->toBe('failed');

    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'status' => 'validating', 'validation_attempts' => 4,
        'validation_run_token_hash' => hash('sha256', 'transient-final'),
        'validation_lease_expires_at' => now()->addMinutes(5),
    ]);
    expect($service->recordTransientFailure($import->id, 1, 4, 'transient-final', 'storage_unavailable'))->toBeTrue();
    expect(DB::table('hades_graph_imports')->where('id', $import->id)->value('failure_code'))
        ->toBe('graph_validation_infrastructure_failed');
});

it('requests projection only after a successful validation CAS', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    $runs = app(GraphV2ValidationRunService::class);
    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'validation_attempts' => 1,
        'validation_run_token_hash' => hash('sha256', 'success'),
        'validation_lease_expires_at' => now()->addMinutes(5),
    ]);

    expect($runs->recordSuccess($import->id, 1, 1, 'success'))->toBeTrue();
    expect(DB::table('hades_graph_imports')->where('id', $import->id)->value('status'))->toBe('validated')
        ->and(DB::table('canonical_graph_projection_heads')->where('project_id', $import->project_id)->where('source_scope_id', $import->workspace_binding_id)->exists())->toBeTrue();
});

it('classifies deterministic graph validation exceptions before runtime exceptions at the job boundary', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    $token = 'deterministic-job-token';
    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'validation_attempts' => 1,
        'validation_run_token_hash' => hash('sha256', $token),
        'validation_lease_expires_at' => now()->addMinutes(5),
    ]);

    $reader = new class implements GraphV2ArtifactReaderContract
    {
        public function __construct() {}

        public function batches(HadesGraphImport $import): iterable
        {
            return [];
        }
    };
    $normalizer = new class implements GraphV2NormalizerContract
    {
        public function passOne(HadesGraphImport $import, iterable $batches, Closure $heartbeat): void
        {
            throw new GraphV2ImportException('graph_validation_count_mismatch', 'count mismatch');
        }

        public function passTwo(HadesGraphImport $import, iterable $batches, Closure $heartbeat): array
        {
            return ['artifact_graph_version' => $import->artifact_graph_version];
        }
    };

    (new ValidateGraphV2Import($import->id, 1, 1, $token))->handle($reader, $normalizer, app(GraphV2ValidationRunService::class));

    expect(DB::table('hades_graph_imports')->where('id', $import->id)->value('status'))->toBe(HadesGraphImport::STATUS_FAILED)
        ->and(DB::table('hades_graph_imports')->where('id', $import->id)->value('failure_code'))->toBe('graph_validation_count_mismatch')
        ->and(Bus::dispatched(AcquireGraphV2ValidationRun::class))->toHaveCount(0);
});

it('keeps record ordinals cumulative when one chunk crosses the batch boundary', function (): void {
    $import = validationImportFixture();
    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $counts['nodes'] = 1001;
    $import->manifest = ['counts' => $counts, 'graph_contract' => ['completeness' => [], 'coverage' => []]];
    $import->save();

    $all = [];
    for ($i = 0; $i < 1001; $i++) {
        $identity = (object) ['variant' => 'source_declaration', 'workspace_binding_id' => $import->workspace_binding_id, 'language' => 'php', 'kind' => 'service', 'namespace' => null, 'qualified_name' => sprintf('Node%04d', $i), 'path' => sprintf('src/Node%04d.php', $i)];
        $all[] = (object) ['id' => app(GraphV2IdentityValidator::class)->nodeId($identity), 'identity' => $identity, 'kind' => 'service', 'language' => 'php'];
    }
    usort($all, static fn (object $left, object $right): int => strcmp($left->id, $right->id));
    $first = array_slice($all, 0, 1000);
    $second = array_slice($all, 1000);

    app(GraphV2Normalizer::class)->passOne($import, [
        ['kind' => 'nodes', 'index' => 0, 'records' => $first],
        ['kind' => 'nodes', 'index' => 0, 'records' => $second],
    ], static function (bool $force = false): void {});

    expect(DB::table('hades_graph_import_record_keys')->where('graph_import_id', $import->id)->where('public_id', $second[0]->id)->value('record_ordinal'))->toBe(1000);
});

it('rejects duplicate file paths and file-node identities through deterministic import failures', function (): void {
    $import = validationImportFixture();
    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $counts['nodes'] = 2;
    $import->manifest = ['counts' => $counts, 'graph_contract' => ['completeness' => [], 'coverage' => []]];
    $import->save();

    $record = fn (string $id): object => (object) [
        'id' => $id,
        'kind' => 'file',
        'identity' => (object) [
            'variant' => 'file', 'workspace_binding_id' => $import->workspace_binding_id,
            'language' => 'php', 'kind' => 'file', 'path' => 'src/App.php',
        ],
        'properties' => (object) ['file_sha256' => str_repeat('a', 64)],
    ];

    expect(fn () => app(GraphV2Normalizer::class)->passOne($import, [[
        'kind' => 'nodes', 'index' => 0, 'records' => [
            $record('hades:node:v2:'.str_repeat('1', 64)),
            $record('hades:node:v2:'.str_repeat('2', 64)),
        ],
    ]], static function (bool $force = false): void {}))
        ->toThrow(GraphV2ImportException::class, 'identity');
});

it('allows all source declaration owner variants without a short node-kind allowlist', function (): void {
    $import = validationImportFixture();
    $ownerId = 'hades:node:v2:'.str_repeat('l', 64);
    $continuationId = 'hades:node:v2:'.str_repeat('c', 64);
    $structureId = 'hades:call-site:v2:'.str_repeat('s', 64);
    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $counts['nodes'] = 0;
    $counts['structures'] = 1;
    $import->manifest = ['counts' => $counts, 'graph_contract' => ['completeness' => [], 'coverage' => []]];
    $import->save();

    DB::table('hades_graph_import_record_keys')->insert([
        ['graph_import_id' => $import->id, 'record_kind' => 'nodes', 'public_id' => $ownerId, 'record_subkind' => 'listener', 'identity_variant' => 'source_declaration', 'chunk_index' => 0, 'record_ordinal' => 0],
        ['graph_import_id' => $import->id, 'record_kind' => 'nodes', 'public_id' => $continuationId, 'record_subkind' => 'basic_block', 'identity_variant' => 'source_occurrence', 'chunk_index' => 0, 'record_ordinal' => 1],
        ['graph_import_id' => $import->id, 'record_kind' => 'structures', 'public_id' => $structureId, 'record_subkind' => 'call_site', 'owner_public_id' => $ownerId, 'chunk_index' => 0, 'record_ordinal' => 0],
    ]);
    DB::table('hades_graph_import_file_paths')->insert([
        'graph_import_id' => $import->id, 'path' => 'src/Listener.php', 'file_node_public_id' => 'hades:node:v2:'.str_repeat('f', 64), 'file_sha256' => str_repeat('a', 64),
    ]);
    $record = (object) [
        'id' => $structureId, 'kind' => 'call_site', 'owner_node_id' => $ownerId, 'continuation_node_id' => $continuationId, 'parent_structure_id' => null,
        'evidence' => (object) ['primary' => (object) [
            'origin' => 'verified_from_code', 'extractor' => 'test',
            'source_locator' => (object) ['kind' => 'ast', 'path' => 'src/Listener.php', 'structural_path' => 'listener/handle/call/0'],
            'source_fingerprint' => app(GraphV2Canonicalizer::class)->sha256(['file_sha256' => str_repeat('a', 64), 'occurrence_kind' => 'ast', 'path' => 'src/Listener.php', 'structural_path' => 'listener/handle/call/0']),
            'inference_rule' => null,
        ], 'supporting' => [], 'supporting_omitted_count' => 0],
    ];

    expect(fn () => app(GraphV2Normalizer::class)->passTwo($import, [['kind' => 'structures', 'index' => 0, 'records' => [$record]]], static function (bool $force = false): void {}))
        ->not->toThrow(Throwable::class);
});

it('updates the monotonic heartbeat anchor only after successful heartbeats', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    $token = 'heartbeat-cadence-token';
    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'validation_attempts' => 1,
        'validation_run_token_hash' => hash('sha256', $token),
        'validation_lease_expires_at' => now()->addMinutes(5),
    ]);

    $monotonicNs = 0;
    $observedHeartbeats = 0;
    $reader = new class implements GraphV2ArtifactReaderContract
    {
        public function __construct() {}

        public function batches(HadesGraphImport $import): iterable
        {
            return [];
        }
    };
    $normalizer = new class($monotonicNs) implements GraphV2NormalizerContract
    {
        public function __construct(private int &$monotonicNs) {}

        public function passOne(HadesGraphImport $import, iterable $batches, Closure $heartbeat): void
        {
            $heartbeat(true);
            $this->monotonicNs = 31_000_000_000;
            $heartbeat(false);
            $this->monotonicNs = 31_100_000_000;
            $heartbeat(false);
            $this->monotonicNs = 62_000_000_000;
            $heartbeat(false);
        }

        public function passTwo(HadesGraphImport $import, iterable $batches, Closure $heartbeat): array
        {
            return ['artifact_graph_version' => $import->artifact_graph_version];
        }
    };

    (new ValidateGraphV2Import($import->id, 1, 1, $token))->handle(
        $reader,
        $normalizer,
        app(GraphV2ValidationRunService::class),
        static function () use (&$monotonicNs): int {
            return $monotonicNs;
        },
        static function () use (&$observedHeartbeats): void {
            $observedHeartbeats++;
        },
    );

    expect($observedHeartbeats)->toBe(5);
});

it('streams a real retained gzip chunk through two bounded reader passes', function (): void {
    Bus::fake();
    Storage::fake('local');
    $import = validationImportFixture();
    $records = [];
    for ($i = 0; $i < 1001; $i++) {
        $path = 'src/'.$i.'.php';
        $records[] = [
            'id' => 'hades:node:v2:'.str_pad((string) $i, 64, '0', STR_PAD_LEFT),
            'identity' => ['variant' => 'file', 'workspace_binding_id' => $import->workspace_binding_id, 'language' => 'php', 'kind' => 'file', 'path' => $path],
            'kind' => 'file', 'language' => 'php', 'framework' => null, 'name' => 'file-'.$i, 'qualified_name' => null, 'namespace' => null,
            'uncertainty_id' => null, 'location' => null,
            'properties' => ['file_sha256' => str_repeat('c', 64), 'byte_size' => 1, 'analysis_status' => 'analyzed', 'omission_reason' => null, 'is_test' => false, 'is_generated' => false],
            'evidence' => ['primary' => ['origin' => 'verified_from_code', 'extractor' => 'test', 'source_locator' => ['kind' => 'file', 'path' => $path], 'source_fingerprint' => app(GraphV2Canonicalizer::class)->sha256(['file_sha256' => str_repeat('c', 64), 'occurrence_kind' => 'file', 'path' => $path]), 'inference_rule' => null], 'supporting' => [], 'supporting_omitted_count' => 0],
        ];
    }
    $chunk = ['schema' => 'hades.graph_chunk.v2', 'index' => 0, 'kind' => 'nodes', 'records' => $records];
    $canonical = app(GraphV2Canonicalizer::class)->canonicalJson($chunk);
    $gzip = gzencode($canonical, 6, FORCE_GZIP);
    $gzip[9] = chr(255);
    $descriptor = ['index' => 0, 'kind' => 'nodes', 'record_count' => 1001, 'sha256' => hash('sha256', $canonical), 'uncompressed_bytes' => strlen($canonical), 'compression' => 'gzip', 'compressed_sha256' => hash('sha256', $gzip), 'compressed_bytes' => strlen($gzip)];
    $path = app(ArtifactStorageService::class)->graphChunkPath($import->id, 0);
    Storage::disk('local')->put($path, $gzip);
    HadesGraphImportChunk::query()->create([
        'id' => (string) Str::ulid(), 'graph_import_id' => $import->id, 'chunk_index' => 0, 'kind' => 'nodes',
        'sha256' => $descriptor['sha256'], 'record_count' => 1001, 'uncompressed_bytes' => $descriptor['uncompressed_bytes'],
        'compression' => 'gzip', 'compressed_sha256' => $descriptor['compressed_sha256'], 'compressed_bytes' => $descriptor['compressed_bytes'],
        'storage_disk' => 'local', 'storage_path' => $path, 'received_at' => now(),
    ]);
    $import->manifest = [
        'schema' => 'hades.graph_bundle.v2', 'artifact_schema' => 'hades.code_graph.v2', 'artifact_graph_version' => $import->artifact_graph_version,
        'project' => ['project_id' => $import->project_id, 'workspace_binding_id' => $import->workspace_binding_id], 'source' => $import->source_identity,
        'counts' => ['entrypoints' => 0, 'nodes' => 1001, 'structures' => 0, 'edges' => 0, 'flows' => 0, 'flow_steps' => 0, 'uncertainties' => 0],
        'chunks' => [$descriptor],
    ];
    $import->save();
    $token = 'stored-reader-token';
    DB::table('hades_graph_imports')->where('id', $import->id)->update(['validation_attempts' => 1, 'validation_run_token_hash' => hash('sha256', $token), 'validation_lease_expires_at' => now()->addMinutes(5)]);
    $reader = new GraphV2ArtifactReader(app(ArtifactStorageService::class), new GraphV2StoredChunkReader(app(GraphV2ChunkValidator::class)));
    $normalizer = new class implements GraphV2NormalizerContract
    {
        /** @var list<list<int>> */
        public array $batchSizes = [];

        public function passOne(HadesGraphImport $import, iterable $batches, Closure $heartbeat): void
        {
            $this->batchSizes[] = array_map(static fn (array $batch): int => count($batch['records']), iterator_to_array($batches));
        }

        public function passTwo(HadesGraphImport $import, iterable $batches, Closure $heartbeat): array
        {
            $this->batchSizes[] = array_map(static fn (array $batch): int => count($batch['records']), iterator_to_array($batches));

            return ['artifact_graph_version' => $import->artifact_graph_version];
        }
    };
    (new ValidateGraphV2Import($import->id, 1, 1, $token))->handle($reader, $normalizer, app(GraphV2ValidationRunService::class));

    expect($normalizer->batchSizes)->toBe([[1000, 1], [1000, 1]]);
});

it('rejects stored identity and declared count mismatches on the real validation path', function (): void {
    $import = validationImportFixture();
    expect(fn () => iterator_to_array(app(GraphV2ArtifactReader::class)->batches($import)))
        ->toThrow(GraphV2ImportException::class, 'identity');

    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $counts['nodes'] = 1;
    $import->manifest = ['counts' => $counts, 'graph_contract' => ['completeness' => [], 'coverage' => []]];
    $import->save();
    expect(fn () => app(GraphV2Normalizer::class)->passOne($import, [
        ['kind' => 'nodes', 'index' => 0, 'records' => []],
    ], static function (bool $force = false): void {}))->toThrow(GraphV2ImportException::class, 'counts');
});

it('uses Laravel unique delivery for one encrypted validation run key', function (): void {
    $job = new ValidateGraphV2Import('import-1', 7, 3, 'secret-token');

    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('graph-import:import-1:7:validation:3')
        ->and($job->uniqueFor)->toBe(GraphV2ValidationRunService::LEASE_SECONDS);
});

it('lets unknown programming errors escape instead of recording them as transient infrastructure failures', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    $token = 'unknown-programming-error';
    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'validation_attempts' => 1,
        'validation_run_token_hash' => hash('sha256', $token),
        'validation_lease_expires_at' => now()->addMinutes(5),
    ]);
    $reader = new class implements GraphV2ArtifactReaderContract
    {
        public function batches(HadesGraphImport $import): iterable
        {
            return [];
        }
    };
    $normalizer = new class implements GraphV2NormalizerContract
    {
        public function passOne(HadesGraphImport $import, iterable $batches, Closure $heartbeat): void
        {
            throw new TypeError('unexpected programming bug');
        }

        public function passTwo(HadesGraphImport $import, iterable $batches, Closure $heartbeat): array
        {
            return ['artifact_graph_version' => $import->artifact_graph_version];
        }
    };

    expect(fn () => (new ValidateGraphV2Import($import->id, 1, 1, $token))
        ->handle($reader, $normalizer, app(GraphV2ValidationRunService::class)))
        ->toThrow(TypeError::class, 'unexpected programming bug');
    expect(DB::table('hades_graph_imports')->where('id', $import->id)->value('status'))
        ->toBe(HadesGraphImport::STATUS_VALIDATING);
});

it('reconciles expired crashed validation runs only after their persisted retry delay', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'validation_attempts' => 1,
        'validation_run_token_hash' => hash('sha256', 'crashed-run'),
        'validation_lease_expires_at' => now()->subSecond(),
    ]);
    $service = app(GraphV2ValidationRunService::class);

    expect($service->reconcileExpiredLeases())->toBe(0)
        ->and(Bus::dispatched(AcquireGraphV2ValidationRun::class))->toHaveCount(0);

    Carbon::setTestNow(now()->addSeconds(11));
    expect($service->reconcileExpiredLeases())->toBe(1)
        ->and(Bus::dispatched(AcquireGraphV2ValidationRun::class))->toHaveCount(1);
    Carbon::setTestNow();
});

it('does not dispatch early when reconciliation repeats before each persisted retry delay', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    $service = app(GraphV2ValidationRunService::class);
    $base = Carbon::parse('2026-07-20 12:00:00');
    Carbon::setTestNow($base);

    foreach ([1 => 10, 2 => 30, 3 => 90] as $attempt => $delay) {
        $eligibleAt = $base->copy()->addSeconds($delay);
        DB::table('hades_graph_imports')->where('id', $import->id)->update([
            'status' => HadesGraphImport::STATUS_VALIDATING,
            'validation_attempts' => $attempt,
            'validation_run_token_hash' => hash('sha256', "delayed-{$attempt}"),
            'validation_lease_expires_at' => $base->copy()->subSecond(),
            'failure_details' => json_encode([
                'reason' => 'validation_lease_expired',
                'next_eligible_at' => $eligibleAt->toISOString(),
            ], JSON_THROW_ON_ERROR),
        ]);

        expect($service->reconcileExpiredLeases())->toBe(0)
            ->and($service->reconcileExpiredLeases())->toBe(0)
            ->and(Bus::dispatched(AcquireGraphV2ValidationRun::class))->toHaveCount($attempt - 1);

        Carbon::setTestNow($eligibleAt->copy()->addSecond());
        expect($service->reconcileExpiredLeases())->toBe(1)
            ->and(Bus::dispatched(AcquireGraphV2ValidationRun::class))->toHaveCount($attempt);
        Carbon::setTestNow($base);
    }

    Carbon::setTestNow();
});

it('terminally reconciles a crashed fourth validation run without leaving validating state', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'validation_attempts' => 4,
        'validation_run_token_hash' => hash('sha256', 'crashed-fourth-run'),
        'validation_lease_expires_at' => now()->subSecond(),
    ]);

    expect(app(GraphV2ValidationRunService::class)->reconcileExpiredLeases())->toBe(1)
        ->and(DB::table('hades_graph_imports')->where('id', $import->id)->value('status'))
        ->toBe(HadesGraphImport::STATUS_FAILED)
        ->and(DB::table('hades_graph_imports')->where('id', $import->id)->value('failure_code'))
        ->toBe('graph_validation_infrastructure_failed');
});

it('rebuilds a missing desired projection head from a validated import and is idempotent', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'status' => HadesGraphImport::STATUS_VALIDATED,
        'validated_at' => now(),
    ]);
    $service = app(GraphV2ValidationRunService::class);

    expect($service->reconcileValidatedProjections())->toBe(1)
        ->and($service->reconcileValidatedProjections())->toBe(0);
    $head = DB::table('canonical_graph_projection_heads')
        ->where('project_id', $import->project_id)
        ->where('source_scope_id', $import->workspace_binding_id)
        ->first();
    expect($head)->not->toBeNull()->and($head->desired_generation)->toBe(1)
        ->and($head->desired_graph_import_id)->toBe($import->id)
        ->and((int) $head->desired_source_generation)->toBe(1);
});

it('selects the greatest scope generation regardless of timestamps, ids, or attempt generations', function (): void {
    $older = validationImportFixture(null, str_repeat('z', 64));
    $newer = validationImportFixture($older->workspace_binding_id, str_repeat('a', 64));
    $olderId = '01ZZZZZZZZZZZZZZZZZZZZZZZZ';
    $newerId = '01AAAAAAAAAAAAAAAAAAAAAAAA';
    DB::table('hades_graph_imports')->where('id', $older->id)->update(['id' => $olderId]);
    DB::table('hades_graph_imports')->where('id', $newer->id)->update(['id' => $newerId]);
    $older->id = $olderId;
    $newer->id = $newerId;
    DB::table('hades_graph_imports')->where('id', $older->id)->update([
        'status' => HadesGraphImport::STATUS_VALIDATED, 'scope_generation' => 1,
        'attempt_generation' => 99, 'validated_at' => now()->addMinute(),
    ]);
    DB::table('hades_graph_imports')->where('id', $newer->id)->update([
        'status' => HadesGraphImport::STATUS_VALIDATED, 'scope_generation' => 2,
        'attempt_generation' => 1, 'validated_at' => now()->subMinute(),
    ]);

    expect(app(GraphV2ValidationRunService::class)->reconcileValidatedProjections())->toBe(1);
    $head = DB::table('canonical_graph_projection_heads')->where('project_id', $newer->project_id)->first();
    expect($head->desired_graph_import_id)->toBe($newer->id)
        ->and((int) $head->desired_source_generation)->toBe(2)
        ->and((int) $head->desired_generation)->toBe(1);
});

it('does not let a late older projection offer regress or increment a newer head', function (): void {
    $older = validationImportFixture(null, str_repeat('b', 64));
    $newer = validationImportFixture($older->workspace_binding_id, str_repeat('c', 64));
    DB::table('hades_graph_imports')->where('id', $older->id)->update(['status' => HadesGraphImport::STATUS_VALIDATED]);
    DB::table('hades_graph_imports')->where('id', $newer->id)->update(['status' => HadesGraphImport::STATUS_VALIDATED]);
    $service = app(GraphV2ValidationRunService::class);

    expect($service->requestProjectionForValidatedImport($newer->id))->toBeTrue()
        ->and($service->requestProjectionForValidatedImport($older->id))->toBeFalse();
    $head = DB::table('canonical_graph_projection_heads')->where('project_id', $newer->project_id)->first();
    expect($head->desired_graph_import_id)->toBe($newer->id)
        ->and((int) $head->desired_source_generation)->toBe(2)
        ->and((int) $head->desired_generation)->toBe(1);
});

it('allows an older event to create an empty head, then reconciliation advances it once', function (): void {
    $older = validationImportFixture(null, str_repeat('d', 64));
    $newer = validationImportFixture($older->workspace_binding_id, str_repeat('e', 64));
    DB::table('hades_graph_imports')->where('id', $older->id)->update(['status' => HadesGraphImport::STATUS_VALIDATED]);
    DB::table('hades_graph_imports')->where('id', $newer->id)->update(['status' => HadesGraphImport::STATUS_VALIDATED]);
    $service = app(GraphV2ValidationRunService::class);

    expect($service->requestProjectionForValidatedImport($older->id))->toBeTrue();
    $head = DB::table('canonical_graph_projection_heads')->where('project_id', $older->project_id)->first();
    expect($head->desired_graph_import_id)->toBe($older->id)
        ->and($service->reconcileValidatedProjections())->toBe(1)
        ->and($service->reconcileValidatedProjections())->toBe(0);
    $head = DB::table('canonical_graph_projection_heads')->where('project_id', $older->project_id)->first();
    expect($head->desired_graph_import_id)->toBe($newer->id)->and((int) $head->desired_generation)->toBe(2);
});

it('ignores a newer failed import while reconciling the latest validated winner', function (): void {
    $validated = validationImportFixture(null, str_repeat('f', 64));
    $failed = validationImportFixture($validated->workspace_binding_id, str_repeat('0', 64));
    DB::table('hades_graph_imports')->where('id', $validated->id)->update(['status' => HadesGraphImport::STATUS_VALIDATED]);
    DB::table('hades_graph_imports')->where('id', $failed->id)->update(['status' => HadesGraphImport::STATUS_FAILED]);

    expect(app(GraphV2ValidationRunService::class)->reconcileValidatedProjections())->toBe(1);
    expect(DB::table('canonical_graph_projection_heads')->where('project_id', $validated->project_id)->value('desired_graph_import_id'))->toBe($validated->id);
});

it('repairs one winning head independently for each project binding scope', function (): void {
    $first = validationImportFixture(null, str_repeat('1', 64));
    $second = validationImportFixture(null, str_repeat('2', 64));
    DB::table('hades_graph_imports')->whereIn('id', [$first->id, $second->id])->update(['status' => HadesGraphImport::STATUS_VALIDATED]);

    expect(app(GraphV2ValidationRunService::class)->reconcileValidatedProjections())->toBe(2);
    expect(DB::table('canonical_graph_projection_heads')->where('project_id', $first->project_id)->value('desired_graph_import_id'))->toBe($first->id)
        ->and(DB::table('canonical_graph_projection_heads')->where('project_id', $second->project_id)->value('desired_graph_import_id'))->toBe($second->id)
        ->and(DB::table('canonical_graph_projection_heads')->count())->toBe(2);
});

it('targets the entrypoints staging kind for flow entrypoint IDs and accepts only normative uncertainty discriminators', function (): void {
    $import = validationImportFixture();
    $entrypointId = 'hades:node:v2:'.str_repeat('e', 64);
    $rootNodeId = 'hades:node:v2:'.str_repeat('n', 64);
    $flowRecord = (object) ['entrypoint_id' => $entrypointId, 'root_node_id' => $rootNodeId, 'kind' => 'request_lifecycle'];
    $flowId = app(GraphV2IdentityValidator::class)->flowId($flowRecord);
    DB::table('hades_graph_import_record_keys')->insert([
        ['graph_import_id' => $import->id, 'record_kind' => 'entrypoints', 'public_id' => $entrypointId, 'record_subkind' => 'http_route', 'identity_variant' => null, 'owner_public_id' => null, 'aux_public_id' => null, 'chunk_index' => 0, 'record_ordinal' => 0],
        ['graph_import_id' => $import->id, 'record_kind' => 'nodes', 'public_id' => $entrypointId, 'record_subkind' => 'entrypoint', 'identity_variant' => 'entrypoint', 'owner_public_id' => null, 'aux_public_id' => null, 'chunk_index' => 0, 'record_ordinal' => 0],
        ['graph_import_id' => $import->id, 'record_kind' => 'nodes', 'public_id' => $rootNodeId, 'record_subkind' => null, 'identity_variant' => null, 'owner_public_id' => null, 'aux_public_id' => null, 'chunk_index' => 1, 'record_ordinal' => 0],
        ['graph_import_id' => $import->id, 'record_kind' => 'flows', 'public_id' => $flowId, 'record_subkind' => null, 'identity_variant' => null, 'owner_public_id' => null, 'aux_public_id' => null, 'chunk_index' => 0, 'record_ordinal' => 0],
    ]);
    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $counts['flows'] = 1;
    $import->manifest = ['counts' => $counts, 'graph_contract' => ['completeness' => [], 'coverage' => []]];
    $import->save();

    expect(fn () => app(GraphV2Normalizer::class)->passTwo($import, [[
        'kind' => 'flows', 'index' => 0, 'records' => [(object) ['id' => $flowId, 'entrypoint_id' => $entrypointId, 'root_node_id' => $rootNodeId, 'kind' => 'request_lifecycle']],
    ]], static function (bool $force = false): void {}))->not->toThrow(Throwable::class);
    expect(DB::table('hades_graph_import_references')->where('owner_public_id', $flowId)->where('reference_kind', 'entrypoint_id')->value('target_record_kind'))->toBe('entrypoints');
    expect(DB::table('hades_graph_import_references')->where('owner_public_id', $flowId)->where('reference_kind', 'root_node_id')->value('target_record_kind'))->toBe('nodes');

    $uncertaintyCounts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $uncertaintyCounts['uncertainties'] = 1;
    $import->manifest = ['counts' => $uncertaintyCounts, 'graph_contract' => ['completeness' => [], 'coverage' => []]];
    $import->save();
    $edgeId = 'hades:edge:v2:'.str_repeat('d', 64);
    $uncertaintyRecord = (object) [
        'domain' => 'graph', 'resolution_kind' => 'not_a_normative_kind',
        'reason_code' => 'call_target_unresolved', 'question' => 'Which edge target is correct?',
        'subject' => (object) ['edge_id' => $edgeId],
    ];
    $uncertaintyId = app(GraphV2IdentityValidator::class)->uncertaintyId($uncertaintyRecord, $import);
    $uncertaintyFingerprint = substr($uncertaintyId, strlen('hades:uncertainty:v2:'));
    DB::table('hades_graph_import_record_keys')->insert([
        ['graph_import_id' => $import->id, 'record_kind' => 'edges', 'public_id' => $edgeId, 'record_subkind' => null, 'identity_variant' => null, 'owner_public_id' => null, 'aux_public_id' => null, 'chunk_index' => 0, 'record_ordinal' => 0],
        ['graph_import_id' => $import->id, 'record_kind' => 'uncertainties', 'public_id' => $uncertaintyId, 'record_subkind' => 'not_a_normative_kind', 'identity_variant' => null, 'owner_public_id' => null, 'aux_public_id' => null, 'chunk_index' => 0, 'record_ordinal' => 0],
    ]);
    expect(fn () => app(GraphV2Normalizer::class)->passTwo($import, [[
        'kind' => 'uncertainties', 'index' => 0, 'records' => [(object) [
            'id' => $uncertaintyId, 'domain' => 'graph',
            'resolution_kind' => 'not_a_normative_kind', 'reason_code' => 'call_target_unresolved', 'question' => 'Which edge target is correct?',
            'subject' => (object) ['edge_id' => $edgeId],
            'fingerprint' => $uncertaintyFingerprint,
            'candidate_target_node_ids' => [], 'candidate_edge_ids' => [],
        ]],
    ]], static function (bool $force = false): void {}))->toThrow(GraphV2ImportException::class, 'reference');
});

it('enforces field-specific structure subtypes while retaining the closed target matrix', function (): void {
    $cases = [
        ['subkind' => 'branch_group', 'valid' => true],
        ['subkind' => 'call_site', 'valid' => false],
    ];

    foreach ($cases as $case) {
        $import = validationImportFixture();
        $flowId = 'hades:flow:v2:'.str_repeat('f', 64);
        $edgeId = 'hades:edge:v2:'.str_repeat('e', 64);
        $stepRecord = (object) ['flow_id' => $flowId, 'edge_id' => $edgeId, 'stage_from' => 'entry', 'stage_to' => 'handler', 'async_context' => 'synchronous'];
        $stepId = app(GraphV2IdentityValidator::class)->flowStepId($stepRecord);
        $branchId = 'hades:branch:v2:'.str_repeat('b', 64);
        $metadata = fn (string $kind, string $id, ?string $subkind = null): array => [
            'graph_import_id' => $import->id, 'record_kind' => $kind, 'public_id' => $id, 'record_subkind' => $subkind,
            'identity_variant' => null, 'owner_public_id' => null, 'aux_public_id' => null, 'chunk_index' => 0, 'record_ordinal' => 0,
        ];
        DB::table('hades_graph_import_record_keys')->insert([
            $metadata('flows', $flowId), $metadata('edges', $edgeId), $metadata('structures', $branchId, $case['subkind']), $metadata('flow_steps', $stepId),
        ]);
        $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
        $counts['flow_steps'] = 1;
        $import->manifest = ['counts' => $counts, 'graph_contract' => ['completeness' => [], 'coverage' => []]];
        $import->save();

        $run = fn () => app(GraphV2Normalizer::class)->passTwo($import, [[
            'kind' => 'flow_steps', 'index' => 0, 'records' => [(object) ['id' => $stepId, 'flow_id' => $flowId, 'edge_id' => $edgeId, 'stage_from' => 'entry', 'stage_to' => 'handler', 'async_context' => 'synchronous', 'branch_group_id' => $branchId, 'async_child_flow_id' => null]],
        ]], static function (bool $force = false): void {});
        if ($case['valid']) {
            expect($run)->not->toThrow(Throwable::class)
                ->and(DB::table('hades_graph_import_references')->where('owner_public_id', $stepId)->where('reference_kind', 'branch_group_id')->value('target_record_kind'))->toBe('structures');
        } else {
            expect($run)->toThrow(GraphV2ImportException::class, 'subtype');
        }
    }
});

it('exposes one idempotent validated-import projection request without scanning sibling imports', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    $sibling = HadesGraphImport::query()->create([
        'id' => (string) Str::ulid(), 'project_id' => $import->project_id, 'workspace_binding_id' => $import->workspace_binding_id,
        'hades_agent_id' => $import->hades_agent_id, 'attempt_generation' => 2, 'scope_generation' => 2, 'schema' => 'hades.code_graph.v2',
        'artifact_graph_version' => str_repeat('d', 64), 'manifest_semantic_sha256' => str_repeat('e', 64), 'source_identity' => [], 'manifest' => [],
        'status' => HadesGraphImport::STATUS_VALIDATED, 'completeness_status' => 'full', 'expected_chunks' => 0, 'received_chunks' => 0,
        'expected_uncompressed_bytes' => 0, 'received_uncompressed_bytes' => 0, 'expected_compressed_bytes' => 0, 'received_compressed_bytes' => 0,
        'validated_at' => now(), 'expires_at' => null,
    ]);
    $import->update(['status' => HadesGraphImport::STATUS_VALIDATED, 'scope_generation' => 1, 'validated_at' => now()]);

    expect(app(GraphV2ValidationRunService::class)->requestProjectionForValidatedImport($import->id))->toBeTrue()
        ->and(DB::table('canonical_graph_projection_heads')->where('project_id', $import->project_id)->where('source_scope_id', $import->workspace_binding_id)->count())->toBe(1)
        ->and(DB::table('canonical_graph_projection_heads')->where('desired_graph_import_id', $import->id)->count())->toBe(1)
        ->and(app(GraphV2ValidationRunService::class)->requestProjectionForValidatedImport($sibling->id))->toBeTrue()
        ->and(DB::table('canonical_graph_projection_heads')->where('desired_graph_import_id', $sibling->id)->count())->toBe(1)
        ->and(app(GraphV2ValidationRunService::class)->requestProjectionForValidatedImport($import->id))->toBeFalse();
});

it('turns controlled storage I/O failure into the persisted transient retry path', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    $descriptor = [
        'index' => 0, 'kind' => 'nodes', 'record_count' => 1, 'sha256' => str_repeat('a', 64),
        'uncompressed_bytes' => 10, 'compression' => 'gzip', 'compressed_sha256' => str_repeat('b', 64), 'compressed_bytes' => 20,
    ];
    HadesGraphImportChunk::query()->create([
        'id' => (string) Str::ulid(), 'graph_import_id' => $import->id, 'chunk_index' => 0, 'kind' => 'nodes',
        'sha256' => $descriptor['sha256'], 'record_count' => 1, 'uncompressed_bytes' => 10, 'compression' => 'gzip',
        'compressed_sha256' => $descriptor['compressed_sha256'], 'compressed_bytes' => 20, 'storage_disk' => 'failing',
        'storage_path' => 'unavailable', 'received_at' => now(),
    ]);
    $import->manifest = [
        'schema' => 'hades.graph_bundle.v2', 'artifact_schema' => 'hades.code_graph.v2',
        'artifact_graph_version' => $import->artifact_graph_version,
        'project' => ['project_id' => $import->project_id, 'workspace_binding_id' => $import->workspace_binding_id],
        'source' => $import->source_identity, 'counts' => ['entrypoints' => 0, 'nodes' => 1, 'structures' => 0, 'edges' => 0, 'flows' => 0, 'flow_steps' => 0, 'uncertainties' => 0],
        'chunks' => [$descriptor],
    ];
    $import->save();
    $token = 'storage-outage-token';
    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'validation_attempts' => 1, 'validation_run_token_hash' => hash('sha256', $token), 'validation_lease_expires_at' => now()->addMinutes(5),
    ]);
    $storage = new class extends ArtifactStorageService
    {
        public function readGraphChunkStream(string $disk, string $path)
        {
            throw new RuntimeException('controlled storage outage');
        }
    };
    $reader = new GraphV2ArtifactReader($storage, new GraphV2StoredChunkReader(app(GraphV2ChunkValidator::class)));
    $normalizer = new class implements GraphV2NormalizerContract
    {
        public function passOne(HadesGraphImport $import, iterable $batches, Closure $heartbeat): void
        {
            iterator_to_array($batches);
        }

        public function passTwo(HadesGraphImport $import, iterable $batches, Closure $heartbeat): array
        {
            return ['artifact_graph_version' => $import->artifact_graph_version];
        }
    };

    (new ValidateGraphV2Import($import->id, 1, 1, $token))->handle($reader, $normalizer, app(GraphV2ValidationRunService::class));

    expect(DB::table('hades_graph_imports')->where('id', $import->id)->value('status'))->toBe(HadesGraphImport::STATUS_VALIDATING)
        ->and(DB::table('hades_graph_imports')->where('id', $import->id)->value('failure_code'))->toBe('graph_validation_infrastructure_failed')
        ->and(data_get(json_decode((string) DB::table('hades_graph_imports')->where('id', $import->id)->value('failure_details'), true), 'next_eligible_at'))->not->toBeNull();
});

it('turns a non-resource artifact stream into a persisted transient retry without leaking its lease', function (): void {
    Bus::fake();
    $import = validationImportFixture();
    $descriptor = [
        'index' => 0, 'kind' => 'nodes', 'record_count' => 1, 'sha256' => str_repeat('a', 64),
        'uncompressed_bytes' => 10, 'compression' => 'gzip', 'compressed_sha256' => str_repeat('b', 64), 'compressed_bytes' => 20,
    ];
    HadesGraphImportChunk::query()->create([
        'id' => (string) Str::ulid(), 'graph_import_id' => $import->id, 'chunk_index' => 0, 'kind' => 'nodes',
        'sha256' => $descriptor['sha256'], 'record_count' => 1, 'uncompressed_bytes' => 10, 'compression' => 'gzip',
        'compressed_sha256' => $descriptor['compressed_sha256'], 'compressed_bytes' => 20, 'storage_disk' => 'faulty',
        'storage_path' => 'not-a-stream', 'received_at' => now(),
    ]);
    $import->manifest = [
        'schema' => 'hades.graph_bundle.v2', 'artifact_schema' => 'hades.code_graph.v2',
        'artifact_graph_version' => $import->artifact_graph_version,
        'project' => ['project_id' => $import->project_id, 'workspace_binding_id' => $import->workspace_binding_id],
        'source' => $import->source_identity, 'counts' => ['entrypoints' => 0, 'nodes' => 1, 'structures' => 0, 'edges' => 0, 'flows' => 0, 'flow_steps' => 0, 'uncertainties' => 0],
        'chunks' => [$descriptor],
    ];
    $import->save();
    $token = 'non-resource-reader-token';
    DB::table('hades_graph_imports')->where('id', $import->id)->update([
        'validation_attempts' => 1, 'validation_run_token_hash' => hash('sha256', $token), 'validation_lease_expires_at' => now()->addMinutes(5),
    ]);
    $storage = new class extends ArtifactStorageService
    {
        public function readGraphChunkStream(string $disk, string $path)
        {
            return false;
        }
    };
    $chunks = new class implements GraphV2StoredChunkReaderContract
    {
        public function streamRecords(HadesGraphImport $import, int $index, $source, array $headers, array $descriptor): iterable
        {
            return [];
        }
    };
    $reader = new GraphV2ArtifactReader($storage, $chunks);
    $normalizer = new class implements GraphV2NormalizerContract
    {
        public function passOne(HadesGraphImport $import, iterable $batches, Closure $heartbeat): void
        {
            iterator_to_array($batches);
        }

        public function passTwo(HadesGraphImport $import, iterable $batches, Closure $heartbeat): array
        {
            return ['artifact_graph_version' => $import->artifact_graph_version];
        }
    };

    expect(fn () => (new ValidateGraphV2Import($import->id, 1, 1, $token))
        ->handle($reader, $normalizer, app(GraphV2ValidationRunService::class)))
        ->not->toThrow(TypeError::class);

    $stored = DB::table('hades_graph_imports')->where('id', $import->id)->first();
    expect($stored->status)->toBe(HadesGraphImport::STATUS_VALIDATING)
        ->and($stored->failure_code)->toBe('graph_validation_infrastructure_failed')
        ->and($stored->validation_run_token_hash)->toBeNull()
        ->and($stored->validation_lease_expires_at)->toBeNull()
        ->and(data_get(json_decode((string) $stored->failure_details, true), 'next_eligible_at'))->not->toBeNull();
    Bus::assertDispatched(AcquireGraphV2ValidationRun::class, fn (AcquireGraphV2ValidationRun $job): bool => $job->delay === 10);
});

it('classifies a non-resource stored neighbor stream as infrastructure failure', function (): void {
    $import = validationImportFixture();
    $descriptor = [
        'index' => 0, 'kind' => 'nodes', 'record_count' => 1, 'sha256' => str_repeat('a', 64),
        'uncompressed_bytes' => 10, 'compression' => 'gzip', 'compressed_sha256' => str_repeat('b', 64), 'compressed_bytes' => 20,
    ];
    $row = HadesGraphImportChunk::query()->create([
        'id' => (string) Str::ulid(), 'graph_import_id' => $import->id, 'chunk_index' => 0, 'kind' => 'nodes',
        'sha256' => $descriptor['sha256'], 'record_count' => 1, 'uncompressed_bytes' => 10, 'compression' => 'gzip',
        'compressed_sha256' => $descriptor['compressed_sha256'], 'compressed_bytes' => 20, 'storage_disk' => 'faulty',
        'storage_path' => 'not-a-stream', 'received_at' => now(),
    ]);
    $storage = new class extends ArtifactStorageService
    {
        public function readGraphChunkStream(string $disk, string $path)
        {
            return false;
        }
    };
    $schema = (new ReflectionClass(GraphV2JsonSchemaValidator::class))->newInstanceWithoutConstructor();
    $chunks = (new ReflectionClass(GraphV2ChunkValidator::class))->newInstanceWithoutConstructor();
    $service = new GraphV2ImportService(new GraphV2ManifestValidator($schema), $chunks, $storage);
    $storedBoundary = new ReflectionMethod(GraphV2ImportService::class, 'storedBoundary');

    expect(fn () => $storedBoundary->invoke($service, $import, $row, $descriptor))
        ->toThrow(GraphV2InfrastructureException::class)
        ->not->toThrow(GraphV2ImportException::class, 'graph_chunk_invalid');
});

it('keeps malformed gzip deterministic while controlled stream reads are infrastructure failures', function (): void {
    $import = validationImportFixture();
    $validator = app(GraphV2ChunkValidator::class);
    $descriptor = ['index' => 0, 'kind' => 'nodes', 'record_count' => 1, 'sha256' => str_repeat('a', 64), 'uncompressed_bytes' => 1, 'compression' => 'gzip', 'compressed_sha256' => hash('sha256', 'not-gzip'), 'compressed_bytes' => 8];
    $headers = ['Content-Type' => 'application/vnd.hades.graph-chunk+gzip', 'X-Hades-Chunk-Compressed-Bytes' => '8', 'X-Hades-Chunk-Compressed-Sha256' => hash('sha256', 'not-gzip'), 'X-Hades-Chunk-Sha256' => str_repeat('a', 64), 'X-Hades-Chunk-Uncompressed-Bytes' => '1'];
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'not-gzip');
    rewind($stream);
    expect(fn () => $validator->validate($import, 0, $stream, $headers, $descriptor))->toThrow(GraphV2ImportException::class, 'truncated');
    fclose($stream);
    stream_wrapper_register('graph-v2-failing', GraphV2FailingReadStream::class);
    $failing = fopen('graph-v2-failing://read', 'r');
    expect(fn () => $validator->validate($import, 0, $failing, $headers, $descriptor))->toThrow(GraphV2InfrastructureException::class);
    fclose($failing);
    stream_wrapper_unregister('graph-v2-failing');
});

it('accepts golden AST/config/file fingerprints, rejects tampering, and rejects derived producer locators', function (): void {
    $import = validationImportFixture();
    $normalizer = app(GraphV2Normalizer::class);
    DB::table('hades_graph_import_file_paths')->insert([
        ['graph_import_id' => $import->id, 'path' => 'src/Service/Example.php', 'file_node_public_id' => 'file-ast', 'file_sha256' => str_repeat('a', 64)],
        ['graph_import_id' => $import->id, 'path' => 'config/routes.yaml', 'file_node_public_id' => 'file-config', 'file_sha256' => str_repeat('b', 64)],
        ['graph_import_id' => $import->id, 'path' => 'src/Empty.php', 'file_node_public_id' => 'file-file', 'file_sha256' => str_repeat('c', 64)],
    ]);
    $assert = new ReflectionMethod(GraphV2Normalizer::class, 'assertEvidenceItem');
    $assert->invoke($normalizer, $import, 'nodes', (object) ['id' => 'node-ast', 'kind' => 'module'], (object) [
        'origin' => 'verified_from_code', 'extractor' => 'test',
        'source_locator' => (object) ['kind' => 'ast', 'path' => 'src/Service/Example.php', 'structural_path' => 'declaration/class/Example/method/run/body/3/call/0'],
        'source_fingerprint' => '5fe5084ccf4d14c89f770bc1fcbea3b23a6b0b8d1ed480d33589c528cc9293da', 'inference_rule' => null,
    ]);
    $assert->invoke($normalizer, $import, 'nodes', (object) ['id' => 'node-config', 'kind' => 'module'], (object) [
        'origin' => 'verified_from_code', 'extractor' => 'test',
        'source_locator' => (object) ['kind' => 'config', 'path' => 'config/routes.yaml', 'structural_pointer' => 'routes/admin'],
        'source_fingerprint' => '4ee4564b1990832777ff106348a98cd81a1abc93fea072844f4bac897ce701f2', 'inference_rule' => null,
    ]);
    $assert->invoke($normalizer, $import, 'nodes', (object) [
        'id' => 'file-file', 'kind' => 'file',
        'identity' => (object) ['path' => 'src/Empty.php'],
        'properties' => (object) ['file_sha256' => str_repeat('c', 64)],
    ], (object) [
        'origin' => 'verified_from_code', 'extractor' => 'test',
        'source_locator' => (object) ['kind' => 'file', 'path' => 'src/Empty.php'],
        'source_fingerprint' => '8ae5520db22b543f517040e5a55df69002af48cc9a0e493fd72c0aa94b8b7402', 'inference_rule' => null,
    ]);

    expect(fn () => $assert->invoke($normalizer, $import, 'nodes', (object) ['id' => 'node-ast', 'kind' => 'module'], (object) [
        'origin' => 'verified_from_code', 'extractor' => 'test',
        'source_locator' => (object) ['kind' => 'ast', 'path' => 'src/Service/Example.php', 'structural_path' => 'declaration/class/Example/method/run/body/3/call/0'],
        'source_fingerprint' => str_repeat('0', 64), 'inference_rule' => null,
    ]))->toThrow(GraphV2ImportException::class, 'fingerprint');
    expect(fn () => $assert->invoke($normalizer, $import, 'nodes', (object) ['id' => 'node-ast', 'kind' => 'module'], (object) [
        'origin' => 'verified_from_code', 'extractor' => 'test',
        'source_locator' => (object) ['kind' => 'derived', 'base_edge_id' => 'edge'], 'source_fingerprint' => str_repeat('0', 64), 'inference_rule' => null,
    ]))->toThrow(GraphV2ImportException::class, 'derived');
});

it('validates the entrypoint registration occurrence path during the second pass', function (): void {
    $import = validationImportFixture();
    $entrypointId = 'hades:node:v2:'.str_repeat('r', 64);
    DB::table('hades_graph_import_record_keys')->insert([
        'graph_import_id' => $import->id, 'record_kind' => 'nodes', 'public_id' => $entrypointId, 'chunk_index' => 0, 'record_ordinal' => 0,
    ]);
    DB::table('hades_graph_import_file_paths')->insert([
        'graph_import_id' => $import->id, 'path' => 'config/routes.yaml', 'file_node_public_id' => 'file-routes', 'file_sha256' => str_repeat('b', 64),
    ]);
    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $counts['entrypoints'] = 1;
    $import->manifest = ['counts' => $counts, 'graph_contract' => ['completeness' => [], 'coverage' => []]];
    $import->save();
    $record = (object) [
        'id' => $entrypointId, 'handler_node_id' => null, 'uncertainty_id' => null,
        'registration_occurrence' => (object) ['kind' => 'config', 'path' => 'config/routes.yaml', 'structural_pointer' => 'routes/0', 'ordinal' => 0],
        'evidence' => (object) [
            'primary' => (object) [
                'origin' => 'verified_from_code', 'extractor' => 'test',
                'source_locator' => (object) ['kind' => 'config', 'path' => 'config/routes.yaml', 'structural_pointer' => 'routes/0'],
                'source_fingerprint' => app(GraphV2Canonicalizer::class)->sha256([
                    'file_sha256' => str_repeat('b', 64), 'occurrence_kind' => 'config', 'path' => 'config/routes.yaml', 'structural_pointer' => 'routes/0',
                ]), 'inference_rule' => null,
            ],
            'supporting' => [], 'supporting_omitted_count' => 0,
        ],
    ];

    expect(fn () => app(GraphV2Normalizer::class)->passTwo($import, [[
        'kind' => 'entrypoints', 'index' => 0, 'records' => [$record],
    ]], static function (bool $force = false): void {}))->not->toThrow(Throwable::class);
});

it('loads all retained chunk rows with one descriptor-independent query', function (): void {
    Storage::fake('local');
    $import = validationImportFixture();
    $descriptors = [];
    for ($index = 0; $index < 4; $index++) {
        $chunkPath = 'graph-v2/'.$import->id.'/chunks/'.$index;
        Storage::disk('local')->put($chunkPath, 'stored');
        $descriptors[] = [
            'index' => $index, 'kind' => 'nodes', 'record_count' => 1,
            'sha256' => str_repeat((string) ($index + 1), 64), 'uncompressed_bytes' => 1,
            'compression' => 'gzip', 'compressed_sha256' => str_repeat((string) ($index + 2), 64),
            'compressed_bytes' => 6,
        ];
        HadesGraphImportChunk::query()->create([
            'id' => (string) Str::ulid(), 'graph_import_id' => $import->id, 'chunk_index' => $index,
            'kind' => 'nodes', 'sha256' => $descriptors[$index]['sha256'], 'record_count' => 1,
            'uncompressed_bytes' => 1, 'compression' => 'gzip',
            'compressed_sha256' => $descriptors[$index]['compressed_sha256'], 'compressed_bytes' => 6,
            'storage_disk' => 'local', 'storage_path' => $chunkPath, 'received_at' => now(),
        ]);
    }
    $import->manifest = [
        'schema' => 'hades.graph_bundle.v2', 'artifact_schema' => 'hades.code_graph.v2',
        'artifact_graph_version' => $import->artifact_graph_version,
        'project' => ['project_id' => $import->project_id, 'workspace_binding_id' => $import->workspace_binding_id],
        'source' => $import->source_identity, 'counts' => ['entrypoints' => 0, 'nodes' => 4, 'structures' => 0, 'edges' => 0, 'flows' => 0, 'flow_steps' => 0, 'uncertainties' => 0],
        'chunks' => $descriptors,
    ];
    $import->save();

    $reader = new GraphV2ArtifactReader(
        app(ArtifactStorageService::class),
        new class implements GraphV2StoredChunkReaderContract
        {
            public function streamRecords(HadesGraphImport $import, int $index, $source, array $headers, array $descriptor): iterable
            {
                yield (object) ['id' => 'hades:node:v2:'.str_repeat((string) ($index + 1), 64)];
            }
        },
    );
    $selects = 0;
    DB::listen(function ($query) use (&$selects): void {
        if (str_contains(strtolower($query->sql), 'select') && str_contains($query->sql, 'hades_graph_import_chunks')) {
            $selects++;
        }
    });

    expect(iterator_to_array($reader->batches($import)))->toHaveCount(4)->and($selects)->toBe(1);
});

it('uses one current-batch path lookup instead of one query per evidence item', function (): void {
    $import = validationImportFixture();
    $filePath = 'src/Batch.php';
    $fileDigest = str_repeat('a', 64);
    $records = [];
    for ($index = 0; $index < 1001; $index++) {
        $identity = (object) [
            'variant' => 'source_declaration', 'workspace_binding_id' => $import->workspace_binding_id,
            'language' => 'php', 'kind' => 'service', 'namespace' => null,
            'qualified_name' => 'BatchService'.$index, 'path' => $filePath,
        ];
        $id = app(GraphV2IdentityValidator::class)->nodeId($identity);
        $records[] = (object) [
            'id' => $id, 'identity' => $identity, 'kind' => 'service', 'language' => 'php',
            'framework' => null, 'name' => 'BatchService'.$index, 'qualified_name' => 'BatchService'.$index,
            'namespace' => null, 'uncertainty_id' => null, 'location' => null, 'properties' => (object) [],
            'evidence' => (object) ['primary' => (object) [
                'origin' => 'verified_from_code', 'extractor' => 'test',
                'source_locator' => (object) ['kind' => 'ast', 'path' => $filePath, 'structural_path' => 'service/'.$index],
                'source_fingerprint' => app(GraphV2Canonicalizer::class)->sha256(['file_sha256' => $fileDigest, 'occurrence_kind' => 'ast', 'path' => $filePath, 'structural_path' => 'service/'.$index]),
                'inference_rule' => null,
            ], 'supporting' => [], 'supporting_omitted_count' => 0],
        ];
    }
    usort($records, static fn (object $left, object $right): int => strcmp($left->id, $right->id));
    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $counts['nodes'] = count($records);
    $import->manifest = ['counts' => $counts, 'graph_contract' => ['completeness' => [], 'coverage' => ['records' => ['nodes' => 1001, 'structures' => 0, 'edges' => 0, 'flows' => 0, 'flow_steps' => 0, 'uncertainties' => 0]]]];
    $import->save();

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains(strtolower($query->sql), 'hades_graph_import_file_paths')) {
            $queries++;
        }
    });
    $batches = array_map(static fn (array $records): array => ['kind' => 'nodes', 'index' => 0, 'records' => $records], array_chunk($records, 1000));
    app(GraphV2Normalizer::class)->passOne($import, $batches, static function (bool $force = false): void {});
    DB::table('hades_graph_import_file_paths')->insert([
        'graph_import_id' => $import->id, 'path' => $filePath,
        'file_node_public_id' => 'hades:node:v2:'.str_repeat('f', 64), 'file_sha256' => $fileDigest,
    ]);
    app(GraphV2Normalizer::class)->passTwo($import, $batches, static function (bool $force = false): void {});

    expect($queries)->toBeLessThanOrEqual(4);
});

it('rejects a self-consistent forged node ID through the real first pass', function (): void {
    $import = validationImportFixture();
    $identity = (object) [
        'variant' => 'file', 'workspace_binding_id' => $import->workspace_binding_id,
        'language' => 'php', 'kind' => 'file', 'path' => 'src/Forged.php',
    ];
    $record = (object) [
        'id' => 'hades:node:v2:'.str_repeat('0', 64), 'identity' => $identity, 'kind' => 'file',
        'properties' => (object) ['file_sha256' => str_repeat('a', 64)],
    ];
    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $counts['nodes'] = 1;
    $import->manifest = ['counts' => $counts, 'graph_contract' => ['completeness' => [], 'coverage' => []]];
    $import->save();

    expect(fn () => app(GraphV2Normalizer::class)->passOne($import, [['kind' => 'nodes', 'index' => 0, 'records' => [$record]]], static function (bool $force = false): void {}))
        ->toThrow(GraphV2ImportException::class, 'identity');
});

it('uses NULL-safe bidirectional entrypoint pairing', function (): void {
    $import = validationImportFixture();
    $entrypointId = 'hades:node:v2:'.str_repeat('e', 64);
    DB::table('hades_graph_import_record_keys')->insert([
        ['graph_import_id' => $import->id, 'record_kind' => 'entrypoints', 'public_id' => $entrypointId, 'record_subkind' => 'http_route', 'identity_variant' => null, 'owner_public_id' => null, 'aux_public_id' => null, 'chunk_index' => 0, 'record_ordinal' => 0],
        ['graph_import_id' => $import->id, 'record_kind' => 'nodes', 'public_id' => $entrypointId, 'record_subkind' => 'entrypoint', 'identity_variant' => null, 'owner_public_id' => null, 'aux_public_id' => null, 'chunk_index' => 0, 'record_ordinal' => 0],
    ]);
    DB::table('hades_graph_import_file_paths')->insert([
        'graph_import_id' => $import->id, 'path' => 'config/routes.yaml',
        'file_node_public_id' => 'hades:node:v2:'.str_repeat('f', 64), 'file_sha256' => str_repeat('a', 64),
    ]);
    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $counts['entrypoints'] = 1;
    $import->manifest = ['counts' => $counts, 'graph_contract' => ['completeness' => [], 'coverage' => []]];
    $import->save();

    $record = (object) [
        'id' => $entrypointId,
        'evidence' => (object) ['primary' => (object) [
            'origin' => 'verified_from_code', 'extractor' => 'test',
            'source_locator' => (object) ['kind' => 'config', 'path' => 'config/routes.yaml', 'structural_pointer' => 'routes/0'],
            'source_fingerprint' => app(GraphV2Canonicalizer::class)->sha256(['file_sha256' => str_repeat('a', 64), 'occurrence_kind' => 'config', 'path' => 'config/routes.yaml', 'structural_pointer' => 'routes/0']),
            'inference_rule' => null,
        ], 'supporting' => [], 'supporting_omitted_count' => 0],
    ];
    expect(fn () => app(GraphV2Normalizer::class)->passTwo($import, [['kind' => 'entrypoints', 'index' => 0, 'records' => [$record]]], static function (bool $force = false): void {}))
        ->toThrow(GraphV2ImportException::class, 'pair');
});

it('rejects canonically rehashed record coverage declarations that omit staged records', function (): void {
    $import = validationImportFixture();
    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $import->manifest = ['counts' => $counts, 'graph_contract' => ['completeness' => [], 'coverage' => ['records' => ['nodes' => 1, 'structures' => 0, 'edges' => 0, 'flows' => 0, 'flow_steps' => 0, 'uncertainties' => 0]]]];
    $import->save();

    expect(fn () => app(GraphV2Normalizer::class)->passTwo($import, [], static function (bool $force = false): void {}))
        ->toThrow(GraphV2ImportException::class, 'coverage');
});

it('accepts a valid partial file inventory with one discovered file omitted by budget', function (): void {
    $import = partialFileCoverageImportFixture();
    $file = partialFileNodeRecord($import);

    expect(fn () => runPublicTwoPass($import, [
        ['kind' => 'nodes', 'index' => 0, 'records' => [$file]],
    ]))->not->toThrow(Throwable::class);
});

it('rejects wrong represented file status coverage on the public two-pass path', function (): void {
    $import = partialFileCoverageImportFixture();
    $manifest = $import->manifest;
    $manifest['graph_contract']['coverage']['files']['analyzed'] = 0;
    $import->manifest = $manifest;
    $import->save();

    expect(fn () => runPublicTwoPass($import, [
        ['kind' => 'nodes', 'index' => 0, 'records' => [partialFileNodeRecord($import)]],
    ]))->toThrow(GraphV2ImportException::class, 'coverage');
});

it('rejects discovered and hashed file coverage that do not agree', function (): void {
    $import = partialFileCoverageImportFixture();
    $manifest = $import->manifest;
    $manifest['graph_contract']['coverage']['files']['hashed'] = 1;
    $import->manifest = $manifest;
    $import->save();

    expect(fn () => runPublicTwoPass($import, [
        ['kind' => 'nodes', 'index' => 0, 'records' => [partialFileNodeRecord($import)]],
    ]))->toThrow(GraphV2ImportException::class, 'coverage');
});

it('rejects parser candidates above discovered files and analyzed above candidates', function (): void {
    foreach ([['parser_candidates' => 3], ['analyzed' => 2, 'parser_candidates' => 1]] as $mutation) {
        $import = partialFileCoverageImportFixture();
        $manifest = $import->manifest;
        foreach ($mutation as $field => $value) {
            $manifest['graph_contract']['coverage']['files'][$field] = $value;
        }
        $import->manifest = $manifest;
        $import->save();

        expect(fn () => runPublicTwoPass($import, [
            ['kind' => 'nodes', 'index' => 0, 'records' => [partialFileNodeRecord($import)]],
        ]))->toThrow(GraphV2ImportException::class);
    }
});

it('accepts a detected but rejected entrypoint as a valid partial public ledger', function (): void {
    $import = emptyPartialCoverageImportFixture();

    expect(fn () => runPublicTwoPass($import, []))->not->toThrow(Throwable::class);
});

it('rejects false analyzed, partial, by-kind sum, and by-kind dominance entrypoint declarations', function (): void {
    $mutations = [
        ['analyzed' => 1],
        ['partial' => 0],
        ['by_kind' => ['http_route' => 0]],
        ['by_kind' => ['http_route' => 2, 'cli_command' => 0]],
    ];

    foreach ($mutations as $mutation) {
        $import = emptyPartialCoverageImportFixture();
        $manifest = $import->manifest;
        foreach ($mutation as $field => $value) {
            $manifest['graph_contract']['coverage']['entrypoints'][$field] = $value;
        }
        $import->manifest = $manifest;
        $import->save();

        expect(fn () => runPublicTwoPass($import, []))->toThrow(GraphV2ImportException::class, 'coverage');
    }
});

it('rejects an omitted represented entrypoint kind even when another declared kind has the same total', function (): void {
    $import = emptyPartialCoverageImportFixture();
    $manifest = $import->manifest;
    $manifest['graph_contract']['coverage']['by_kind'] = ['public_api' => 1];
    $manifest['graph_contract']['coverage']['entrypoints']['by_kind'] = ['public_api' => 1];
    $import->manifest = $manifest;
    $import->save();

    $entrypointId = 'hades:node:v2:'.str_repeat('e', 64);
    DB::table('hades_graph_import_record_keys')->insert([
        'graph_import_id' => $import->id, 'record_kind' => 'entrypoints', 'public_id' => $entrypointId,
        'record_subkind' => 'http_route', 'entrypoint_identity_digest' => str_repeat('a', 64), 'chunk_index' => 0, 'record_ordinal' => 0,
    ]);
    DB::table('hades_graph_import_record_keys')->insert([
        'graph_import_id' => $import->id, 'record_kind' => 'nodes', 'public_id' => $entrypointId,
        'record_subkind' => 'entrypoint', 'identity_variant' => 'entrypoint', 'entrypoint_identity_digest' => str_repeat('a', 64), 'chunk_index' => 0, 'record_ordinal' => 0,
    ]);
    DB::table('hades_graph_import_record_keys')->insert([
        'graph_import_id' => $import->id, 'record_kind' => 'flows', 'public_id' => 'hades:flow:v2:'.str_repeat('f', 64),
        'record_subkind' => 'request_lifecycle', 'owner_public_id' => $entrypointId, 'root_node_public_id' => $entrypointId,
        'chunk_index' => 0, 'record_ordinal' => 0,
    ]);

    expect(fn () => app(GraphV2Normalizer::class)->passTwo($import, [], static function (bool $force = false): void {}))
        ->toThrow(GraphV2ImportException::class);
});

it('rejects missing and extra language inventory and completeness records', function (): void {
    $mutations = [
        static function (HadesGraphImport $import): void {
            $manifest = $import->manifest;
            $manifest['languages'] = [];
            $import->manifest = $manifest;
        },
        static function (HadesGraphImport $import): void {
            $manifest = $import->manifest;
            $manifest['languages'][] = ['name' => 'python', 'extractor' => 'test', 'extractor_version' => '1', 'detected_file_count' => 0, 'analyzed_file_count' => 0];
            $import->manifest = $manifest;
        },
        static function (HadesGraphImport $import): void {
            $manifest = $import->manifest;
            $manifest['graph_contract']['completeness']['languages'] = [];
            $import->manifest = $manifest;
        },
        static function (HadesGraphImport $import): void {
            $manifest = $import->manifest;
            $manifest['graph_contract']['completeness']['languages'][] = ['language' => 'python', 'status' => 'full', 'capabilities' => fullGraphCapabilities()];
            $import->manifest = $manifest;
        },
    ];

    foreach ($mutations as $mutate) {
        $import = partialFileCoverageImportFixture();
        $manifest = $import->manifest;
        $import->manifest = $manifest;
        $mutate($import);
        $import->save();

        expect(fn () => runPublicTwoPass($import, [
            ['kind' => 'nodes', 'index' => 0, 'records' => [partialFileNodeRecord($import)]],
        ]))->toThrow(GraphV2ImportException::class);
    }
});

it('rejects language detected-file totals that exceed discovered files', function (): void {
    $import = partialFileCoverageImportFixture();
    $manifest = $import->manifest;
    $manifest['languages'][] = ['name' => 'python', 'extractor' => 'test', 'extractor_version' => '1', 'detected_file_count' => 1, 'analyzed_file_count' => 0];
    $manifest['graph_contract']['completeness']['languages'][] = ['language' => 'python', 'status' => 'full', 'capabilities' => fullGraphCapabilities()];
    $import->manifest = $manifest;
    $import->save();

    expect(fn () => runPublicTwoPass($import, [
        ['kind' => 'nodes', 'index' => 0, 'records' => [partialFileNodeRecord($import)]],
    ]))->toThrow(GraphV2ImportException::class);
});

it('requires an uncertainty fingerprint at the public first-pass boundary', function (): void {
    $import = validationImportFixture();
    $record = (object) [
        'domain' => 'graph', 'resolution_kind' => 'external_target',
        'reason_code' => 'external_boundary_unresolved', 'question' => 'Which target?',
        'subject' => (object) ['edge_id' => 'hades:edge:v2:'.str_repeat('e', 64)],
        'candidate_target_node_ids' => [], 'candidate_edge_ids' => [],
    ];
    $record->id = app(GraphV2IdentityValidator::class)->uncertaintyId($record, $import);
    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $counts['uncertainties'] = 1;
    $import->manifest = ['counts' => $counts, 'graph_contract' => ['completeness' => [], 'coverage' => []]];
    $import->save();

    expect(fn () => app(GraphV2Normalizer::class)->passOne($import, [[
        'kind' => 'uncertainties', 'index' => 0, 'records' => [$record],
    ]], static function (bool $force = false): void {}))->toThrow(GraphV2ImportException::class, 'identity');
});

it('rejects a flow count declaration without represented in the public second pass', function (): void {
    [$import, $flow] = flowCountImportFixture();
    $flow->terminal_count = (object) ['value' => 0, 'knowledge' => 'absence_verified', 'reason' => null];

    expect(fn () => runFlowCountSecondPass($import, $flow))->toThrow(GraphV2ImportException::class);
});

it('rejects exact zero, unknown-without-reason, and unknown knowledge count shapes', function (): void {
    $mutations = [
        (object) ['represented' => 0, 'value' => 0, 'knowledge' => 'exact', 'reason' => null],
        (object) ['represented' => 0, 'value' => null, 'knowledge' => 'unknown', 'reason' => null],
        (object) ['represented' => 0, 'value' => null, 'knowledge' => 'not_known', 'reason' => 'resource_budget_reached'],
    ];

    foreach ($mutations as $declaration) {
        [$import, $flow] = flowCountImportFixture();
        $flow->terminal_count = $declaration;

        expect(fn () => runFlowCountSecondPass($import, $flow))->toThrow(GraphV2ImportException::class);
    }
});

it('rejects unknown knowledge when all relevant flow capabilities are complete', function (): void {
    [$import, $flow] = flowCountImportFixture();
    $flow->terminal_count = (object) ['represented' => 0, 'value' => null, 'knowledge' => 'unknown', 'reason' => 'resource_budget_reached'];

    expect(fn () => runFlowCountSecondPass($import, $flow))->toThrow(GraphV2ImportException::class);
});

it('rejects a non-first reason when relevant flow capabilities are partial', function (): void {
    [$import, $flow] = flowCountImportFixture(true);
    $flow->terminal_count = (object) ['represented' => 0, 'value' => null, 'knowledge' => 'unknown', 'reason' => 'unsupported_language'];

    expect(fn () => runFlowCountSecondPass($import, $flow))->toThrow(GraphV2ImportException::class);
});

it('uses each stage capability subset for zero and unknown stage counts', function (): void {
    [$import, $flow] = flowCountImportFixture(false);
    $capabilities = json_decode(json_encode($flow->completeness->capabilities, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    $capabilities['symbol_resolution'] = ['status' => 'partial', 'reasons' => [['code' => 'invalid_source_fact', 'count' => 1, 'language' => null, 'paths_sample' => []]]];
    $capabilities['data_access'] = ['status' => 'partial', 'reasons' => [['code' => 'verified_target_not_materialized', 'count' => 1, 'language' => null, 'paths_sample' => []]]];
    $flow->completeness = (object) ['status' => 'partial', 'capabilities' => (object) $capabilities];
    $flow->stage_counts->handler = (object) ['represented' => 0, 'value' => null, 'knowledge' => 'unknown', 'reason' => 'invalid_source_fact'];
    $flow->stage_counts->data = (object) ['represented' => 0, 'value' => null, 'knowledge' => 'unknown', 'reason' => 'verified_target_not_materialized'];
    $manifest = $import->manifest;
    $manifest['graph_contract']['completeness']['status'] = 'partial';
    $manifest['graph_contract']['completeness']['capabilities'] = $capabilities;
    $import->manifest = $manifest;
    $import->save();

    expect(fn () => runFlowCountSecondPass($import, $flow))->not->toThrow(Throwable::class);

    $flow->stage_counts->data = (object) ['represented' => 0, 'value' => 0, 'knowledge' => 'absence_verified', 'reason' => null];
    expect(fn () => runFlowCountSecondPass($import, $flow))->toThrow(GraphV2ImportException::class);
});

it('uses each flow row capability set when validating stage knowledge across multiple flows', function (): void {
    [$import, $first] = flowCountImportFixture(false);
    $second = json_decode(json_encode($first, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $second->entrypoint_id = 'hades:node:v2:'.str_repeat('f', 64);
    $second->root_node_id = $second->entrypoint_id;
    $second->id = app(GraphV2IdentityValidator::class)->flowId($second);
    $firstCapabilities = json_decode(json_encode($first->completeness->capabilities, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    $firstCapabilities['symbol_resolution'] = ['status' => 'partial', 'reasons' => [['code' => 'invalid_source_fact', 'count' => 1, 'language' => null, 'paths_sample' => []]]];
    $secondCapabilities = json_decode(json_encode($first->completeness->capabilities, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    $secondCapabilities['data_access'] = ['status' => 'partial', 'reasons' => [['code' => 'verified_target_not_materialized', 'count' => 1, 'language' => null, 'paths_sample' => []]]];
    $first->completeness = (object) ['status' => 'partial', 'capabilities' => (object) $firstCapabilities];
    $second->completeness = (object) ['status' => 'partial', 'capabilities' => (object) $secondCapabilities];
    $first->stage_counts->handler = (object) ['represented' => 0, 'value' => null, 'knowledge' => 'unknown', 'reason' => 'invalid_source_fact'];
    $second->stage_counts->data = (object) ['represented' => 0, 'value' => null, 'knowledge' => 'unknown', 'reason' => 'verified_target_not_materialized'];
    $manifest = $import->manifest;
    $manifest['counts']['flows'] = 2;
    $manifest['graph_contract']['coverage']['records']['flows'] = 2;
    $manifest['graph_contract']['completeness']['status'] = 'partial';
    $manifest['graph_contract']['completeness']['capabilities'] = $firstCapabilities;
    $manifest['graph_contract']['completeness']['capabilities']['data_access'] = $secondCapabilities['data_access'];
    $import->manifest = $manifest;
    $import->save();
    $secondEntrypoint = $second->entrypoint_id;
    DB::table('hades_graph_import_record_keys')->insert([
        'graph_import_id' => $import->id, 'record_kind' => 'entrypoints', 'public_id' => $secondEntrypoint, 'record_subkind' => 'http_route', 'entrypoint_identity_digest' => str_repeat('a', 64), 'chunk_index' => 0, 'record_ordinal' => 1,
    ]);
    DB::table('hades_graph_import_record_keys')->insert([
        'graph_import_id' => $import->id, 'record_kind' => 'nodes', 'public_id' => $secondEntrypoint, 'record_subkind' => 'entrypoint', 'identity_variant' => 'entrypoint', 'entrypoint_identity_digest' => str_repeat('a', 64), 'chunk_index' => 0, 'record_ordinal' => 1,
    ]);
    DB::table('hades_graph_import_record_keys')->insert([
        'graph_import_id' => $import->id, 'record_kind' => 'flows', 'public_id' => $second->id, 'record_subkind' => 'request_lifecycle', 'owner_public_id' => $secondEntrypoint, 'root_node_public_id' => $secondEntrypoint, 'flow_counts' => json_encode(['terminal_count' => $second->terminal_count, 'linked_async_flow_count' => $second->linked_async_flow_count, 'uncertainty_count' => $second->uncertainty_count], JSON_THROW_ON_ERROR), 'flow_capabilities' => json_encode($second->completeness->capabilities, JSON_THROW_ON_ERROR), 'completeness_status' => 'partial', 'stage_counts' => json_encode($second->stage_counts, JSON_THROW_ON_ERROR), 'chunk_index' => 0, 'record_ordinal' => 1,
    ]);
    DB::table('hades_graph_import_record_keys')->where('graph_import_id', $import->id)->where('record_kind', 'flows')->where('public_id', $first->id)->update([
        'flow_capabilities' => json_encode($first->completeness->capabilities, JSON_THROW_ON_ERROR), 'completeness_status' => 'partial', 'stage_counts' => json_encode($first->stage_counts, JSON_THROW_ON_ERROR),
    ]);
    $records = [$first, $second];
    usort($records, static fn (object $left, object $right): int => strcmp($left->id, $right->id));

    expect(fn () => app(GraphV2Normalizer::class)->passTwo($import, [['kind' => 'flows', 'index' => 0, 'records' => $records]], static function (bool $force = false): void {}))
        ->not->toThrow(Throwable::class);
});

it('rejects a bundle omission without a global budget reason', function (): void {
    $import = emptyPartialCoverageImportFixture();
    $manifest = $import->manifest;
    $manifest['graph_contract']['completeness']['capabilities']['entrypoint_discovery']['reasons'] = [];
    $import->manifest = $manifest;
    $import->save();

    expect(fn () => runPublicTwoPass($import, []))->toThrow(GraphV2ImportException::class);
});

it('rejects bundle omission when global completeness is not partial', function (): void {
    $import = emptyPartialCoverageImportFixture();
    $manifest = $import->manifest;
    $manifest['graph_contract']['completeness']['status'] = 'full';
    $import->manifest = $manifest;
    $import->save();

    expect(fn () => runPublicTwoPass($import, []))->toThrow(GraphV2ImportException::class);
});

it('rejects a detected entrypoint omission without partial discovery evidence', function (): void {
    $import = emptyPartialCoverageImportFixture();
    $manifest = $import->manifest;
    $manifest['graph_contract']['completeness']['capabilities']['entrypoint_discovery']['status'] = 'full';
    $import->manifest = $manifest;
    $import->save();

    expect(fn () => runPublicTwoPass($import, []))->toThrow(GraphV2ImportException::class);
});

it('rejects insufficient language-scoped budget reason counts for omitted files', function (): void {
    $import = partialFileCoverageImportFixture();
    $manifest = $import->manifest;
    $manifest['graph_contract']['completeness']['languages'][0]['capabilities']['inventory']['reasons'][0]['count'] = 0;
    $import->manifest = $manifest;
    $import->save();

    expect(fn () => runPublicTwoPass($import, [
        ['kind' => 'nodes', 'index' => 0, 'records' => [partialFileNodeRecord($import)]],
    ]))->toThrow(GraphV2ImportException::class);
});

it('does not let unsupported budget evidence close an omitted-file gap', function (): void {
    $import = partialFileCoverageImportFixture();
    $manifest = $import->manifest;
    $manifest['graph_contract']['completeness']['capabilities']['inventory']['status'] = 'unsupported';
    $manifest['graph_contract']['completeness']['languages'][0]['capabilities']['inventory']['status'] = 'unsupported';
    $import->manifest = $manifest;
    $import->save();

    expect(fn () => runPublicTwoPass($import, [
        ['kind' => 'nodes', 'index' => 0, 'records' => [partialFileNodeRecord($import)]],
    ]))->toThrow(GraphV2ImportException::class);
});

it('rejects an entrypoint with zero synchronous flows through the public two-pass path', function (): void {
    [$import, $batches, $afterFirstPass] = publicFlowTopologyFixture('valid');
    $manifest = $import->manifest;
    $manifest['counts']['edges'] = 0;
    $manifest['counts']['flows'] = 0;
    $manifest['counts']['flow_steps'] = 0;
    $import->manifest = $manifest;
    $import->save();
    $batches = array_values(array_filter($batches, static fn (array $batch): bool => in_array($batch['kind'], ['entrypoints', 'nodes'], true)));

    expect(fn () => runPublicFlowTopologyTwoPass($import, $batches, $afterFirstPass))
        ->toThrow(GraphV2ImportException::class, 'flow');
});

it('rejects structural and disconnected state-machine flow steps through the public two-pass path', function (): void {
    foreach (['structural', 'stage_mismatch', 'uncertainty_frontier', 'terminal_continuation'] as $case) {
        [$import, $batches, $afterFirstPass] = publicFlowTopologyFixture($case);
        $thrown = false;
        try {
            runPublicFlowTopologyTwoPass($import, $batches, $afterFirstPass);
        } catch (GraphV2ImportException $exception) {
            $thrown = true;
        }
        expect($thrown)->toBeTrue($case);
    }
});

it('rejects a public-path flow step reassigned to a colliding wrong flow', function (): void {
    [$import, $batches, $afterFirstPass] = publicFlowTopologyFixture('valid');
    $reassign = static function () use ($import, $afterFirstPass): void {
        $afterFirstPass();
        DB::table('hades_graph_import_record_keys')->where('graph_import_id', $import->id)->where('record_kind', 'flow_steps')->update(['flow_public_id' => 'hades:flow:v2:'.str_repeat('w', 64)]);
    };

    expect(fn () => runPublicFlowTopologyTwoPass($import, $batches, $reassign))
        ->toThrow(GraphV2ImportException::class, 'flow');
});

it('rejects global complete status when a language or flow is partial', function (): void {
    $languageImport = partialFileCoverageImportFixture();
    $manifest = $languageImport->manifest;
    $manifest['graph_contract']['completeness']['status'] = 'full';
    $languageImport->manifest = $manifest;
    $languageImport->save();
    expect(fn () => runPublicTwoPass($languageImport, [['kind' => 'nodes', 'index' => 0, 'records' => [partialFileNodeRecord($languageImport)]]]))
        ->toThrow(GraphV2ImportException::class, 'completeness');

    [$flowImport, $flow] = flowCountImportFixture(true);
    $manifest = $flowImport->manifest;
    $manifest['graph_contract']['completeness']['status'] = 'full';
    $flowImport->manifest = $manifest;
    $flowImport->save();
    expect(fn () => runFlowCountSecondPass($flowImport, $flow))
        ->toThrow(GraphV2ImportException::class, 'completeness');
});

it('rejects capability status and reason mismatches in global, language, and flow scopes', function (): void {
    $global = emptyPartialCoverageImportFixture();
    $manifest = $global->manifest;
    $manifest['graph_contract']['completeness']['capabilities']['entrypoint_discovery']['status'] = 'full';
    $global->manifest = $manifest;
    $global->save();
    expect(fn () => runPublicTwoPass($global, []))->toThrow(GraphV2ImportException::class, 'Capability status');

    $language = partialFileCoverageImportFixture();
    $manifest = $language->manifest;
    $manifest['graph_contract']['completeness']['languages'][0]['capabilities']['inventory']['status'] = 'full';
    $language->manifest = $manifest;
    $language->save();
    expect(fn () => runPublicTwoPass($language, [['kind' => 'nodes', 'index' => 0, 'records' => [partialFileNodeRecord($language)]]]))
        ->toThrow(GraphV2ImportException::class, 'Capability status');

    [$flowImport, $flow] = flowCountImportFixture();
    $flow->completeness->capabilities->control_flow = (object) ['status' => 'full', 'reasons' => [(object) ['code' => 'invalid_source_fact', 'count' => 1, 'language' => null, 'paths_sample' => []]]];
    expect(fn () => runFlowCountSecondPass($flowImport, $flow))->toThrow(GraphV2ImportException::class, 'Capability status');
});

it('rejects reason count undercounts and overcounts without collapsing capability or language scope', function (): void {
    foreach ([0, 2] as $count) {
        $import = partialFileCoverageImportFixture();
        $manifest = $import->manifest;
        $manifest['graph_contract']['completeness']['capabilities']['inventory']['reasons'][0]['count'] = $count;
        $manifest['graph_contract']['completeness']['languages'][0]['capabilities']['inventory']['reasons'][0]['count'] = $count;
        $import->manifest = $manifest;
        $import->save();

        expect(fn () => runPublicTwoPass($import, [['kind' => 'nodes', 'index' => 0, 'records' => [partialFileNodeRecord($import)]]]))
            ->toThrow(GraphV2ImportException::class);
    }
});

it('rejects every independent falsification of flow and stage represented counts', function (): void {
    $mutations = [
        static function (object $flow): void {
            $flow->represented_step_count = 1;
        },
        static function (object $flow): void {
            $flow->linked_async_flow_count->represented = 1;
        },
        static function (object $flow): void {
            $flow->uncertainty_count->represented = 1;
        },
        static function (object $flow): void {
            $flow->terminal_count->represented = 1;
        },
        static function (object $flow): void {
            $flow->stage_counts->handler->represented = 1;
        },
        static function (object $flow): void {
            $flow->stage_counts->data->represented = 1;
        },
    ];
    foreach ($mutations as $mutate) {
        [$import, $flow] = flowCountImportFixture();
        $mutate($flow);
        expect(fn () => runFlowCountSecondPass($import, $flow))->toThrow(GraphV2ImportException::class);
    }
});

it('rejects a flow step assigned to a disconnected wrong flow through public two-pass validation', function (): void {
    $import = validationImportFixture();
    $rootA = 'hades:node:v2:'.str_repeat('a', 64);
    $rootB = 'hades:node:v2:'.str_repeat('b', 64);
    $target = 'hades:node:v2:'.str_repeat('c', 64);
    $entryA = 'hades:node:v2:'.str_repeat('d', 64);
    $entryB = 'hades:node:v2:'.str_repeat('e', 64);
    $flowA = app(GraphV2IdentityValidator::class)->flowId((object) ['entrypoint_id' => $entryA, 'root_node_id' => $rootA, 'kind' => 'request_lifecycle']);
    $flowB = app(GraphV2IdentityValidator::class)->flowId((object) ['entrypoint_id' => $entryB, 'root_node_id' => $rootB, 'kind' => 'request_lifecycle']);
    $edgeRecord = (object) [
        'source_id' => $rootA, 'target_id' => $target, 'relation' => 'routes_to', 'flow' => 'always', 'condition_hash' => null,
        'branch_group_id' => null, 'call_site_id' => null, 'exception_scope_id' => null,
        'occurrence' => (object) ['kind' => 'ast', 'owner_node_id' => $rootA, 'ast_path' => 'route/0', 'ordinal' => 0],
    ];
    $edgeId = app(GraphV2IdentityValidator::class)->edgeId($edgeRecord);
    $stepRecord = (object) ['flow_id' => $flowB, 'edge_id' => $edgeId, 'stage_from' => 'entry', 'stage_to' => 'handler', 'async_context' => 'synchronous'];
    $stepId = app(GraphV2IdentityValidator::class)->flowStepId($stepRecord);
    $stepRecord->id = $stepId;
    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $counts['flow_steps'] = 1;
    $import->manifest = ['counts' => $counts, 'graph_contract' => ['completeness' => [], 'coverage' => ['records' => ['flow_steps' => 1]]]];
    $import->save();

    app(GraphV2Normalizer::class)->passOne($import, [['kind' => 'flow_steps', 'index' => 0, 'records' => [$stepRecord]]], static function (bool $force = false): void {});
    $metadata = static fn (string $kind, string $id, array $extra = []): array => array_merge([
        'graph_import_id' => $import->id, 'record_kind' => $kind, 'public_id' => $id, 'record_subkind' => null,
        'identity_variant' => null, 'owner_public_id' => null, 'aux_public_id' => null, 'language' => null, 'analysis_status' => null,
        'flow_public_id' => null, 'edge_public_id' => null, 'source_node_public_id' => null, 'target_node_public_id' => null,
        'uncertainty_public_id' => null, 'root_node_public_id' => null, 'stage_from' => null, 'stage_to' => null,
        'async_context' => null, 'async_child_flow_id' => null, 'count_hint' => null, 'flow_counts' => null,
        'completeness_status' => null, 'stage_counts' => null, 'relation' => null, 'chunk_index' => 0, 'record_ordinal' => 0,
    ], $extra);
    DB::table('hades_graph_import_record_keys')->insert([
        $metadata('flows', $flowA, ['record_subkind' => 'request_lifecycle', 'root_node_public_id' => $rootA]),
        $metadata('flows', $flowB, ['record_subkind' => 'request_lifecycle', 'root_node_public_id' => $rootB]),
        $metadata('nodes', $rootA), $metadata('nodes', $rootB), $metadata('nodes', $target),
        $metadata('edges', $edgeId, ['source_node_public_id' => $rootA, 'target_node_public_id' => $target, 'relation' => 'routes_to']),
    ]);

    expect(fn () => app(GraphV2Normalizer::class)->passTwo($import, [['kind' => 'flow_steps', 'index' => 0, 'records' => [$stepRecord]]], static function (bool $force = false): void {}))
        ->toThrow(GraphV2ImportException::class, 'flow');
});

it('rejects a semantically changed same-ID entrypoint pair after both records traverse public pass one and pass two with empty coverage', function (): void {
    [$import, $batches, $afterFirstPass] = publicFlowTopologyFixture('valid');
    $divergentBatches = $batches;
    $divergentEntrypoint = clone $divergentBatches[0]['records'][0];
    $divergentEntrypoint->public_path = '/divergent';
    $divergentEntrypoint->trigger = (object) ['kind' => 'http', 'value' => 'GET /divergent'];
    $divergentBatches[0]['records'] = [$divergentEntrypoint];
    $runDivergentPair = static function () use ($import, $batches, $divergentBatches, $afterFirstPass): void {
        app(GraphV2Normalizer::class)->passOne($import, $batches, static function (bool $force = false): void {});
        $afterFirstPass();
        app(GraphV2Normalizer::class)->passTwo($import, $divergentBatches, static function (bool $force = false): void {});
    };

    expect($runDivergentPair)->toThrow(GraphV2ImportException::class, 'pair');
});

it('isolates sequential public validations when B has an incompatible colliding edge', function (): void {
    [$firstImport, $firstBatches, $afterFirstPass] = publicFlowTopologyFixture('valid');
    runPublicFlowTopologyTwoPass($firstImport, $firstBatches, $afterFirstPass);
    $firstIds = DB::table('hades_graph_import_record_keys')->where('graph_import_id', $firstImport->id)->pluck('public_id')->all();

    [$secondImport, $secondBatches, $afterSecondPass] = publicFlowTopologyFixture('valid', $firstImport->workspace_binding_id, str_repeat('c', 64));
    $collidingEdgeId = $secondBatches[2]['records'][0]->id;
    $afterSecondPass = static function () use ($afterSecondPass, $secondImport, $collidingEdgeId): void {
        $afterSecondPass();
        // Mutation-sensitive: removing B's scoped edge row means an unscoped
        // edge join could incorrectly borrow A's valid edge with the same ID.
        DB::table('hades_graph_import_record_keys')
            ->where('graph_import_id', $secondImport->id)
            ->where('record_kind', 'edges')
            ->where('public_id', $collidingEdgeId)
            ->delete();
    };

    expect(fn () => runPublicFlowTopologyTwoPass($secondImport, $secondBatches, $afterSecondPass))
        ->toThrow(GraphV2ImportException::class);
    expect(DB::table('hades_graph_import_record_keys')->where('graph_import_id', $firstImport->id)->pluck('public_id')->all())
        ->toBe($firstIds)
        ->and(DB::table('hades_graph_import_record_keys')->where('graph_import_id', $firstImport->id)->where('record_kind', 'flows')->whereNull('root_node_public_id')->count())
        ->toBe(0)
        ->and(DB::table('hades_graph_import_record_keys')->where('graph_import_id', $secondImport->id)->where('record_kind', 'edges')->where('public_id', $collidingEdgeId)->count())
        ->toBe(0)
        ->and(DB::table('hades_graph_import_record_keys')->where('graph_import_id', $firstImport->id)->where('record_kind', 'edges')->where('public_id', $collidingEdgeId)->count())
        ->toBe(1);
});

it('keeps second-pass SELECT counts constant as evidence batches and manifest languages grow', function (): void {
    $measure = static function (bool $large): int {
        $import = partialFileCoverageImportFixture();
        $records = [partialFileNodeRecord($import)];
        $records[0]->properties->omission_reason = 'parser_unavailable';
        $manifest = $import->manifest;
        $manifest['graph_contract']['completeness']['capabilities']['inventory']['reasons'][] = ['code' => 'parser_unavailable', 'count' => 1, 'language' => null, 'paths_sample' => []];
        $manifest['graph_contract']['completeness']['languages'][0]['capabilities']['inventory']['reasons'][] = ['code' => 'parser_unavailable', 'count' => 1, 'language' => 'php', 'paths_sample' => []];
        if ($large) {
            $records = [];
            $languageRecords = [];
            for ($index = 0; $index < 25; $index++) {
                $record = partialFileNodeRecord($import);
                $path = 'src/Omitted'.$index.'.php';
                $digest = str_pad(dechex($index), 64, 'a');
                $languageName = 'lang'.$index;
                $identity = (object) ['variant' => 'file', 'workspace_binding_id' => $import->workspace_binding_id, 'language' => $languageName, 'kind' => 'file', 'path' => $path];
                $record->id = app(GraphV2IdentityValidator::class)->nodeId($identity);
                $record->identity = $identity;
                $record->language = $languageName;
                $record->name = $path;
                $record->properties->file_sha256 = $digest;
                $record->properties->omission_reason = 'parser_unavailable';
                $record->evidence->primary->source_locator->path = $path;
                $record->evidence->primary->source_fingerprint = app(GraphV2Canonicalizer::class)->sha256(['file_sha256' => $digest, 'occurrence_kind' => 'file', 'path' => $path]);
                $records[] = $record;
                $languageRecords[] = ['name' => $languageName, 'extractor' => 'test', 'extractor_version' => '1', 'detected_file_count' => 1, 'analyzed_file_count' => 1];
            }
            usort($records, static fn (object $left, object $right): int => strcmp($left->id, $right->id));
            $globalCapabilities = fullGraphCapabilities();
            $globalCapabilities['inventory'] = ['status' => 'partial', 'reasons' => [
                ['code' => 'resource_budget_reached', 'count' => 1, 'language' => null, 'paths_sample' => []],
                ['code' => 'parser_unavailable', 'count' => 25, 'language' => null, 'paths_sample' => []],
            ]];
            $manifest['languages'] = $languageRecords;
            $manifest['graph_contract']['completeness']['capabilities'] = $globalCapabilities;
            $manifest['graph_contract']['completeness']['languages'] = [];
            foreach ($languageRecords as $languageRecord) {
                $languageCapabilities = fullGraphCapabilities();
                $languageCapabilities['inventory'] = ['status' => 'partial', 'reasons' => [['code' => 'parser_unavailable', 'count' => 1, 'language' => $languageRecord['name'], 'paths_sample' => []]]];
                $manifest['graph_contract']['completeness']['languages'][] = ['language' => $languageRecord['name'], 'status' => 'partial', 'capabilities' => $languageCapabilities];
            }
            $manifest['graph_contract']['coverage']['files'] = ['discovered' => 26, 'hashed' => 26, 'parser_candidates' => 25, 'analyzed' => 25, 'unsupported' => 0, 'failed' => 0, 'too_large' => 0, 'budget_omitted' => 1];
            $manifest['graph_contract']['coverage']['records']['nodes'] = 25;
            $manifest['counts']['nodes'] = 25;
        }
        $import->manifest = $manifest;
        $import->save();
        $batches = [['kind' => 'nodes', 'index' => 0, 'records' => $records]];
        app(GraphV2Normalizer::class)->passOne($import, $batches, static function (bool $force = false): void {});
        $selects = 0;
        DB::listen(function ($query) use (&$selects): void {
            if (preg_match('/^\s*(?:select|with)\b/i', trim((string) $query->sql)) === 1) {
                $selects++;
            }
        });
        app(GraphV2Normalizer::class)->passTwo($import, $batches, static function (bool $force = false): void {});

        return $selects;
    };

    expect($measure(true))->toBe($measure(false));
});

it('orders call-site uncertainty language by the first eligible serialized carrier edge', function (): void {
    $import = validationImportFixture();
    $uncertaintyId = 'hades:uncertainty:v2:'.str_repeat('u', 64);
    $otherUncertaintyId = 'hades:uncertainty:v2:'.str_repeat('v', 64);
    $callSiteId = 'hades:call-site:v2:'.str_repeat('c', 64);
    $sourcePhp = 'hades:node:v2:'.str_repeat('p', 64);
    $sourcePython = 'hades:node:v2:'.str_repeat('q', 64);
    $sourceRuby = 'hades:node:v2:'.str_repeat('r', 64);
    $decoyEdge = 'hades:edge:v2:'.str_repeat('a', 64);
    $validEdge = 'hades:edge:v2:'.str_repeat('b', 64);
    DB::table('hades_graph_import_record_keys')->insert([
        ['graph_import_id' => $import->id, 'record_kind' => 'uncertainties', 'public_id' => $uncertaintyId, 'record_subkind' => 'call_target', 'reason_code' => 'call_target_unresolved', 'chunk_index' => 9, 'record_ordinal' => 9],
        ['graph_import_id' => $import->id, 'record_kind' => 'uncertainties', 'public_id' => $otherUncertaintyId, 'record_subkind' => 'call_target', 'reason_code' => 'call_target_unresolved', 'chunk_index' => 9, 'record_ordinal' => 10],
    ]);
    DB::table('hades_graph_import_record_keys')->insert(['graph_import_id' => $import->id, 'record_kind' => 'structures', 'public_id' => $callSiteId, 'record_subkind' => 'call_site', 'chunk_index' => 0, 'record_ordinal' => 0]);
    DB::table('hades_graph_import_record_keys')->insert([
        ['graph_import_id' => $import->id, 'record_kind' => 'nodes', 'public_id' => $sourcePhp, 'record_subkind' => 'class', 'language' => 'php', 'chunk_index' => 0, 'record_ordinal' => 0],
        ['graph_import_id' => $import->id, 'record_kind' => 'nodes', 'public_id' => $sourcePython, 'record_subkind' => 'class', 'language' => 'python', 'chunk_index' => 0, 'record_ordinal' => 1],
        ['graph_import_id' => $import->id, 'record_kind' => 'nodes', 'public_id' => $sourceRuby, 'record_subkind' => 'class', 'language' => 'ruby', 'chunk_index' => 0, 'record_ordinal' => 2],
    ]);
    DB::table('hades_graph_import_record_keys')->insert([
        // The Ruby edge sorts first, but belongs to another uncertainty. It is
        // the decoy that makes removing the uncertainty predicate observable.
        ['graph_import_id' => $import->id, 'record_kind' => 'edges', 'public_id' => $decoyEdge, 'record_subkind' => 'routes_to', 'source_node_public_id' => $sourceRuby, 'uncertainty_public_id' => $otherUncertaintyId, 'chunk_index' => 0, 'record_ordinal' => 0],
        ['graph_import_id' => $import->id, 'record_kind' => 'edges', 'public_id' => $validEdge, 'record_subkind' => 'routes_to', 'source_node_public_id' => $sourcePython, 'uncertainty_public_id' => $uncertaintyId, 'chunk_index' => 1, 'record_ordinal' => 0],
    ]);
    DB::table('hades_graph_import_references')->insert([
        ['graph_import_id' => $import->id, 'owner_record_kind' => 'uncertainties', 'owner_public_id' => $uncertaintyId, 'reference_kind' => 'subject.call_site_id', 'target_public_id' => $callSiteId],
        ['graph_import_id' => $import->id, 'owner_record_kind' => 'uncertainties', 'owner_public_id' => $otherUncertaintyId, 'reference_kind' => 'subject.call_site_id', 'target_public_id' => $callSiteId],
        ['graph_import_id' => $import->id, 'owner_record_kind' => 'edges', 'owner_public_id' => $decoyEdge, 'reference_kind' => 'call_site_id', 'target_public_id' => $callSiteId],
        ['graph_import_id' => $import->id, 'owner_record_kind' => 'edges', 'owner_public_id' => $validEdge, 'reference_kind' => 'call_site_id', 'target_public_id' => $callSiteId],
    ]);
    $reflection = new ReflectionMethod(GraphV2Normalizer::class, 'uncertaintyLanguageRelation');
    $reflection->setAccessible(true);
    $row = $reflection->invoke(app(GraphV2Normalizer::class), $import)
        ->where('uncertainty_public_id', $uncertaintyId)
        ->first();

    expect($row->language)->toBe('python');
});

it('uses the last global scope reason duplicate as its canonical row', function (): void {
    $import = partialFileCoverageImportFixture();
    $manifest = $import->manifest;
    $manifest['graph_contract']['completeness']['capabilities']['inventory']['reasons'] = [
        ['code' => 'resource_budget_reached', 'count' => 0, 'language' => null, 'paths_sample' => []],
        ['code' => 'resource_budget_reached', 'count' => 1, 'language' => null, 'paths_sample' => ['last']],
        ['code' => 'parser_unavailable', 'count' => 0, 'language' => null, 'paths_sample' => ['first']],
        ['code' => 'parser_unavailable', 'count' => 1, 'language' => null, 'paths_sample' => ['last']],
    ];
    $import->manifest = $manifest;
    $import->save();

    $node = partialFileNodeRecord($import);
    $node->properties->omission_reason = 'parser_unavailable';
    expect(fn () => runPublicTwoPass($import, [['kind' => 'nodes', 'index' => 0, 'records' => [$node]]]))
        ->not->toThrow(Throwable::class);
});

it('uses the last language scope reason duplicate as its canonical row', function (): void {
    $import = partialFileCoverageImportFixture();
    $manifest = $import->manifest;
    $manifest['graph_contract']['completeness']['languages'][0]['capabilities']['inventory']['reasons'] = [
        ['code' => 'resource_budget_reached', 'count' => 0, 'language' => 'php', 'paths_sample' => []],
        ['code' => 'resource_budget_reached', 'count' => 1, 'language' => 'php', 'paths_sample' => ['last']],
        ['code' => 'parser_unavailable', 'count' => 0, 'language' => 'php', 'paths_sample' => ['first']],
        ['code' => 'parser_unavailable', 'count' => 1, 'language' => 'php', 'paths_sample' => ['last']],
    ];
    $manifest['graph_contract']['completeness']['capabilities']['inventory']['reasons'][] = ['code' => 'parser_unavailable', 'count' => 1, 'language' => null, 'paths_sample' => []];
    $import->manifest = $manifest;
    $import->save();

    $node = partialFileNodeRecord($import);
    $node->properties->omission_reason = 'parser_unavailable';
    expect(fn () => runPublicTwoPass($import, [['kind' => 'nodes', 'index' => 0, 'records' => [$node]]]))
        ->not->toThrow(Throwable::class);
});

it('keeps the first producer duplicate distinct from the last language-scope row', function (): void {
    $import = producerReasonImportFixture(
        [['code' => 'resource_budget_reached', 'count' => 2, 'language' => null, 'paths_sample' => []]],
        ['php' => [
            ['code' => 'resource_budget_reached', 'count' => 1, 'language' => 'php', 'paths_sample' => ['first']],
            ['code' => 'resource_budget_reached', 'count' => 2, 'language' => 'php', 'paths_sample' => ['last']],
        ]],
    );

    expect(fn () => runPublicTwoPass($import, []))
        ->toThrow(GraphV2ImportException::class, 'Producer-fact');
});

it('adds uncertainty and omission observations sharing one reason and language key', function (): void {
    $import = partialFileCoverageImportFixture();
    $manifest = $import->manifest;
    $manifest['graph_contract']['completeness']['capabilities']['inventory']['reasons'] = [
        ['code' => 'resource_budget_reached', 'count' => 1, 'language' => null, 'paths_sample' => []],
        ['code' => 'parser_unavailable', 'count' => 2, 'language' => null, 'paths_sample' => []],
    ];
    $manifest['graph_contract']['completeness']['languages'][0]['capabilities']['inventory']['reasons'] = [
        ['code' => 'resource_budget_reached', 'count' => 1, 'language' => 'php', 'paths_sample' => []],
        ['code' => 'parser_unavailable', 'count' => 2, 'language' => 'php', 'paths_sample' => []],
    ];
    $manifest['counts']['nodes'] = 2;
    $manifest['counts']['edges'] = 1;
    $manifest['counts']['uncertainties'] = 1;
    $manifest['graph_contract']['coverage']['files']['discovered'] = 2;
    $manifest['graph_contract']['coverage']['files']['hashed'] = 2;
    $manifest['graph_contract']['coverage']['files']['analyzed'] = 1;
    $manifest['graph_contract']['coverage']['records']['nodes'] = 2;
    $manifest['graph_contract']['coverage']['records']['edges'] = 1;
    $manifest['graph_contract']['coverage']['records']['uncertainties'] = 1;
    $import->manifest = $manifest;
    $import->save();
    $node = partialFileNodeRecord($import);
    // The tiny fixture cannot express a complete schema-valid uncertainty and
    // its carrier through the public chunk contract without rebuilding the
    // whole graph. Stage only that SQL evidence after the public node pass;
    // every row and reference remains import-scoped and referentially valid.
    $node->properties->omission_reason = 'parser_unavailable';
    $uncertaintyId = 'hades:uncertainty:v2:'.str_repeat('u', 64);
    $sourceNodeId = 'hades:node:v2:'.str_repeat('s', 64);
    $edgeId = 'hades:edge:v2:'.str_repeat('e', 64);

    $batches = [['kind' => 'nodes', 'index' => 0, 'records' => [$node]]];
    $stagedManifest = $manifest;
    $manifest['counts']['nodes'] = 1;
    $manifest['counts']['edges'] = 0;
    $manifest['counts']['uncertainties'] = 0;
    $manifest['graph_contract']['coverage']['records']['nodes'] = 1;
    $manifest['graph_contract']['coverage']['records']['edges'] = 0;
    $manifest['graph_contract']['coverage']['records']['uncertainties'] = 0;
    $import->manifest = $manifest;
    $import->save();
    app(GraphV2Normalizer::class)->passOne($import, $batches, static function (bool $force = false): void {});
    $import->manifest = $stagedManifest;
    $import->save();
    $stageRecord = static function (string $kind, string $id, array $extra) use ($import): void {
        DB::table('hades_graph_import_record_keys')->insert(array_merge([
            'graph_import_id' => $import->id, 'record_kind' => $kind, 'public_id' => $id,
            'record_subkind' => null, 'reason_code' => null, 'identity_variant' => null,
            'owner_public_id' => null, 'aux_public_id' => null, 'language' => null, 'analysis_status' => null,
            'flow_public_id' => null, 'edge_public_id' => null, 'source_node_public_id' => null,
            'target_node_public_id' => null, 'uncertainty_public_id' => null, 'root_node_public_id' => null,
            'stage_from' => null, 'stage_to' => null, 'async_context' => null, 'async_child_flow_id' => null,
            'relation' => null, 'edge_flow' => null, 'branch_group_id' => null,
            'occurrence_owner_public_id' => null, 'backbone_role' => null, 'omission_reason' => null,
            'identity_digest' => null, 'entrypoint_identity_digest' => null, 'count_hint' => null,
            'flow_counts' => null, 'flow_capabilities' => null, 'completeness_status' => null,
            'stage_counts' => null, 'chunk_index' => 9, 'record_ordinal' => 0,
        ], $extra));
    };
    $stageRecord('nodes', $sourceNodeId, ['record_subkind' => 'class', 'language' => 'php']);
    $stageRecord('edges', $edgeId, ['record_subkind' => 'routes_to', 'source_node_public_id' => $sourceNodeId, 'target_node_public_id' => $sourceNodeId, 'uncertainty_public_id' => $uncertaintyId, 'record_ordinal' => 1]);
    $stageRecord('uncertainties', $uncertaintyId, ['record_subkind' => 'external_target', 'reason_code' => 'parser_unavailable', 'record_ordinal' => 2]);
    DB::table('hades_graph_import_references')->insert([
        ['graph_import_id' => $import->id, 'owner_record_kind' => 'uncertainties', 'owner_public_id' => $uncertaintyId, 'reference_kind' => 'subject.edge_id', 'target_record_kind' => 'edges', 'target_public_id' => $edgeId],
    ]);

    $closure = new ReflectionMethod(GraphV2Normalizer::class, 'assertCompletenessAndReasonClosure');
    $closure->setAccessible(true);
    expect(fn () => $closure->invoke(app(GraphV2Normalizer::class), $import->fresh()))
        ->not->toThrow(Throwable::class);
    $manifest = $import->fresh()->manifest;
    $manifest['graph_contract']['completeness']['capabilities']['inventory']['reasons'][1]['count'] = 1;
    $manifest['graph_contract']['completeness']['languages'][0]['capabilities']['inventory']['reasons'][1]['count'] = 1;
    $import->manifest = $manifest;
    $import->save();
    expect(fn () => $closure->invoke(app(GraphV2Normalizer::class), $import->fresh()))
        ->toThrow(GraphV2ImportException::class, 'reason count');
});

it('sums global producer facts only across languages with the same capability', function (): void {
    $import = producerReasonImportFixture(
        [['code' => 'resource_budget_reached', 'count' => 5, 'language' => null, 'paths_sample' => []]],
        [
            'php' => [['code' => 'resource_budget_reached', 'count' => 2, 'language' => 'php', 'paths_sample' => []]],
            'python' => [['code' => 'resource_budget_reached', 'count' => 3, 'language' => 'python', 'paths_sample' => []]],
        ],
    );
    $manifest = $import->manifest;
    $manifest['graph_contract']['completeness']['capabilities']['call_graph'] = ['status' => 'partial', 'reasons' => [['code' => 'resource_budget_reached', 'count' => 4, 'language' => null, 'paths_sample' => []]]];
    foreach ($manifest['graph_contract']['completeness']['languages'] as &$language) {
        $language['capabilities']['call_graph'] = ['status' => 'partial', 'reasons' => [['code' => 'resource_budget_reached', 'count' => 2, 'language' => $language['language'], 'paths_sample' => []]]];
    }
    unset($language);
    $import->manifest = $manifest;
    $import->save();

    expect(fn () => runPublicTwoPass($import, []))->not->toThrow(Throwable::class);
});

it('uses only the requested language and capability for a scoped producer fact', function (): void {
    $import = producerReasonImportFixture(
        [['code' => 'resource_budget_reached', 'count' => 2, 'language' => 'php', 'paths_sample' => []]],
        [
            'php' => [['code' => 'resource_budget_reached', 'count' => 2, 'language' => 'php', 'paths_sample' => []]],
            'python' => [['code' => 'resource_budget_reached', 'count' => 3, 'language' => 'python', 'paths_sample' => ['python']]],
        ],
    );
    $manifest = $import->manifest;
    $manifest['graph_contract']['completeness']['capabilities']['symbol_resolution']['reasons'][] = ['code' => 'resource_budget_reached', 'count' => 5, 'language' => null, 'paths_sample' => ['aggregate']];
    $manifest['graph_contract']['completeness']['capabilities']['call_graph'] = ['status' => 'partial', 'reasons' => [['code' => 'resource_budget_reached', 'count' => 4, 'language' => null, 'paths_sample' => ['call-graph']]]];
    $manifest['graph_contract']['completeness']['languages'][0]['capabilities']['call_graph'] = ['status' => 'partial', 'reasons' => [['code' => 'resource_budget_reached', 'count' => 4, 'language' => 'php', 'paths_sample' => ['call-graph']]]];
    $import->manifest = $manifest;
    $import->save();

    expect(fn () => runPublicTwoPass($import, []))->not->toThrow(Throwable::class);
});

it('rejects global bundle excess reused across language-scoped reasons', function (): void {
    $import = producerReasonImportFixture(
        [
            ['code' => 'record_too_large', 'count' => 1, 'language' => 'php', 'paths_sample' => []],
            ['code' => 'record_too_large', 'count' => 1, 'language' => 'python', 'paths_sample' => []],
        ],
        [
            'php' => [['code' => 'record_too_large', 'count' => 1, 'language' => 'php', 'paths_sample' => []]],
            'python' => [['code' => 'record_too_large', 'count' => 1, 'language' => 'python', 'paths_sample' => []]],
        ],
        'inventory',
    );

    expect(fn () => runPublicTwoPass($import, []))->toThrow(GraphV2ImportException::class, 'double-count');
});

it('excludes non-inventory producer facts from bundle excess accounting', function (): void {
    $import = producerReasonImportFixture(
        [
            ['code' => 'resource_budget_reached', 'count' => 1, 'language' => null, 'paths_sample' => ['producer']],
            ['code' => 'record_too_large', 'count' => 1, 'language' => null, 'paths_sample' => ['bundle']],
        ],
        ['php' => [
            ['code' => 'resource_budget_reached', 'count' => 1, 'language' => 'php', 'paths_sample' => ['producer']],
            ['code' => 'record_too_large', 'count' => 1, 'language' => 'php', 'paths_sample' => ['bundle']],
        ]],
        'symbol_resolution',
    );

    expect(fn () => runPublicTwoPass($import, []))->not->toThrow(Throwable::class);
});

it('matches literal Python golden vectors for every v2 identity family', function (): void {
    $validator = app(GraphV2IdentityValidator::class);
    $binding = '01J00000000000000000000000';
    $owner = 'hades:node:v2:'.str_repeat('1', 64);
    $nodeVectors = [
        ['identity' => ['variant' => 'source_declaration', 'workspace_binding_id' => $binding, 'language' => 'php', 'kind' => 'service', 'namespace' => 'Café', 'qualified_name' => 'Café\\Service::déjà', 'path' => 'src/Cafe.php'], 'id' => 'hades:node:v2:5227e83eeee9301cb19d0365b3056b290a5179252e11039aedca41a392e7351e'],
        ['identity' => ['variant' => 'file', 'workspace_binding_id' => $binding, 'language' => null, 'kind' => 'file', 'path' => 'src/é.php'], 'id' => 'hades:node:v2:29c36a415f2e71f8de2118193ee7d1889d852bddbd16951756e9408347176587'],
        ['identity' => ['variant' => 'source_occurrence', 'workspace_binding_id' => $binding, 'language' => 'php', 'kind' => 'basic_block', 'owner_node_id' => $owner, 'structural_path' => 'body/é/0', 'ordinal' => 0, 'semantic_role' => 'handler'], 'id' => 'hades:node:v2:c454bbe1dd41c26d7d013c98fb1dc2c9804ae9551f1008f537aec3bbbb4d493a'],
        ['identity' => ['variant' => 'anonymous_callable', 'workspace_binding_id' => $binding, 'language' => 'php', 'kind' => 'function', 'owner_node_id' => $owner, 'structural_path' => 'body/λ/0', 'ordinal' => 1], 'id' => 'hades:node:v2:789af0ccbd953c6251e47226d2b2343e5268a638b420500ce1d5a84da9cd3459'],
        ['identity' => ['variant' => 'entrypoint', 'workspace_binding_id' => $binding, 'language' => 'php', 'kind' => 'entrypoint', 'path' => 'routes/é.php', 'entrypoint_identity' => ['entrypoint_kind' => 'http_route', 'framework' => null, 'method_semantics' => 'explicit', 'methods' => ['GET'], 'public_path' => '/déjà', 'public_name' => null, 'trigger' => ['kind' => 'http', 'value' => 'GET /déjà'], 'match_constraints' => ['host' => null, 'schemes' => [], 'condition_hash' => null], 'registration_occurrence' => ['kind' => 'config', 'path' => 'config/routes.yaml', 'structural_pointer' => 'routes/é', 'ordinal' => 0]]], 'id' => 'hades:node:v2:034c505f0cc3f052cb49358a99b4d1470e8a7af47cb2aefcddb61b0820174f58'],
        ['identity' => ['variant' => 'semantic_resource', 'workspace_binding_id' => $binding, 'language' => null, 'kind' => 'table', 'framework' => null, 'namespace' => null, 'qualified_name' => null, 'public_resource_name' => 'tavolo', 'protocol' => null, 'operation' => null], 'id' => 'hades:node:v2:88ad1c7252b98100183953293a99b2353bc9a2e6f9fab6c3b5c35f187cbb8193'],
    ];
    foreach ($nodeVectors as $vector) {
        expect($validator->nodeId(json_decode(json_encode($vector['identity'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR)))->toBe($vector['id']);
    }

    $structure = ['kind' => 'call_site', 'owner_node_id' => $owner, 'structural_path' => 'body/é/0', 'ordinal' => 0, 'subtype' => 'call'];
    expect($validator->structureId((object) $structure))->toBe('hades:call-site:v2:10d2142de033ab21830de677555b30f99827b77a14d80a647cedcf64c5b1c6f4');
    $edge = ['source_id' => 'hades:node:v2:'.str_repeat('2', 64), 'target_id' => 'hades:node:v2:'.str_repeat('3', 64), 'relation' => 'invokes', 'flow' => 'always', 'condition_hash' => null, 'branch_group_id' => null, 'call_site_id' => 'hades:call-site:v2:10d2142de033ab21830de677555b30f99827b77a14d80a647cedcf64c5b1c6f4', 'exception_scope_id' => null, 'occurrence' => ['kind' => 'ast', 'owner_node_id' => $owner, 'ast_path' => 'body/é/0', 'ordinal' => 0]];
    expect($validator->edgeId((object) $edge))->toBe('hades:edge:v2:8740b88005a799d7bc828e71b5968512e3a78cc403da94cedaf5f1403b5f35d4');
    $flow = ['entrypoint_id' => 'hades:node:v2:'.str_repeat('4', 64), 'root_node_id' => 'hades:node:v2:'.str_repeat('5', 64), 'kind' => 'async_flow'];
    $flowId = 'hades:flow:v2:127163647f73cd99dc241f98b1bb3c2542d09cd851aed7ccdb9ae666c33fc7a7';
    expect($validator->flowId((object) $flow))->toBe($flowId);
    expect($validator->flowStepId((object) ['flow_id' => $flowId, 'edge_id' => $edge['id'] ?? 'hades:edge:v2:8740b88005a799d7bc828e71b5968512e3a78cc403da94cedaf5f1403b5f35d4', 'stage_from' => 'handler', 'stage_to' => 'data', 'async_context' => 'linked_async']))->toBe('hades:flow-step:v2:4620a2ea42edb2763129b30d2c1cfd7d7aaaafabbb4fea667e623b58a7e9402b');
    $import = new HadesGraphImport(['project_id' => '01J00000000000000000000001', 'workspace_binding_id' => $binding]);
    $uncertainty = (object) ['domain' => 'graph', 'subject' => (object) ['edge_id' => 'hades:edge:v2:8740b88005a799d7bc828e71b5968512e3a78cc403da94cedaf5f1403b5f35d4'], 'resolution_kind' => 'external_target', 'reason_code' => 'external_boundary_unresolved', 'question' => 'Quale destinazione?'];
    expect($validator->uncertaintyId($uncertainty, $import))->toBe('hades:uncertainty:v2:05e561cc1a2ba222f94da4e49cbe0f566b470ba0644889c29a353c374619622d');
});

/** @return array{0:HadesGraphImport,1:list<array{kind:string,index:int,records:list<object>}>,2:Closure} */
function publicFlowTopologyFixture(string $case, ?string $workspaceBindingId = null, ?string $artifactGraphVersion = null): array
{
    $import = validationImportFixture($workspaceBindingId, $artifactGraphVersion);
    $fileDigest = str_repeat('a', 64);
    $configPath = 'config/routes.yaml';
    $handlerPath = 'src/Handler.php';
    $validator = app(GraphV2IdentityValidator::class);
    $entrypointIdentity = (object) [
        'entrypoint_kind' => 'http_route', 'framework' => null, 'method_semantics' => 'explicit', 'methods' => ['GET'],
        'public_path' => '/', 'public_name' => null,
        'trigger' => (object) ['kind' => 'http', 'value' => 'GET /'],
        'match_constraints' => (object) ['host' => null, 'schemes' => [], 'condition_hash' => null],
        'registration_occurrence' => (object) ['kind' => 'config', 'path' => $configPath, 'structural_pointer' => 'routes/0', 'ordinal' => 0],
    ];
    $entrypointNodeIdentity = (object) [
        'variant' => 'entrypoint', 'workspace_binding_id' => $import->workspace_binding_id, 'language' => 'php', 'kind' => 'entrypoint',
        'path' => $configPath, 'entrypoint_identity' => $entrypointIdentity,
    ];
    $entrypointId = $validator->nodeId($entrypointNodeIdentity);
    $middleIdentity = (object) [
        'variant' => 'source_declaration', 'workspace_binding_id' => $import->workspace_binding_id, 'language' => 'php', 'kind' => 'handler',
        'namespace' => null, 'qualified_name' => 'Handler', 'path' => $handlerPath,
    ];
    $middleId = $validator->nodeId($middleIdentity);
    $terminalIdentity = (object) [
        'variant' => 'source_declaration', 'workspace_binding_id' => $import->workspace_binding_id, 'language' => 'php', 'kind' => 'response',
        'namespace' => null, 'qualified_name' => 'Response', 'path' => $handlerPath,
    ];
    $terminalId = $validator->nodeId($terminalIdentity);
    $evidence = static function (string $path, string $pointer) use ($fileDigest): object {
        return (object) ['primary' => (object) [
            'origin' => 'verified_from_code', 'extractor' => 'test',
            'source_locator' => (object) ['kind' => 'config', 'path' => $path, 'structural_pointer' => $pointer],
            'source_fingerprint' => app(GraphV2Canonicalizer::class)->sha256(['file_sha256' => $fileDigest, 'occurrence_kind' => 'config', 'path' => $path, 'structural_pointer' => $pointer]),
            'inference_rule' => null,
        ], 'supporting' => [], 'supporting_omitted_count' => 0];
    };
    $entrypoint = (object) [
        'id' => $entrypointId, 'entrypoint_kind' => 'http_route', 'framework' => null, 'method_semantics' => 'explicit', 'methods' => ['GET'],
        'public_path' => '/', 'public_name' => null, 'handler_node_id' => null, 'uncertainty_id' => null,
        'trigger' => $entrypointIdentity->trigger, 'match_constraints' => $entrypointIdentity->match_constraints,
        'registration_occurrence' => $entrypointIdentity->registration_occurrence, 'evidence' => $evidence($configPath, 'routes/0'),
    ];
    $entrypointNode = (object) [
        'id' => $entrypointId, 'identity' => $entrypointNodeIdentity, 'kind' => 'entrypoint', 'language' => 'php',
        'framework' => null, 'name' => '/', 'qualified_name' => null, 'namespace' => null, 'uncertainty_id' => null,
        'location' => null, 'properties' => (object) [], 'evidence' => $evidence($configPath, 'routes/0'),
    ];
    $middle = (object) [
        'id' => $middleId, 'identity' => $middleIdentity, 'kind' => 'handler', 'language' => 'php',
        'framework' => null, 'name' => 'Handler', 'qualified_name' => 'Handler', 'namespace' => null, 'uncertainty_id' => null,
        'location' => null, 'properties' => (object) [], 'evidence' => $evidence($handlerPath, 'handler/0'),
    ];
    $terminal = (object) [
        'id' => $terminalId, 'identity' => $terminalIdentity, 'kind' => 'response', 'language' => 'php',
        'framework' => null, 'name' => 'Response', 'qualified_name' => 'Response', 'namespace' => null, 'uncertainty_id' => null,
        'location' => null, 'properties' => (object) [], 'evidence' => $evidence($handlerPath, 'response/0'),
    ];
    $edgeRecord = static function (string $source, string $target, string $relation, string $pointer) use ($validator, $evidence, $configPath): object {
        $edge = (object) [
            'source_id' => $source, 'target_id' => $target, 'relation' => $relation, 'flow' => 'always', 'condition_hash' => null,
            'branch_group_id' => null, 'call_site_id' => null, 'exception_scope_id' => null,
            'occurrence' => (object) ['kind' => 'ast', 'owner_node_id' => $source, 'ast_path' => $pointer, 'ordinal' => 0],
            'evidence' => $evidence($configPath, $pointer),
        ];
        $edge->id = $validator->edgeId($edge);

        return $edge;
    };
    $edges = [];
    $steps = [];
    $edge1Target = $case === 'terminal_reached' ? $terminalId : $middleId;
    $edge1Relation = $case === 'structural' ? 'declares' : 'routes_to';
    $edge1 = $edgeRecord($entrypointId, $edge1Target, $edge1Relation, 'flow/0');
    $edges[] = $edge1;
    $step1 = (object) ['flow_id' => null, 'edge_id' => $edge1->id, 'stage_from' => 'entry', 'stage_to' => $case === 'terminal_reached' ? 'response' : 'handler', 'async_context' => 'synchronous'];
    $flowRecord = (object) [
        'entrypoint_id' => $entrypointId, 'root_node_id' => $entrypointId, 'kind' => 'request_lifecycle',
        'represented_step_count' => 1,
        'terminal_count' => (object) ['represented' => 0, 'value' => 0, 'knowledge' => 'absence_verified', 'reason' => null],
        'linked_async_flow_count' => (object) ['represented' => 0, 'value' => 0, 'knowledge' => 'absence_verified', 'reason' => null],
        'uncertainty_count' => (object) ['represented' => 0, 'value' => 0, 'knowledge' => 'absence_verified', 'reason' => null],
        'stage_counts' => (object) ['entry' => (object) ['represented' => 1, 'value' => 1, 'knowledge' => 'exact', 'reason' => null], 'handler' => (object) ['represented' => $case === 'terminal_reached' ? 0 : 1, 'value' => $case === 'terminal_reached' ? 0 : 1, 'knowledge' => 'exact', 'reason' => null]],
        'completeness' => (object) ['status' => 'full', 'capabilities' => (object) fullGraphCapabilities()],
    ];
    if ($case === 'terminal_reached') {
        $flowRecord->terminal_count = (object) ['represented' => 1, 'value' => 1, 'knowledge' => 'exact', 'reason' => null];
        $flowRecord->stage_counts->response = (object) ['represented' => 1, 'value' => 1, 'knowledge' => 'exact', 'reason' => null];
    }
    $flowId = $validator->flowId($flowRecord);
    $step1->flow_id = $flowId;
    $step1->id = $validator->flowStepId($step1);
    $steps[] = $step1;
    if (in_array($case, ['stage_mismatch', 'uncertainty_frontier', 'terminal_continuation'], true)) {
        $secondTarget = $case === 'terminal_continuation' ? $terminalId : $terminalId;
        $edge2 = $edgeRecord($middleId, $secondTarget, 'routes_to', 'flow/1');
        $edges[] = $edge2;
        $step2 = (object) ['flow_id' => $flowId, 'edge_id' => $edge2->id, 'stage_from' => $case === 'stage_mismatch' ? 'entry' : 'handler', 'stage_to' => 'response', 'async_context' => 'synchronous'];
        $step2->id = $validator->flowStepId($step2);
        $steps[] = $step2;
        $flowRecord->represented_step_count = 2;
        $flowRecord->stage_counts->response = (object) ['represented' => 1, 'value' => 1, 'knowledge' => 'exact', 'reason' => null];
    }
    if ($case === 'terminal_continuation') {
        $flowRecord->terminal_count = (object) ['represented' => 1, 'value' => 1, 'knowledge' => 'exact', 'reason' => null];
        $edge3 = $edgeRecord($terminalId, $middleId, 'routes_to', 'flow/2');
        $edges[] = $edge3;
        $step3 = (object) ['flow_id' => $flowId, 'edge_id' => $edge3->id, 'stage_from' => 'response', 'stage_to' => 'handler', 'async_context' => 'synchronous'];
        $step3->id = $validator->flowStepId($step3);
        $steps[] = $step3;
        $flowRecord->represented_step_count = 3;
    }
    $flowId = $validator->flowId($flowRecord);
    foreach ($steps as $step) {
        $step->flow_id = $flowId;
        $step->id = $validator->flowStepId($step);
    }
    usort($edges, static fn (object $left, object $right): int => strcmp($left->id, $right->id));
    usort($steps, static fn (object $left, object $right): int => strcmp($left->id, $right->id));
    $flowRecord->id = $flowId;
    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $counts['entrypoints'] = 1;
    $counts['nodes'] = 3;
    $counts['edges'] = count($edges);
    $counts['flows'] = 1;
    $counts['flow_steps'] = count($steps);
    $import->manifest = ['counts' => $counts, 'graph_contract' => ['completeness' => [], 'coverage' => ['records' => []]]];
    $import->save();
    $nodes = [$entrypointNode, $middle, $terminal];
    usort($nodes, static fn (object $left, object $right): int => strcmp($left->id, $right->id));
    $batches = [
        ['kind' => 'entrypoints', 'index' => 0, 'records' => [$entrypoint]],
        ['kind' => 'nodes', 'index' => 0, 'records' => $nodes],
        ['kind' => 'edges', 'index' => 0, 'records' => $edges],
        ['kind' => 'flows', 'index' => 0, 'records' => [$flowRecord]],
        ['kind' => 'flow_steps', 'index' => 0, 'records' => $steps],
    ];
    $afterFirstPass = static function () use ($import, $case, $edges, $configPath, $handlerPath, $fileDigest): void {
        DB::table('hades_graph_import_file_paths')->insert([
            ['graph_import_id' => $import->id, 'path' => $configPath, 'file_node_public_id' => 'hades:node:v2:'.str_repeat('f', 64), 'file_sha256' => $fileDigest],
            ['graph_import_id' => $import->id, 'path' => $handlerPath, 'file_node_public_id' => 'hades:node:v2:'.str_repeat('0', 64), 'file_sha256' => $fileDigest],
        ]);
        if ($case !== 'uncertainty_frontier') {
            return;
        }
        $uncertaintyId = 'hades:uncertainty:v2:'.str_repeat('u', 64);
        DB::table('hades_graph_import_record_keys')->where('graph_import_id', $import->id)->where('record_kind', 'edges')->where('public_id', $edges[0]->id)->update(['uncertainty_public_id' => $uncertaintyId]);
        DB::table('hades_graph_import_record_keys')->insert(['graph_import_id' => $import->id, 'record_kind' => 'uncertainties', 'public_id' => $uncertaintyId, 'record_subkind' => 'external_target', 'reason_code' => 'external_boundary_unresolved', 'chunk_index' => 0, 'record_ordinal' => 0]);
    };

    return [$import, $batches, $afterFirstPass];
}

/** @param list<array{kind:string,index:int,records:list<object>}> $batches */
function runPublicFlowTopologyTwoPass(HadesGraphImport $import, array $batches, Closure $afterFirstPass): void
{
    app(GraphV2Normalizer::class)->passOne($import, $batches, static function (bool $force = false): void {});
    $afterFirstPass();
    app(GraphV2Normalizer::class)->passTwo($import, $batches, static function (bool $force = false): void {});
}

/** @return array<string, array{status:string, reasons:list<array<string,mixed>>}> */
function fullGraphCapabilities(): array
{
    $capabilities = [];
    foreach (['inventory', 'entrypoint_discovery', 'symbol_resolution', 'call_graph', 'control_flow', 'framework_lifecycle', 'exceptions', 'async', 'data_access'] as $name) {
        $capabilities[$name] = ['status' => 'full', 'reasons' => []];
    }

    return $capabilities;
}

/** @param list<array{code:string,count:int,language:string|null,paths_sample:list<string>}> $globalReasons @param array<string,list<array{code:string,count:int,language:string|null,paths_sample:list<string>}>> $languageReasons */
function producerReasonImportFixture(array $globalReasons, array $languageReasons, string $capability = 'symbol_resolution'): HadesGraphImport
{
    $import = emptyPartialCoverageImportFixture();
    $manifest = $import->manifest;
    $globalCapabilities = fullGraphCapabilities();
    $globalCapabilities['entrypoint_discovery'] = ['status' => 'partial', 'reasons' => [['code' => 'resource_budget_reached', 'count' => 1, 'language' => null, 'paths_sample' => []]]];
    if ($globalReasons !== []) {
        $globalCapabilities[$capability] = ['status' => 'partial', 'reasons' => $globalReasons];
    }
    $manifest['graph_contract']['completeness']['capabilities'] = $globalCapabilities;
    $manifest['languages'] = [];
    $manifest['graph_contract']['completeness']['languages'] = [];
    foreach ($languageReasons as $language => $reasons) {
        $capabilities = fullGraphCapabilities();
        if ($reasons !== []) {
            $capabilities[$capability] = ['status' => 'partial', 'reasons' => $reasons];
        }
        $manifest['languages'][] = ['name' => $language, 'extractor' => 'test', 'extractor_version' => '1', 'detected_file_count' => 0, 'analyzed_file_count' => 0];
        $manifest['graph_contract']['completeness']['languages'][] = ['language' => $language, 'status' => $reasons === [] ? 'full' : 'partial', 'capabilities' => $capabilities];
    }
    $import->manifest = $manifest;
    $import->save();

    return $import;
}

function partialFileCoverageImportFixture(): HadesGraphImport
{
    $import = validationImportFixture();
    $globalCapabilities = fullGraphCapabilities();
    $globalCapabilities['inventory'] = ['status' => 'partial', 'reasons' => [['code' => 'resource_budget_reached', 'count' => 1, 'language' => null, 'paths_sample' => []]]];
    $languageCapabilities = fullGraphCapabilities();
    $languageCapabilities['inventory'] = ['status' => 'partial', 'reasons' => [['code' => 'resource_budget_reached', 'count' => 1, 'language' => 'php', 'paths_sample' => []]]];
    $import->manifest = [
        'counts' => ['entrypoints' => 0, 'nodes' => 1, 'structures' => 0, 'edges' => 0, 'flows' => 0, 'flow_steps' => 0, 'uncertainties' => 0],
        'languages' => [['name' => 'php', 'extractor' => 'test', 'extractor_version' => '1', 'detected_file_count' => 2, 'analyzed_file_count' => 1]],
        'graph_contract' => [
            'completeness' => ['status' => 'partial', 'capabilities' => $globalCapabilities, 'languages' => [['language' => 'php', 'status' => 'partial', 'capabilities' => $languageCapabilities]]],
            'coverage' => [
                'files' => ['discovered' => 2, 'hashed' => 2, 'parser_candidates' => 1, 'analyzed' => 1, 'unsupported' => 0, 'failed' => 0, 'too_large' => 0, 'budget_omitted' => 1],
                'entrypoints' => ['detected' => 0, 'analyzed' => 0, 'partial' => 0, 'by_kind' => []],
                'records' => ['nodes' => 1, 'structures' => 0, 'edges' => 0, 'flows' => 0, 'flow_steps' => 0, 'uncertainties' => 0, 'omitted_by_bundle_budget' => 1],
            ],
        ],
    ];
    $import->save();

    return $import;
}

function emptyPartialCoverageImportFixture(): HadesGraphImport
{
    $import = validationImportFixture();
    $capabilities = fullGraphCapabilities();
    $capabilities['entrypoint_discovery'] = ['status' => 'partial', 'reasons' => [['code' => 'resource_budget_reached', 'count' => 1, 'language' => null, 'paths_sample' => []]]];
    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $import->manifest = [
        'counts' => $counts,
        'languages' => [],
        'graph_contract' => [
            'completeness' => ['status' => 'partial', 'capabilities' => $capabilities, 'languages' => []],
            'coverage' => [
                'files' => ['discovered' => 0, 'hashed' => 0, 'parser_candidates' => 0, 'analyzed' => 0, 'unsupported' => 0, 'failed' => 0, 'too_large' => 0, 'budget_omitted' => 0],
                'entrypoints' => ['detected' => 1, 'analyzed' => 0, 'partial' => 1, 'by_kind' => ['http_route' => 1]],
                'records' => ['nodes' => 0, 'structures' => 0, 'edges' => 0, 'flows' => 0, 'flow_steps' => 0, 'uncertainties' => 0, 'omitted_by_bundle_budget' => 1],
            ],
        ],
    ];
    $import->save();

    return $import;
}

function partialFileNodeRecord(HadesGraphImport $import): object
{
    $path = 'src/Omitted.php';
    $digest = str_repeat('a', 64);
    $identity = (object) ['variant' => 'file', 'workspace_binding_id' => $import->workspace_binding_id, 'language' => 'php', 'kind' => 'file', 'path' => $path];
    $fingerprint = app(GraphV2Canonicalizer::class)->sha256(['file_sha256' => $digest, 'occurrence_kind' => 'file', 'path' => $path]);

    return (object) [
        'id' => app(GraphV2IdentityValidator::class)->nodeId($identity), 'identity' => $identity, 'kind' => 'file', 'language' => 'php',
        'framework' => null, 'name' => $path, 'qualified_name' => null, 'namespace' => null, 'uncertainty_id' => null, 'location' => null,
        'properties' => (object) ['file_sha256' => $digest, 'byte_size' => 1, 'analysis_status' => 'analyzed', 'omission_reason' => null, 'is_test' => false, 'is_generated' => false],
        'evidence' => (object) ['primary' => (object) ['origin' => 'verified_from_code', 'extractor' => 'test', 'source_locator' => (object) ['kind' => 'file', 'path' => $path], 'source_fingerprint' => $fingerprint, 'inference_rule' => null], 'supporting' => [], 'supporting_omitted_count' => 0],
    ];
}

/** @param list<array{kind:string,index:int,records:list<object>}> $batches */
function runPublicTwoPass(HadesGraphImport $import, array $batches): void
{
    app(GraphV2Normalizer::class)->passOne($import, $batches, static function (bool $force = false): void {});
    app(GraphV2Normalizer::class)->passTwo($import, $batches, static function (bool $force = false): void {});
}

/** @return array{0:HadesGraphImport,1:object} */
function flowCountImportFixture(bool $partial = false): array
{
    $import = validationImportFixture();
    $entrypointId = 'hades:node:v2:'.str_repeat('e', 64);
    $flow = (object) [
        'entrypoint_id' => $entrypointId, 'root_node_id' => $entrypointId, 'kind' => 'request_lifecycle',
        'represented_step_count' => 0,
        'terminal_count' => (object) ['represented' => 0, 'value' => 0, 'knowledge' => 'absence_verified', 'reason' => null],
        'linked_async_flow_count' => (object) ['represented' => 0, 'value' => 0, 'knowledge' => 'absence_verified', 'reason' => null],
        'uncertainty_count' => (object) ['represented' => 0, 'value' => 0, 'knowledge' => 'absence_verified', 'reason' => null],
        'stage_counts' => (object) [
            'entry' => (object) ['represented' => 1, 'value' => 1, 'knowledge' => 'exact', 'reason' => null],
            'handler' => (object) ['represented' => 0, 'value' => 0, 'knowledge' => 'absence_verified', 'reason' => null],
            'data' => (object) ['represented' => 0, 'value' => 0, 'knowledge' => 'absence_verified', 'reason' => null],
        ],
    ];
    $flow->id = app(GraphV2IdentityValidator::class)->flowId($flow);
    $capabilities = fullGraphCapabilities();
    if ($partial) {
        $capabilities['inventory'] = ['status' => 'partial', 'reasons' => [['code' => 'resource_budget_reached', 'count' => 1, 'language' => null, 'paths_sample' => []]]];
        $capabilities['control_flow'] = ['status' => 'partial', 'reasons' => [['code' => 'unsupported_language', 'count' => 1, 'language' => null, 'paths_sample' => []]]];
    }
    $counts = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
    $counts['flows'] = 1;
    $import->manifest = [
        'counts' => $counts,
        'languages' => [],
        'graph_contract' => [
            'completeness' => ['status' => $partial ? 'partial' : 'full', 'capabilities' => $capabilities, 'languages' => []],
            'coverage' => [
                'records' => ['flows' => 1],
            ],
        ],
    ];
    $flow->completeness = (object) ['status' => $partial ? 'partial' : 'full', 'capabilities' => (object) $capabilities];
    $import->save();

    app(GraphV2Normalizer::class)->passOne($import, [['kind' => 'flows', 'index' => 0, 'records' => [$flow]]], static function (bool $force = false): void {});
    DB::table('hades_graph_import_record_keys')->insert([
        ['graph_import_id' => $import->id, 'record_kind' => 'entrypoints', 'public_id' => $entrypointId, 'record_subkind' => 'http_route', 'identity_variant' => null, 'entrypoint_identity_digest' => str_repeat('a', 64), 'chunk_index' => 0, 'record_ordinal' => 0],
        ['graph_import_id' => $import->id, 'record_kind' => 'nodes', 'public_id' => $entrypointId, 'record_subkind' => 'entrypoint', 'identity_variant' => 'entrypoint', 'entrypoint_identity_digest' => str_repeat('a', 64), 'chunk_index' => 0, 'record_ordinal' => 0],
    ]);

    return [$import, $flow];
}

function runFlowCountSecondPass(HadesGraphImport $import, object $flow): void
{
    DB::table('hades_graph_import_record_keys')->where('graph_import_id', $import->id)->where('record_kind', 'flows')->where('public_id', $flow->id)->update([
        'flow_counts' => json_encode(['terminal_count' => $flow->terminal_count, 'linked_async_flow_count' => $flow->linked_async_flow_count, 'uncertainty_count' => $flow->uncertainty_count], JSON_THROW_ON_ERROR),
        'flow_capabilities' => json_encode($flow->completeness->capabilities, JSON_THROW_ON_ERROR),
        'completeness_status' => $flow->completeness->status,
        'count_hint' => $flow->represented_step_count,
        'stage_counts' => json_encode($flow->stage_counts, JSON_THROW_ON_ERROR),
    ]);
    app(GraphV2Normalizer::class)->passTwo($import, [['kind' => 'flows', 'index' => 0, 'records' => [$flow]]], static function (bool $force = false): void {});
}

function validationImportFixture(?string $workspaceBindingId = null, ?string $artifactGraphVersion = null): HadesGraphImport
{
    $now = now();
    if ($workspaceBindingId !== null) {
        $binding = DB::table('hades_workspace_bindings')->where('id', $workspaceBindingId)->firstOrFail();
        $projectId = $binding->project_id;
        $bindingId = $workspaceBindingId;
        $agentId = $binding->hades_agent_id;
    } else {
        $user = User::factory()->create(['status' => 'active']);
        $projectId = (string) Str::ulid();
        $bindingId = (string) Str::ulid();
        DB::table('projects')->insert([
            'id' => $projectId, 'name' => 'Validation project', 'slug' => 'validation-'.Str::lower(Str::random(8)),
            'description' => null, 'status' => 'active', 'default_code_exposure_policy' => 'full_code_artifacts',
            'created_by_user_id' => $user->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $agentId = (string) Str::ulid();
        DB::table('hades_agents')->insert([
            'id' => $agentId, 'project_id' => $projectId, 'external_agent_id' => 'validation-agent', 'label' => 'Validation agent',
            'platform' => 'test', 'version' => '1', 'declared_capabilities' => json_encode(['populate_backend_ast']),
            'effective_capabilities' => json_encode(['populate_backend_ast']), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('hades_workspace_bindings')->insert([
            'id' => $bindingId, 'project_id' => $projectId, 'hades_agent_id' => $agentId, 'external_agent_id' => 'validation-agent',
            'workspace_fingerprint' => 'validation-workspace', 'display_path' => '/validation', 'status' => 'linked',
            'linked_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    return HadesGraphImport::query()->create([
        'id' => (string) Str::ulid(), 'project_id' => $projectId, 'workspace_binding_id' => $bindingId, 'hades_agent_id' => $agentId,
        'attempt_generation' => 1, 'scope_generation' => ((int) DB::table('hades_graph_imports')->where('project_id', $projectId)->where('workspace_binding_id', $bindingId)->max('scope_generation')) + 1, 'schema' => 'hades.code_graph.v2', 'artifact_graph_version' => $artifactGraphVersion ?? str_repeat('a', 64),
        'manifest_semantic_sha256' => str_repeat('b', 64), 'source_identity' => [], 'manifest' => [], 'status' => 'validating',
        'completeness_status' => 'full', 'expected_chunks' => 0, 'received_chunks' => 0, 'expected_uncompressed_bytes' => 0,
        'received_uncompressed_bytes' => 0, 'expected_compressed_bytes' => 0, 'received_compressed_bytes' => 0,
        'validation_attempts' => 0, 'expires_at' => null,
    ]);
}
