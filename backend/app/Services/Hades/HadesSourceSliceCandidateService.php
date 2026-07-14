<?php

namespace App\Services\Hades;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HadesSourceSliceCandidateService
{
    public function __construct(private readonly HadesAgentJobPolicy $jobPolicy) {}

    public function reconcilePendingForBinding(object $agent, object $binding, int $limit): int
    {
        $limit = max(1, min(100, $limit));
        if (
            ! $this->jobPolicy->allowsCapability($agent, 'read_source_slice')
            || (string) $binding->project_id !== (string) $agent->project_id
            || (string) $binding->hades_agent_id !== (string) $agent->id
            || ($binding->status ?? null) !== 'linked'
        ) {
            return 0;
        }

        $candidateIds = DB::table('hades_source_slice_candidates')
            ->where('project_id', $binding->project_id)
            ->where('workspace_binding_id', $binding->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $created = 0;

        foreach ($candidateIds as $candidateId) {
            $created += DB::transaction(function () use ($agent, $binding, $candidateId): int {
                $candidate = DB::table('hades_source_slice_candidates')
                    ->where('id', $candidateId)
                    ->where('project_id', $binding->project_id)
                    ->where('workspace_binding_id', $binding->id)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();
                if (! $candidate) {
                    return 0;
                }

                $normalized = $this->normalizeCandidate((array) $candidate, $candidate->head_commit);
                if ($normalized === null) {
                    return 0;
                }

                $idempotencyKey = $this->candidateIdempotencyKey($binding, $normalized);
                $existingJob = DB::table('hades_agent_jobs')
                    ->where('project_id', $binding->project_id)
                    ->where('workspace_binding_id', $binding->id)
                    ->where('hades_agent_id', $agent->id)
                    ->where('capability', 'read_source_slice')
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingJob) {
                    DB::table('hades_source_slice_candidates')
                        ->where('id', $candidate->id)
                        ->update([
                            'status' => 'job_created',
                            'job_id' => $existingJob->id,
                            'updated_at' => now(),
                        ]);

                    return 0;
                }

                $now = now();
                $jobId = (string) Str::ulid();
                DB::table('hades_agent_jobs')->insert([
                    'id' => $jobId,
                    'project_id' => $binding->project_id,
                    'hades_agent_id' => $agent->id,
                    'workspace_binding_id' => $binding->id,
                    'idempotency_key' => $idempotencyKey,
                    'capability' => 'read_source_slice',
                    'status' => 'queued',
                    'policy' => 'confirm',
                    'priority' => 'normal',
                    'payload' => json_encode([
                        'path' => $normalized['path'],
                        'start_line' => $normalized['start_line'],
                        'end_line' => $normalized['end_line'],
                        'symbol' => $normalized['symbol'],
                        'candidate_key' => $normalized['candidate_key'],
                        'reason' => $normalized['reason'],
                        'head_commit' => $normalized['head_commit'],
                    ], JSON_THROW_ON_ERROR),
                    'result' => null,
                    'requires_confirmation' => true,
                    'deadline_at' => $now->copy()->addDays(7),
                    'available_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('hades_source_slice_candidates')
                    ->where('id', $candidate->id)
                    ->update([
                        'status' => 'job_created',
                        'job_id' => $jobId,
                        'updated_at' => $now,
                    ]);

                return 1;
            });
        }

        return $created;
    }

    /**
     * @return array{candidates: int, jobs_created: int}
     */
    public function ingestArtifactCandidates(object $agent, object $binding, array $artifact, ?string $headCommit): array
    {
        $items = $artifact['source_slice_candidates'] ?? [];
        if (! is_array($items)) {
            return ['candidates' => 0, 'jobs_created' => 0];
        }

        $candidateCount = 0;
        $jobsCreated = 0;
        $allowsReadSourceSlice = $this->jobPolicy->allowsCapability($agent, 'read_source_slice');

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $candidate = $this->normalizeCandidate($item, $headCommit);
            if ($candidate === null) {
                continue;
            }

            $candidateCount++;
            $jobsCreated += DB::transaction(function () use ($agent, $binding, $candidate, $allowsReadSourceSlice): int {
                $now = now();
                DB::table('hades_source_slice_candidates')->insertOrIgnore([
                    'project_id' => $binding->project_id,
                    'workspace_binding_id' => $binding->id,
                    'candidate_key' => $candidate['candidate_key'],
                    'path' => $candidate['path'],
                    'start_line' => $candidate['start_line'],
                    'end_line' => $candidate['end_line'],
                    'symbol' => $candidate['symbol'],
                    'reason' => $candidate['reason'],
                    'priority' => $candidate['priority'],
                    'head_commit' => $candidate['head_commit'],
                    'status' => 'pending',
                    'job_id' => null,
                    'source_slice_id' => null,
                    'metadata' => json_encode($candidate, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $existing = DB::table('hades_source_slice_candidates')
                    ->where('project_id', $binding->project_id)
                    ->where('workspace_binding_id', $binding->id)
                    ->where('candidate_key', $candidate['candidate_key'])
                    ->lockForUpdate()
                    ->first();

                if (! $existing || in_array($existing->status, ['job_created', 'slice_uploaded', 'rejected'], true)) {
                    return 0;
                }

                DB::table('hades_source_slice_candidates')
                    ->where('id', $existing->id)
                    ->update([
                        'path' => $candidate['path'],
                        'start_line' => $candidate['start_line'],
                        'end_line' => $candidate['end_line'],
                        'symbol' => $candidate['symbol'],
                        'reason' => $candidate['reason'],
                        'priority' => $candidate['priority'],
                        'head_commit' => $candidate['head_commit'],
                        'status' => 'pending',
                        'metadata' => json_encode($candidate, JSON_THROW_ON_ERROR),
                        'updated_at' => $now,
                    ]);

                if (! $allowsReadSourceSlice) {
                    return 0;
                }

                $idempotencyKey = $this->candidateIdempotencyKey($binding, $candidate);
                $existingJob = DB::table('hades_agent_jobs')
                    ->where('project_id', $binding->project_id)
                    ->where('workspace_binding_id', $binding->id)
                    ->where('hades_agent_id', $agent->id)
                    ->where('capability', 'read_source_slice')
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingJob) {
                    DB::table('hades_source_slice_candidates')
                        ->where('workspace_binding_id', $binding->id)
                        ->where('candidate_key', $candidate['candidate_key'])
                        ->update([
                            'status' => 'job_created',
                            'job_id' => $existingJob->id,
                            'updated_at' => $now,
                        ]);

                    return 0;
                }

                $jobId = (string) Str::ulid();
                DB::table('hades_agent_jobs')->insert([
                    'id' => $jobId,
                    'project_id' => $binding->project_id,
                    'hades_agent_id' => $agent->id,
                    'workspace_binding_id' => $binding->id,
                    'idempotency_key' => $idempotencyKey,
                    'capability' => 'read_source_slice',
                    'status' => 'queued',
                    'policy' => 'confirm',
                    'priority' => 'normal',
                    'payload' => json_encode([
                        'path' => $candidate['path'],
                        'start_line' => $candidate['start_line'],
                        'end_line' => $candidate['end_line'],
                        'symbol' => $candidate['symbol'],
                        'candidate_key' => $candidate['candidate_key'],
                        'reason' => $candidate['reason'],
                        'head_commit' => $candidate['head_commit'],
                    ], JSON_THROW_ON_ERROR),
                    'result' => null,
                    'requires_confirmation' => true,
                    'deadline_at' => $now->copy()->addDays(7),
                    'available_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('hades_source_slice_candidates')
                    ->where('workspace_binding_id', $binding->id)
                    ->where('candidate_key', $candidate['candidate_key'])
                    ->update([
                        'status' => 'job_created',
                        'job_id' => $jobId,
                        'updated_at' => $now,
                    ]);

                return 1;
            });
        }

        return ['candidates' => $candidateCount, 'jobs_created' => $jobsCreated];
    }

    private function candidateIdempotencyKey(object $binding, array $candidate): string
    {
        return 'source_slice_candidate:'.$binding->id.':'.$candidate['candidate_key'];
    }

    /**
     * @return array{candidate_key: string, path: string, start_line: int, end_line: int, symbol: string|null, reason: string, priority: int, head_commit: string|null}|null
     */
    private function normalizeCandidate(array $item, ?string $headCommit): ?array
    {
        $path = trim((string) ($item['path'] ?? ''));
        $candidateKey = trim((string) ($item['candidate_key'] ?? ''));
        if ($path === '' || $candidateKey === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            return null;
        }

        $startLine = max(1, (int) ($item['start_line'] ?? 1));
        $endLine = max($startLine, (int) ($item['end_line'] ?? $startLine));

        return [
            'candidate_key' => $candidateKey,
            'path' => $path,
            'start_line' => $startLine,
            'end_line' => $endLine,
            'symbol' => trim((string) ($item['symbol'] ?? '')) ?: null,
            'reason' => trim((string) ($item['reason'] ?? 'symbol')) ?: 'symbol',
            'priority' => max(1, min(1000, (int) ($item['priority'] ?? 500))),
            'head_commit' => trim((string) ($item['head_commit'] ?? $headCommit ?? '')) ?: null,
        ];
    }
}
