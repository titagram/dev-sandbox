<?php

namespace App\Jobs;

use App\Exceptions\CanonicalGraphProjectionException;
use App\Services\GenesisGraphImportService;
use App\Services\Graph\CanonicalGraphProjectionService;
use App\Services\Neo4j\Neo4jClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImportGraphToNeo4j implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $maxExceptions;

    public int $timeout = 120;

    public function __construct(
        public readonly string $scope,
        public readonly string $importOrDeltaId,
    ) {
        if (! in_array($this->scope, ['genesis', 'delta'], true)) {
            throw new \InvalidArgumentException("Invalid graph import scope [{$this->scope}].");
        }

        $this->tries = max(1, (int) config('services.devboard.graph_import_job_tries', 3));
        $this->maxExceptions = $this->tries;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        $configured = config('services.devboard.graph_import_job_backoff_seconds', [10, 60, 300]);

        if (! is_array($configured) || $configured === []) {
            return [10, 60, 300];
        }

        return array_map(
            static fn (mixed $value): int => max(0, (int) $value),
            $configured,
        );
    }

    public function handle(GenesisGraphImportService $service): void
    {
        if ($this->scope === 'genesis') {
            $this->handleGenesis($service);
        } else {
            $this->handleDelta($service);
        }
    }

    private function handleGenesis(GenesisGraphImportService $service): void
    {
        if (config('services.devboard.graph_import_mode') === 'fake') {
            $service->importGenesis($this->importOrDeltaId, $this->fakeClient(), 'fake', false);

            return;
        }

        $service->importGenesis($this->importOrDeltaId, null, 'neo4j', false);
    }

    private function handleDelta(GenesisGraphImportService $service): void
    {
        $mode = config('services.devboard.graph_import_mode', 'neo4j');
        $client = null;

        if ($mode === 'fake') {
            $client = $this->fakeClient();
        }

        $service->importDelta($this->importOrDeltaId, $client, $mode);
    }

    private function fakeClient(): Neo4jClient
    {
        return new class implements Neo4jClient
        {
            public function run(string $cypher, array $params = []): mixed
            {
                if (str_contains($cypher, 'RETURN nodes, count(r) AS relationships')) {
                    return [[
                        'nodes' => $params['expected_nodes'],
                        'relationships' => $params['expected_relationships'],
                    ]];
                }

                return [];
            }
        };
    }

    public function failed(Throwable $exception): void
    {
        if ($exception instanceof CanonicalGraphProjectionException) {
            app(CanonicalGraphProjectionService::class)->markFailedIfQueued(
                $exception->projectionId,
                $exception->failureCode,
            );
        }

        if ($this->scope === 'genesis') {
            $this->failedGenesis($exception);
        } else {
            $this->failedDelta($exception);
        }
    }

    private function failedGenesis(Throwable $exception): void
    {
        $import = DB::table('genesis_imports')->where('id', $this->importOrDeltaId)->first();
        if (! $import) {
            return;
        }

        DB::table('genesis_imports')->where('id', $this->importOrDeltaId)->update([
            'status' => 'failed',
            'updated_at' => now(),
        ]);

        DB::table('run_events')->insert([
            'id' => (string) Str::ulid(),
            'run_id' => $import->run_id,
            'event_type' => 'graph.import_failed',
            'severity' => 'error',
            'message' => 'Genesis graph import failed after queue retries.',
            'payload' => json_encode($this->failurePayload($exception, [
                'genesis_import_id' => $this->importOrDeltaId,
                'tries' => $this->tries,
                'backoff' => $this->backoff(),
            ]), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function failedDelta(Throwable $exception): void
    {
        $delta = DB::table('delta_syncs')->where('id', $this->importOrDeltaId)->first();
        if (! $delta) {
            return;
        }

        DB::table('delta_syncs')->where('id', $this->importOrDeltaId)->update([
            'status' => 'failed',
            'updated_at' => now(),
        ]);

        DB::table('run_events')->insert([
            'id' => (string) Str::ulid(),
            'run_id' => $delta->run_id,
            'event_type' => 'graph.import_failed',
            'severity' => 'error',
            'message' => 'Delta graph import failed after queue retries.',
            'payload' => json_encode($this->failurePayload($exception, [
                'delta_id' => $this->importOrDeltaId,
                'tries' => $this->tries,
                'backoff' => $this->backoff(),
            ]), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function failurePayload(Throwable $exception, array $payload): array
    {
        $payload['exception_class'] = $exception::class;
        $payload['exception_message'] = $exception instanceof CanonicalGraphProjectionException
            ? $exception->failureCode
            : $exception->getMessage();

        if ($exception instanceof CanonicalGraphProjectionException) {
            $payload['projection_id'] = $exception->projectionId;
            $payload['error_code'] = $exception->failureCode;
        }

        return $payload;
    }
}
