<?php

namespace App\Services\Graph\V2;

use App\Jobs\AcquireGraphV2ValidationRun;
use App\Jobs\ValidateGraphV2Import;
use App\Models\HadesGraphImport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GraphV2ValidationRunService
{
    public const int MAX_RUNS = 4;

    public const int LEASE_SECONDS = 300;

    /** @var array<int, int> */
    private const array RETRY_DELAYS = [1 => 10, 2 => 30, 3 => 90];

    public function acquireAndDispatch(HadesGraphImport $import): bool
    {
        return DB::transaction(function () use ($import): bool {
            $locked = HadesGraphImport::query()->whereKey($import->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== HadesGraphImport::STATUS_VALIDATING) {
                return false;
            }
            if ($locked->validation_lease_expires_at !== null
                && $locked->validation_lease_expires_at->isFuture()) {
                return false;
            }
            $nextEligibleAt = data_get($locked->failure_details, 'next_eligible_at');
            if (is_string($nextEligibleAt) && Carbon::parse($nextEligibleAt)->isFuture()) {
                return false;
            }
            if ((int) $locked->validation_attempts >= self::MAX_RUNS) {
                return false;
            }

            $attempt = (int) $locked->validation_attempts + 1;
            $token = bin2hex(random_bytes(32));
            $now = now();
            $locked->update([
                'validation_attempts' => $attempt,
                'validation_run_token_hash' => hash('sha256', $token),
                'validation_started_at' => $locked->validation_started_at ?? $now,
                'validation_heartbeat_at' => $now,
                'validation_lease_expires_at' => $now->copy()->addSeconds(self::LEASE_SECONDS),
                'failure_code' => null,
                'failure_details' => null,
            ]);

            DB::afterCommit(function () use ($locked, $attempt, $token): void {
                ValidateGraphV2Import::dispatch(
                    (string) $locked->id,
                    (int) $locked->attempt_generation,
                    $attempt,
                    $token,
                );
            });

            return true;
        });
    }

    /**
     * Reconcile validation workers that disappeared after acquiring a lease.
     *
     * @return int number of runs made eligible or terminally reconciled
     */
    public function reconcileExpiredLeases(): int
    {
        $reconciled = 0;
        $now = now();

        HadesGraphImport::query()
            ->where('status', HadesGraphImport::STATUS_VALIDATING)
            ->where(function ($query) use ($now): void {
                $query->whereNull('validation_lease_expires_at')
                    ->orWhere('validation_lease_expires_at', '<=', $now);
            })
            ->orderBy('id')
            ->cursor()
            ->each(function (HadesGraphImport $import) use (&$reconciled): void {
                $action = DB::transaction(function () use ($import): string {
                    $locked = HadesGraphImport::query()->whereKey($import->id)->lockForUpdate()->first();
                    if ($locked === null || $locked->status !== HadesGraphImport::STATUS_VALIDATING) {
                        return 'skip';
                    }
                    if ($locked->validation_lease_expires_at !== null
                        && $locked->validation_lease_expires_at->isFuture()) {
                        return 'skip';
                    }

                    $attempt = (int) $locked->validation_attempts;
                    if ($attempt >= self::MAX_RUNS) {
                        $updated = DB::table('hades_graph_imports')
                            ->where('id', $locked->id)
                            ->where('status', HadesGraphImport::STATUS_VALIDATING)
                            ->where('validation_attempts', self::MAX_RUNS)
                            ->update([
                                'status' => HadesGraphImport::STATUS_FAILED,
                                'failure_code' => 'graph_validation_infrastructure_failed',
                                'failure_details' => json_encode(['reason' => 'validation_lease_expired'], JSON_THROW_ON_ERROR),
                                'validation_run_token_hash' => null,
                                'validation_lease_expires_at' => null,
                            ]);

                        return $updated === 1 ? 'terminal' : 'skip';
                    }

                    $nextEligibleAt = data_get($locked->failure_details, 'next_eligible_at');
                    if (! is_string($nextEligibleAt) && $locked->validation_lease_expires_at !== null) {
                        $delay = self::RETRY_DELAYS[$attempt] ?? 0;
                        $nextEligibleAt = $locked->validation_lease_expires_at->copy()->addSeconds($delay)->toISOString();
                        DB::table('hades_graph_imports')->where('id', $locked->id)->update([
                            'failure_code' => 'graph_validation_infrastructure_failed',
                            'failure_details' => json_encode([
                                'reason' => 'validation_lease_expired',
                                'next_eligible_at' => $nextEligibleAt,
                            ], JSON_THROW_ON_ERROR),
                            'validation_run_token_hash' => null,
                            'validation_lease_expires_at' => null,
                        ]);
                    }
                    if (is_string($nextEligibleAt) && Carbon::parse($nextEligibleAt)->isFuture()) {
                        return 'waiting';
                    }

                    $updated = DB::table('hades_graph_imports')
                        ->where('id', $locked->id)
                        ->where('status', HadesGraphImport::STATUS_VALIDATING)
                        ->where('validation_attempts', $attempt)
                        ->update([
                            'validation_run_token_hash' => null,
                            'validation_lease_expires_at' => null,
                        ]);

                    return $updated === 1 ? 'eligible' : 'skip';
                });

                if (in_array($action, ['eligible', 'terminal'], true)) {
                    $reconciled++;
                }
                if ($action === 'eligible') {
                    AcquireGraphV2ValidationRun::dispatch((string) $import->id, (int) $import->attempt_generation);
                }
            });

        return $reconciled;
    }

    /** @return int number of missing projection heads repaired */
    public function reconcileValidatedProjections(): int
    {
        $repaired = 0;
        $winners = DB::table('hades_graph_imports')
            ->where('status', HadesGraphImport::STATUS_VALIDATED)
            ->groupBy('project_id', 'workspace_binding_id')
            ->select('project_id', 'workspace_binding_id', DB::raw('MAX(scope_generation) as scope_generation'));
        HadesGraphImport::query()
            ->joinSub($winners, 'scope_winners', function ($join): void {
                $join->on('scope_winners.project_id', '=', 'hades_graph_imports.project_id')
                    ->on('scope_winners.workspace_binding_id', '=', 'hades_graph_imports.workspace_binding_id')
                    ->on('scope_winners.scope_generation', '=', 'hades_graph_imports.scope_generation');
            })
            ->where('hades_graph_imports.status', HadesGraphImport::STATUS_VALIDATED)
            ->select('hades_graph_imports.*')
            ->cursor()
            ->each(function (HadesGraphImport $import) use (&$repaired): void {
                if ($this->requestProjectionForValidatedImport((string) $import->id)) {
                    $repaired++;
                }
            });

        return $repaired;
    }

    public function heartbeat(string $importId, int $attemptGeneration, int $validationAttempt, string $runToken): bool
    {
        $updated = HadesGraphImport::query()
            ->whereKey($importId)
            ->where('attempt_generation', $attemptGeneration)
            ->where('status', HadesGraphImport::STATUS_VALIDATING)
            ->where('validation_attempts', $validationAttempt)
            ->where('validation_run_token_hash', hash('sha256', $runToken))
            ->where('validation_lease_expires_at', '>', now())
            ->update([
                'validation_heartbeat_at' => now(),
                'validation_lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
            ]);

        return $updated === 1;
    }

    public function recordSuccess(string $importId, int $attemptGeneration, int $validationAttempt, string $runToken): bool
    {
        $committed = DB::transaction(function () use ($attemptGeneration, $importId, $runToken, $validationAttempt): bool {
            $updated = HadesGraphImport::query()
                ->whereKey($importId)
                ->where('attempt_generation', $attemptGeneration)
                ->where('status', HadesGraphImport::STATUS_VALIDATING)
                ->where('validation_attempts', $validationAttempt)
                ->where('validation_run_token_hash', hash('sha256', $runToken))
                ->where('validation_lease_expires_at', '>', now())
                ->update([
                    'status' => HadesGraphImport::STATUS_VALIDATED,
                    'validated_at' => now(),
                    'expires_at' => null,
                    'validation_run_token_hash' => null,
                    'validation_lease_expires_at' => null,
                    'failure_code' => null,
                    'failure_details' => null,
                ]);
            if ($updated !== 1) {
                return false;
            }

            DB::afterCommit(function () use ($importId): void {
                try {
                    $this->requestProjectionForValidatedImport($importId);
                } catch (\Throwable) {
                    // The validated import remains durable; reconciliation repairs a lost request.
                }
            });

            return true;
        });

        return $committed;
    }

    public function recordDeterministicFailure(string $importId, int $attemptGeneration, int $validationAttempt, string $runToken, string $failureCode, array $details = []): bool
    {
        return $this->recordFailure($importId, $attemptGeneration, $validationAttempt, $runToken, $failureCode, $details, true);
    }

    public function recordTransientFailure(string $importId, int $attemptGeneration, int $validationAttempt, string $runToken, string $failureCode, array $details = []): bool
    {
        return $this->recordFailure($importId, $attemptGeneration, $validationAttempt, $runToken, $failureCode, $details, false);
    }

    /** @param array<string, mixed> $details */
    private function recordFailure(string $importId, int $attemptGeneration, int $validationAttempt, string $runToken, string $failureCode, array $details, bool $deterministic): bool
    {
        return DB::transaction(function () use ($attemptGeneration, $details, $deterministic, $failureCode, $importId, $runToken, $validationAttempt): bool {
            $query = HadesGraphImport::query()
                ->whereKey($importId)
                ->where('attempt_generation', $attemptGeneration)
                ->where('status', HadesGraphImport::STATUS_VALIDATING)
                ->where('validation_attempts', $validationAttempt)
                ->where('validation_run_token_hash', hash('sha256', $runToken))
                ->where('validation_lease_expires_at', '>', now());
            $row = $query->first();
            if ($row === null) {
                return false;
            }

            $terminal = $deterministic || $validationAttempt >= self::MAX_RUNS;
            $safeCode = $terminal && ! $deterministic ? 'graph_validation_infrastructure_failed' : $failureCode;
            $safeDetails = $this->safeDetails($details);
            if (! $terminal) {
                $delay = self::RETRY_DELAYS[$validationAttempt] ?? null;
                if ($delay !== null) {
                    $safeDetails['next_eligible_at'] = now()->addSeconds($delay)->toISOString();
                }
            }
            $updated = DB::table('hades_graph_imports')
                ->where('id', $importId)
                ->where('attempt_generation', $attemptGeneration)
                ->where('status', HadesGraphImport::STATUS_VALIDATING)
                ->where('validation_attempts', $validationAttempt)
                ->where('validation_run_token_hash', hash('sha256', $runToken))
                ->where('validation_lease_expires_at', '>', now())
                ->update([
                    'status' => $terminal ? HadesGraphImport::STATUS_FAILED : HadesGraphImport::STATUS_VALIDATING,
                    'failure_code' => $safeCode,
                    'failure_details' => json_encode($safeDetails, JSON_THROW_ON_ERROR),
                    'validation_run_token_hash' => null,
                    'validation_lease_expires_at' => null,
                ]);
            if ($updated !== 1) {
                return false;
            }

            if (! $terminal) {
                $delay = self::RETRY_DELAYS[$validationAttempt] ?? null;
                if ($delay !== null) {
                    DB::afterCommit(fn () => AcquireGraphV2ValidationRun::dispatch($importId, $attemptGeneration)->delay($delay));
                }
            }

            return true;
        });
    }

    public function requestProjectionForValidatedImport(string $importId): bool
    {
        return DB::transaction(function () use ($importId): bool {
            $candidate = HadesGraphImport::query()->whereKey($importId)->first();
            if ($candidate === null) {
                return false;
            }
            $binding = DB::table('hades_workspace_bindings')
                ->where('project_id', $candidate->project_id)
                ->where('id', $candidate->workspace_binding_id)
                ->lockForUpdate()
                ->first();
            if ($binding === null) {
                return false;
            }
            $import = HadesGraphImport::query()->whereKey($importId)->lockForUpdate()->first();
            if ($import === null || $import->status !== HadesGraphImport::STATUS_VALIDATED) {
                return false;
            }
            $verificationSetHash = hash('sha256', app(GraphV2Canonicalizer::class)->canonicalJson([]));
            $projectionVersion = hash('sha256', $import->artifact_graph_version.':'.$verificationSetHash);
            $head = DB::table('canonical_graph_projection_heads')
                ->where('project_id', $import->project_id)
                ->where('source_scope_type', 'workspace_binding')
                ->where('source_scope_id', $import->workspace_binding_id)
                ->lockForUpdate()
                ->first();
            if ($head !== null && $head->desired_source_generation !== null) {
                $currentGeneration = (int) $head->desired_source_generation;
                if ((int) $import->scope_generation < $currentGeneration
                    || ((int) $import->scope_generation === $currentGeneration
                        && (string) $head->desired_graph_import_id !== (string) $import->id)) {
                    return false;
                }
                if ((int) $import->scope_generation === $currentGeneration) {
                    return false;
                }
            }
            if ($head !== null
                && $head->desired_graph_import_id === $import->id
                && $head->desired_artifact_graph_version === $import->artifact_graph_version
                && $head->desired_projection_version === $projectionVersion) {
                return false;
            }
            $now = now();
            $attributes = [
                'project_id' => $import->project_id,
                'source_scope_type' => 'workspace_binding',
                'source_scope_id' => $import->workspace_binding_id,
                'desired_generation' => $head === null ? 1 : ((int) $head->desired_generation + 1),
                'desired_graph_import_id' => $import->id,
                'desired_source_generation' => $import->scope_generation,
                'desired_artifact_graph_version' => $import->artifact_graph_version,
                'desired_verification_set_hash' => $verificationSetHash,
                'desired_projection_version' => $projectionVersion,
                'failed_generation' => null,
                'failed_projection_version' => null,
                'failed_at' => null,
                'updated_at' => $now,
            ];
            if ($head === null) {
                DB::table('canonical_graph_projection_heads')->insert(array_merge($attributes, [
                    'id' => (string) Str::ulid(), 'active_projection_id' => null, 'previous_projection_id' => null,
                    'created_at' => $now,
                ]));
            } else {
                DB::table('canonical_graph_projection_heads')->where('id', $head->id)->update($attributes);
            }

            return true;
        });
    }

    /** @param array<string, mixed> $details @return array<string, scalar|null> */
    private function safeDetails(array $details): array
    {
        $safe = [];
        foreach ($details as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $safe[(string) $key] = is_string($value) ? substr($value, 0, 255) : $value;
            }
        }

        return $safe;
    }
}
