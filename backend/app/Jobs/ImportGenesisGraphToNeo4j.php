<?php

namespace App\Jobs;

use App\Services\GenesisGraphImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportGenesisGraphToNeo4j implements ShouldQueue
{
    use Queueable;

    private readonly ImportGraphToNeo4j $inner;

    public int $tries;

    public int $maxExceptions;

    public int $timeout;

    public function __construct(public readonly string $genesisImportId)
    {
        $this->inner = new ImportGraphToNeo4j('genesis', $this->genesisImportId);
        $this->tries = $this->inner->tries;
        $this->maxExceptions = $this->inner->maxExceptions;
        $this->timeout = $this->inner->timeout;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return $this->inner->backoff();
    }

    public function handle(GenesisGraphImportService $service): void
    {
        $this->inner->handle($service);
    }

    public function failed(Throwable $exception): void
    {
        $this->inner->failed($exception);
    }

    public static function dispatch(mixed ...$arguments): void
    {
        ImportGraphToNeo4j::dispatch('genesis', $arguments[0]);
    }
}
