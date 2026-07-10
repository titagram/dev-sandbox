<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class ArtifactRetentionService
{
    /**
     * @return array{scanned: int, purged: int, skipped: int, failed: int, would_purge: int, failures: list<array{artifact_id: string, message: string}>}
     */
    public function purgeOlderThan(int $days, bool $dryRun = false, ?int $limit = null): array
    {
        if ($days < 1) {
            throw new InvalidArgumentException('Artifact retention days must be at least 1.');
        }

        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Artifact retention limit must be at least 1.');
        }

        $artifacts = $this->candidates($days, $limit);
        $result = [
            'scanned' => $artifacts->count(),
            'purged' => 0,
            'skipped' => 0,
            'failed' => 0,
            'would_purge' => 0,
            'failures' => [],
        ];

        foreach ($artifacts as $artifact) {
            if ($this->isPinned($artifact->id)) {
                $result['skipped']++;

                continue;
            }

            if ($dryRun) {
                $result['would_purge']++;

                continue;
            }

            try {
                $this->deleteArtifactContents($artifact->storage_path);
                DB::transaction(function () use ($artifact, $days): void {
                    DB::table('artifacts')->where('id', $artifact->id)->update([
                        'status' => 'purged',
                        'updated_at' => now(),
                    ]);
                    $this->auditPurge($artifact, $days);
                });
                $result['purged']++;
            } catch (Throwable $exception) {
                $result['failed']++;
                $result['failures'][] = [
                    'artifact_id' => $artifact->id,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * Purge abandoned `uploading` artifacts whose artifact row and parent
     * Genesis/Delta transfer row have both been untouched for at least the
     * configured TTL. Dry-run is the default so operator-triggered cleanup
     * never deletes content without an explicit opt-in.
     *
     * @return array{scanned: int, purged: int, skipped: int, failed: int, would_purge: int, failures: list<array{artifact_id: string, message: string}>}
     */
    public function purgeIncompleteUploads(?int $ttlHours = null, bool $dryRun = true, ?int $limit = null): array
    {
        $ttlHours = $ttlHours ?? (int) config('devboard.artifacts.incomplete_upload_ttl_hours', 24);

        if ($ttlHours < 1) {
            throw new InvalidArgumentException('Incomplete upload TTL hours must be at least 1.');
        }

        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Incomplete upload retention limit must be at least 1.');
        }

        $cutoff = now()->subHours($ttlHours);
        $artifacts = $this->incompleteUploadCandidates($cutoff, $limit);
        $result = [
            'scanned' => $artifacts->count(),
            'purged' => 0,
            'skipped' => 0,
            'failed' => 0,
            'would_purge' => 0,
            'failures' => [],
        ];

        foreach ($artifacts as $artifact) {
            if (! $this->parentTransferIsStale($artifact->storage_path, $cutoff)) {
                $result['skipped']++;

                continue;
            }

            if ($dryRun) {
                $result['would_purge']++;

                continue;
            }

            try {
                $this->deleteArtifactDirectory($artifact->storage_path);
                DB::transaction(function () use ($artifact, $ttlHours): void {
                    DB::table('artifacts')->where('id', $artifact->id)->update([
                        'status' => 'purged',
                        'updated_at' => now(),
                    ]);
                    $this->auditIncompleteUploadPurge($artifact, $ttlHours);
                });
                $result['purged']++;
            } catch (Throwable $exception) {
                $result['failed']++;
                $result['failures'][] = [
                    'artifact_id' => $artifact->id,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * @return Collection<int, object>
     */
    private function candidates(int $days, ?int $limit): Collection
    {
        return DB::table('artifacts')
            ->whereIn('status', ['validated', 'imported', 'rejected'])
            ->where('updated_at', '<=', now()->subDays($days))
            ->orderBy('updated_at')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();
    }

    private function isPinned(string $artifactId): bool
    {
        $snapshotIds = DB::table('snapshots')
            ->where(function ($query) use ($artifactId): void {
                $query
                    ->where('file_inventory_artifact_id', $artifactId)
                    ->orWhere('graph_snapshot_artifact_id', $artifactId);
            })
            ->pluck('id');

        if ($snapshotIds->isEmpty()) {
            return false;
        }

        return DB::table('local_workspaces')->whereIn('last_snapshot_id', $snapshotIds)->exists()
            || DB::table('genesis_imports')->where('status', 'active')->whereIn('snapshot_id', $snapshotIds)->exists()
            || DB::table('delta_syncs')->where('status', 'active')->whereIn('new_snapshot_id', $snapshotIds)->exists();
    }

    private function deleteArtifactContents(string $storagePath): void
    {
        Storage::disk('local')->delete($storagePath);

        if (Str::endsWith($storagePath, '/artifact')) {
            Storage::disk('local')->deleteDirectory(Str::beforeLast($storagePath, '/artifact').'/chunks');
        }
    }

    /**
     * @return Collection<int, object>
     */
    private function incompleteUploadCandidates(Carbon $cutoff, ?int $limit): Collection
    {
        return DB::table('artifacts')
            ->where('status', 'uploading')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();
    }

    private function parentTransferIsStale(string $storagePath, Carbon $cutoff): bool
    {
        if (! preg_match('#^devboard/artifacts/(genesis|delta)/([^/]+)/[^/]+/artifact$#', $storagePath, $matches)) {
            return true;
        }

        $table = $matches[1] === 'genesis' ? 'genesis_imports' : 'delta_syncs';

        return ! DB::table($table)->where('id', $matches[2])->where('updated_at', '>', $cutoff)->exists();
    }

    private function deleteArtifactDirectory(string $storagePath): void
    {
        if (Str::endsWith($storagePath, '/artifact')) {
            Storage::disk('local')->deleteDirectory(Str::beforeLast($storagePath, '/artifact'));

            return;
        }

        $this->deleteArtifactContents($storagePath);
    }

    private function auditIncompleteUploadPurge(object $artifact, int $ttlHours): void
    {
        app(AuditLogger::class)->record('artifact.purged', 'artifact', $artifact->id, [
            'reason' => 'incomplete_upload_expired',
            'incomplete_upload_ttl_hours' => $ttlHours,
            'artifact_type' => $artifact->artifact_type,
            'previous_status' => $artifact->status,
            'size_bytes' => (int) $artifact->size_bytes,
        ]);
    }

    private function auditPurge(object $artifact, int $days): void
    {
        app(AuditLogger::class)->record('artifact.purged', 'artifact', $artifact->id, [
            'retention_days' => $days,
            'artifact_type' => $artifact->artifact_type,
            'previous_status' => $artifact->status,
            'storage_path' => $artifact->storage_path,
        ]);
    }
}
