<?php

namespace App\Jobs;

use App\Models\HadesGraphImport;
use App\Services\Graph\V2\GraphV2ArtifactReaderContract;
use App\Services\Graph\V2\GraphV2ImportException;
use App\Services\Graph\V2\GraphV2InfrastructureException;
use App\Services\Graph\V2\GraphV2NormalizerContract;
use App\Services\Graph\V2\GraphV2ValidationRunService;
use App\Services\Graph\V2\LostValidationLease;
use Closure;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ValidateGraphV2Import implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = GraphV2ValidationRunService::LEASE_SECONDS;

    public function __construct(
        public readonly string $importId,
        public readonly int $attemptGeneration,
        public readonly int $validationAttempt,
        public readonly string $runToken,
    ) {}

    public function uniqueId(): string
    {
        return "graph-import:{$this->importId}:{$this->attemptGeneration}:validation:{$this->validationAttempt}";
    }

    public function handle(
        GraphV2ArtifactReaderContract $reader,
        GraphV2NormalizerContract $normalizer,
        GraphV2ValidationRunService $runs,
        ?Closure $monotonicClock = null,
        ?Closure $onHeartbeat = null,
    ): void {
        $import = HadesGraphImport::query()->whereKey($this->importId)->first();
        if ($import === null
            || (int) $import->attempt_generation !== $this->attemptGeneration
            || $import->status !== HadesGraphImport::STATUS_VALIDATING
            || (int) $import->validation_attempts !== $this->validationAttempt) {
            return;
        }

        $clock = $monotonicClock ?? static fn (): int => hrtime(true);
        $lastHeartbeatNs = $clock();
        $heartbeat = function (bool $force = false) use ($clock, &$lastHeartbeatNs, $onHeartbeat, $runs): void {
            $nowNs = $clock();
            if ($force || ($nowNs - $lastHeartbeatNs) >= 30_000_000_000) {
                if (! $runs->heartbeat($this->importId, $this->attemptGeneration, $this->validationAttempt, $this->runToken)) {
                    throw new LostValidationLease('validation_lease_lost');
                }
                $lastHeartbeatNs = $nowNs;
                $onHeartbeat?->__invoke();
            }
        };

        try {
            $heartbeat(true);
            $normalizer->passOne($import, $reader->batches($import), $heartbeat);
            $heartbeat(true);
            $result = $normalizer->passTwo($import, $reader->batches($import), $heartbeat);
            if (($result['artifact_graph_version'] ?? null) !== $import->artifact_graph_version) {
                throw new GraphV2ImportException('graph_artifact_version_mismatch', 'Validated graph artifact version does not match the manifest.');
            }
            $runs->recordSuccess($this->importId, $this->attemptGeneration, $this->validationAttempt, $this->runToken);
        } catch (GraphV2ImportException $exception) {
            if ($exception->errorCode === 'graph_validation_infrastructure_failed') {
                $this->recordTransient($runs, $exception);

                return;
            }
            $runs->recordDeterministicFailure(
                $this->importId,
                $this->attemptGeneration,
                $this->validationAttempt,
                $this->runToken,
                $exception->errorCode,
                ['message' => $exception->getMessage()],
            );
        } catch (LostValidationLease) {
            return;
        } catch (GraphV2InfrastructureException $exception) {
            $this->recordTransient($runs, $exception);
        }
    }

    private function recordTransient(GraphV2ValidationRunService $runs, Throwable $exception): void
    {
        $runs->recordTransientFailure(
            $this->importId,
            $this->attemptGeneration,
            $this->validationAttempt,
            $this->runToken,
            'graph_validation_infrastructure_failed',
            ['class' => $exception::class, 'message' => $exception->getMessage()],
        );
    }
}
