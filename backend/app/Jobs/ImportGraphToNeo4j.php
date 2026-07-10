<?php

namespace App\Jobs;

use App\Services\GenesisGraphImportService;
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
            $service->importGenesis($this->importOrDeltaId, new class implements Neo4jClient
            {
                public function run(string $cypher, array $params = []): mixed
                {
                    return [];
                }
            }, 'fake', false);

            return;
        }

        $service->importGenesis($this->importOrDeltaId, null, 'neo4j', false);
    }

    private function handleDelta(GenesisGraphImportService $service): void
    {
        $mode = config('services.devboard.graph_import_mode', 'neo4j');
        $client = null;

        if ($mode === 'fake') {
            $client = new class implements Neo4jClient
            {
                public function run(string $cypher, array $params = []): mixed
                {
                    return [];
                }
            };
        }

        $service->importDelta($this->importOrDeltaId, $client, $mode);
    }

    public function failed(Throwable $exception): void
    {
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
            'payload' => json_encode([
                'genesis_import_id' => $this->importOrDeltaId,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'tries' => $this->tries,
                'backoff' => $this->backoff(),
            ], JSON_THROW_ON_ERROR),
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
            'payload' => json_encode([
                'delta_id' => $this->importOrDeltaId,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'tries' => $this->tries,
                'backoff' => $this->backoff(),
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
