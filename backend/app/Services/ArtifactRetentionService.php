<?php

namespace App\Services;

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
                DB::table('artifacts')->where('id', $artifact->id)->update([
                    'status' => 'purged',
                    'updated_at' => now(),
                ]);
                $this->auditPurge($artifact, $days);
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

    private function auditPurge(object $artifact, int $days): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => null,
            'actor_device_id' => null,
            'actor_type' => 'system',
            'action' => 'artifact.purged',
            'target_type' => 'artifact',
            'target_id' => $artifact->id,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => json_encode([
                'retention_days' => $days,
                'artifact_type' => $artifact->artifact_type,
                'previous_status' => $artifact->status,
                'storage_path' => $artifact->storage_path,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
