<?php

namespace App\Jobs;

use App\Models\HadesGraphImport;
use App\Services\Graph\V2\GraphV2ValidationRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class AcquireGraphV2ValidationRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $importId,
        public readonly int $attemptGeneration,
    ) {}

    public function handle(GraphV2ValidationRunService $runs): void
    {
        $import = HadesGraphImport::query()->whereKey($this->importId)->first();
        if ($import === null || (int) $import->attempt_generation !== $this->attemptGeneration) {
            return;
        }
        $runs->acquireAndDispatch($import);
    }
}
