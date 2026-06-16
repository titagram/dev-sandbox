<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ArtifactStorageService
{
    public function chunkPath(string $importId, string $artifactId, int $chunkIndex): string
    {
        return "devboard/artifacts/genesis/{$importId}/{$artifactId}/chunks/{$chunkIndex}";
    }

    public function artifactPath(string $importId, string $artifactId): string
    {
        return "devboard/artifacts/genesis/{$importId}/{$artifactId}/artifact";
    }

    public function storeChunk(string $importId, string $artifactId, int $chunkIndex, string $content, string $hash): string
    {
        $path = $this->chunkPath($importId, $artifactId, $chunkIndex);

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

    public function assembleArtifact(object $artifact, string $importId): string
    {
        $metadata = json_decode($artifact->metadata, true, 512, JSON_THROW_ON_ERROR);
        $chunkCount = (int) ($metadata['chunk_count'] ?? 0);
        $content = '';

        for ($index = 0; $index < $chunkCount; $index++) {
            $chunkPath = $this->chunkPath($importId, $artifact->id, $index);

            if (! Storage::disk('local')->exists($chunkPath)) {
                throw new ArtifactStorageException('artifact_chunk_missing', 'Artifact upload is missing one or more chunks.');
            }

            $content .= Storage::disk('local')->get($chunkPath);
        }

        if (! hash_equals($artifact->sha256, hash('sha256', $content))) {
            throw new ArtifactStorageException('artifact_hash_mismatch', 'Uploaded artifact hash does not match manifest.');
        }

        Storage::disk('local')->put($artifact->storage_path, $content);
        DB::table('artifacts')->where('id', $artifact->id)->update([
            'status' => 'validated',
            'updated_at' => now(),
        ]);

        return $artifact->storage_path;
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
