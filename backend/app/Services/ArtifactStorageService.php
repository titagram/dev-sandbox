<?php

namespace App\Services;

use App\Support\DevBoardUlid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ArtifactStorageService
{
    public function chunkPath(string $importId, string $artifactId, int $chunkIndex, string $scope = 'genesis'): string
    {
        DevBoardUlid::assertStrict($artifactId, 'artifact_id');

        return "devboard/artifacts/{$scope}/{$importId}/{$artifactId}/chunks/{$chunkIndex}";
    }

    public function artifactPath(string $importId, string $artifactId, string $scope = 'genesis'): string
    {
        DevBoardUlid::assertStrict($artifactId, 'artifact_id');

        return "devboard/artifacts/{$scope}/{$importId}/{$artifactId}/artifact";
    }

    public function storeChunk(string $importId, string $artifactId, int $chunkIndex, string $content, string $hash, string $scope = 'genesis'): string
    {
        return DB::transaction(function () use ($importId, $artifactId, $chunkIndex, $content, $hash, $scope): string {
            $artifact = DB::table('artifacts')->where('id', $artifactId)->lockForUpdate()->first();
            if (! $artifact) {
                throw new ArtifactStorageException('schema_validation_failed', 'Artifact was not found.');
            }

            $metadata = json_decode($artifact->metadata, true, 512, JSON_THROW_ON_ERROR);
            $chunkCount = (int) ($metadata['chunk_count'] ?? 0);
            $sizeBytes = (int) $artifact->size_bytes;

            if ($chunkIndex < 0 || $chunkIndex >= $chunkCount) {
                throw new ArtifactStorageException('artifact_chunk_out_of_range', 'Chunk index is outside the declared 0..chunk_count-1 range.');
            }

            $path = $this->chunkPath($importId, $artifactId, $chunkIndex, $scope);
            $disk = Storage::disk('local');

            if ($disk->exists($path)) {
                $existingHash = hash('sha256', $disk->get($path));
                if (! hash_equals($existingHash, $this->normalizeHash($hash))) {
                    throw new ArtifactStorageException('artifact_finalize_conflict', 'Chunk index was already uploaded with a different hash.');
                }

                return $path;
            }

            if (! hash_equals(hash('sha256', $content), $this->normalizeHash($hash))) {
                throw new ArtifactStorageException('artifact_hash_mismatch', 'Uploaded chunk hash does not match the chunk content.');
            }

            // Recheck max_chunk_bytes as defense in depth even though the controller enforces it.
            if (strlen($content) > (int) config('devboard.artifacts.max_chunk_bytes')) {
                throw new ArtifactStorageException('artifact_chunk_too_large', 'Chunk body exceeds max_chunk_bytes.');
            }

            // Sum sizes only for existing expected paths from index 0 through chunk_count - 1.
            $existingExpectedBytes = 0;
            for ($i = 0; $i < $chunkCount; $i++) {
                if ($i === $chunkIndex) {
                    continue;
                }
                $existingPath = $this->chunkPath($importId, $artifactId, $i, $scope);
                if ($disk->exists($existingPath)) {
                    $existingExpectedBytes += $disk->size($existingPath);
                }
            }

            $incomingBytes = strlen($content);
            if ($existingExpectedBytes + $incomingBytes > $sizeBytes) {
                throw new ArtifactStorageException('artifact_size_mismatch', 'Uploaded chunk bytes exceed the declared artifact size_bytes.');
            }

            $disk->put($path, $content);

            return $path;
        });
    }

    public function assembleArtifact(object $artifact, string $importId, string $scope = 'genesis'): string
    {
        $metadata = json_decode($artifact->metadata, true, 512, JSON_THROW_ON_ERROR);
        $chunkCount = (int) ($metadata['chunk_count'] ?? 0);
        $declaredSize = (int) $artifact->size_bytes;

        $disk = Storage::disk('local');
        $targetPath = $disk->path($artifact->storage_path);
        $targetDir = dirname($targetPath);

        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $target = fopen($targetPath, 'wb');

        try {
            $context = hash_init('sha256');
            $actualSize = 0;

            for ($index = 0; $index < $chunkCount; $index++) {
                $chunkPath = $this->chunkPath($importId, $artifact->id, $index, $scope);

                if (! $disk->exists($chunkPath)) {
                    throw new ArtifactStorageException('artifact_chunk_missing', 'Artifact upload is missing one or more chunks.');
                }

                $chunkContent = $disk->get($chunkPath);
                hash_update($context, $chunkContent);
                fwrite($target, $chunkContent);
                $actualSize += strlen($chunkContent);

                if ($actualSize > $declaredSize) {
                    throw new ArtifactStorageException('artifact_size_mismatch', 'Assembled artifact exceeds the declared size_bytes.');
                }
            }

            $actualHash = hash_final($context);

            if ($actualSize !== $declaredSize) {
                throw new ArtifactStorageException('artifact_size_mismatch', 'Assembled artifact size does not match the declared size_bytes exactly.');
            }

            if (! hash_equals($artifact->sha256, $actualHash)) {
                throw new ArtifactStorageException('artifact_hash_mismatch', 'Uploaded artifact hash does not match manifest.');
            }

            fclose($target);

            DB::table('artifacts')->where('id', $artifact->id)->update([
                'status' => 'validated',
                'updated_at' => now(),
            ]);

            app(AuditLogger::class)->record('artifact.uploaded', 'artifact', $artifact->id, [
                'artifact_type' => $artifact->artifact_type,
                'size_bytes' => $artifact->size_bytes,
                'sha256' => $artifact->sha256,
            ], ['type' => 'plugin']);

            return $artifact->storage_path;
        } catch (\Throwable $e) {
            if (is_resource($target)) {
                fclose($target);
            }
            @unlink($targetPath);

            app(AuditLogger::class)->record('artifact.rejected', 'artifact', $artifact->id, [
                'artifact_type' => $artifact->artifact_type,
                'reason' => $e->getMessage(),
            ], ['type' => 'plugin']);

            throw $e;
        }
    }

    public function artifactContents(object $artifact): string
    {
        return Storage::disk('local')->get($artifact->storage_path);
    }

    private function normalizeHash(string $hash): string
    {
        return str_starts_with($hash, 'sha256:') ? substr($hash, 7) : $hash;
    }
}
