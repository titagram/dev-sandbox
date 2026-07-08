<?php

namespace App\Jobs;

use App\Services\GenesisGraphImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImportGenesisGraphToNeo4j implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $maxExceptions;

    public int $timeout = 120;

    public function __construct(public readonly string $genesisImportId)
    {
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
        if (config('services.devboard.graph_import_mode') === 'fake') {
            $service->importGenesis($this->genesisImportId, new class
            {
                public function run(string $cypher, array $params): void
                {
                }
            }, 'fake', false);

            return;
        }

        $service->importGenesis($this->genesisImportId, null, 'neo4j', false);
    }

    public function failed(Throwable $exception): void
    {
        $import = DB::table('genesis_imports')->where('id', $this->genesisImportId)->first();
        if (! $import) {
            return;
        }

        DB::table('genesis_imports')->where('id', $this->genesisImportId)->update([
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
                'genesis_import_id' => $this->genesisImportId,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'tries' => $this->tries,
                'backoff' => $this->backoff(),
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
