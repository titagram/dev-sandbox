<?php

namespace App\Jobs;

use App\Contracts\EmbeddingGenerator;
use App\Services\Search\EmbeddingIndexService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateSearchDocumentEmbedding implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $sourceTable,
        public readonly string $sourceId,
        public readonly string $expectedChecksum,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(EmbeddingGenerator $generator, ?EmbeddingIndexService $index = null): void
    {
        $index ??= app(EmbeddingIndexService::class);

        if (! (bool) config('devboard.embeddings.enabled', false)) {
            return;
        }

        $document = DB::table('hades_search_documents')
            ->where('source_table', $this->sourceTable)
            ->where('source_id', $this->sourceId)
            ->first();

        if (! $document || (string) $document->checksum !== $this->expectedChecksum) {
            return;
        }

        try {
            $embedding = $generator->generate(trim((string) $document->title."\n".(string) $document->body));
            $index->indexDocument($this->sourceTable, $this->sourceId, $embedding, $this->expectedChecksum);
        } catch (Throwable $exception) {
            $this->markDegraded($exception->getMessage());
        }
    }

    private function markDegraded(string $reason): void
    {
        $now = now();
        $metadata = $this->metadataWithEmbedding([
            'status' => 'degraded',
            'model' => (string) config('devboard.embeddings.model'),
            'dimensions' => (int) config('devboard.embeddings.dimensions', 1536),
            'checksum' => $this->expectedChecksum,
            'updated_at' => $now->toJSON(),
            'error' => substr($reason, 0, 255),
        ]);

        DB::table('hades_search_documents')
            ->where('source_table', $this->sourceTable)
            ->where('source_id', $this->sourceId)
            ->where('checksum', $this->expectedChecksum)
            ->update([
                'embedding_status' => 'degraded',
                'embedding_model' => (string) config('devboard.embeddings.model'),
                'embedding_dimensions' => (int) config('devboard.embeddings.dimensions', 1536),
                'embedding_checksum' => $this->expectedChecksum,
                'embedding_updated_at' => $now,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);
    }

    /**
     * @param  array<string, mixed>  $embedding
     * @return array<string, mixed>
     */
    private function metadataWithEmbedding(array $embedding): array
    {
        $metadata = DB::table('hades_search_documents')
            ->where('source_table', $this->sourceTable)
            ->where('source_id', $this->sourceId)
            ->value('metadata');

        $decoded = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        $decoded = is_array($decoded) ? $decoded : [];
        $decoded['embedding'] = $embedding;

        return $decoded;
    }
}
