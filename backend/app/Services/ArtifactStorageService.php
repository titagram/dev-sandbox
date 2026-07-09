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
        $path = $this->chunkPath($importId, $artifactId, $chunkIndex, $scope);

        if (Storage::disk('local')->exists($path)) {
            $existingHash = hash('sha256', Storage::disk('local')->get($path));
            if (! hash_equals($existingHash, $this->normalizeHash($hash))) {
                throw new ArtifactStorageException('artifact_finalize_conflict', 'Chunk index was already uploaded with a different hash.');
            }

            return $path;
        }

        if (! hash_equals(hash('sha256', $content), $this->normalizeHash($hash))) {
            throw new ArtifactStorageException('artifact_hash_mismatch', 'Uploaded chunk hash does not match the chunk content.');
        }

        Storage::disk('local')->put($path, $content);

        return $path;
    }

    public function assembleArtifact(object $artifact, string $importId, string $scope = 'genesis'): string
    {
        $metadata = json_decode($artifact->metadata, true, 512, JSON_THROW_ON_ERROR);
        $chunkCount = (int) ($metadata['chunk_count'] ?? 0);

        $disk = Storage::disk('local');
        $targetPath = $disk->path($artifact->storage_path);
        $targetDir = dirname($targetPath);

        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $target = fopen($targetPath, 'wb');

        try {
            $context = hash_init('sha256');

            for ($index = 0; $index < $chunkCount; $index++) {
                $chunkPath = $this->chunkPath($importId, $artifact->id, $index, $scope);

                if (! $disk->exists($chunkPath)) {
                    throw new ArtifactStorageException('artifact_chunk_missing', 'Artifact upload is missing one or more chunks.');
                }

                $chunkContent = $disk->get($chunkPath);
                hash_update($context, $chunkContent);
                fwrite($target, $chunkContent);
            }

            fclose($target);
            $actualHash = hash_final($context);

            if (! hash_equals($artifact->sha256, $actualHash)) {
                throw new ArtifactStorageException('artifact_hash_mismatch', 'Uploaded artifact hash does not match manifest.');
            }

            DB::table('artifacts')->where('id', $artifact->id)->update([
                'status' => 'validated',
                'updated_at' => now(),
            ]);

            return $artifact->storage_path;
        } catch (\Throwable $e) {
            if (is_resource($target)) {
                fclose($target);
            }
            @unlink($targetPath);

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
