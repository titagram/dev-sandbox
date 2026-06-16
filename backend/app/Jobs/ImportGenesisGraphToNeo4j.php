<?php

namespace App\Jobs;

use App\Services\GenesisGraphImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportGenesisGraphToNeo4j implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $genesisImportId)
    {
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
}
