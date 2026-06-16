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

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    public function __construct(public readonly string $genesisImportId)
    {
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(GenesisGraphImportService $service): void
    {
        if (config('services.devboard.graph_import_mode') === 'fake') {
            $service->importGenesis($this->genesisImportId, new class
            {
                public function run(string $cypher, array $params): void
                {
                }
            }, 'fake');

            return;
        }

        $service->importGenesis($this->genesisImportId);
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
