<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EmbeddingIndexService
{
    /**
     * Store a vector embedding for a hades_search_documents entry.
     *
     * @param  array<int, mixed>  $embedding  Flat array of configured float values.
     */
    public function indexDocument(string $sourceTable, string $sourceId, array $embedding, ?string $expectedChecksum = null): void
    {
        $validated = $this->validateEmbedding($embedding);
        $now = now();
        $model = (string) config('devboard.embeddings.model');
        $dimensions = (int) config('devboard.embeddings.dimensions', 1536);

        $document = DB::table('hades_search_documents')
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId)
            ->first();

        if (! $document || ($expectedChecksum !== null && (string) $document->checksum !== $expectedChecksum)) {
            return;
        }

        $metadata = is_string($document->metadata) ? json_decode($document->metadata, true) : $document->metadata;
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['embedding'] = [
            'status' => 'ready',
            'model' => $model,
            'dimensions' => $dimensions,
            'checksum' => (string) $document->checksum,
            'updated_at' => $now->toJSON(),
        ];

        $updates = [
            'embedding_status' => 'ready',
            'embedding_model' => $model,
            'embedding_dimensions' => $dimensions,
            'embedding_checksum' => (string) $document->checksum,
            'embedding_updated_at' => $now,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ];

        $query = DB::table('hades_search_documents')
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId);

        if ($expectedChecksum !== null) {
            $query->where('checksum', $expectedChecksum);
        }

        if (! $this->supportsVectorSearch()) {
            $query->update($updates);

            return;
        }

        $vector = '['.implode(',', array_map(static fn (float $value): string => (string) $value, $validated)).']';

        DB::update(
            'UPDATE hades_search_documents SET embedding = ?::vector, embedding_status = ?, embedding_model = ?, embedding_dimensions = ?, embedding_checksum = ?, embedding_updated_at = ?, metadata = ?, updated_at = ? WHERE source_table = ? AND source_id = ?'.($expectedChecksum !== null ? ' AND checksum = ?' : ''),
            array_values(array_filter([
                $vector,
                $updates['embedding_status'],
                $updates['embedding_model'],
                $updates['embedding_dimensions'],
                $updates['embedding_checksum'],
                $updates['embedding_updated_at'],
                $updates['metadata'],
                $updates['updated_at'],
                $sourceTable,
                $sourceId,
                $expectedChecksum,
            ], static fn (mixed $value): bool => $value !== null)),
        );
    }

    /**
     * Find similar documents by cosine similarity.
     *
     * @param  array<int, float>  $queryEmbedding  Flat array of 1536 float values.
     * @param  list<string>  $domains  Optional domain filter.
     * @return list<array{source_id: string, source_table: string, similarity: float, evidence_refs: list<mixed>, needs_verification: bool}>
     */
    public function searchSimilar(string $projectId, array $queryEmbedding, int $limit, ?string $workspaceBindingId = null, array $domains = []): array
    {
        if (! $this->supportsVectorSearch()) {
            return [];
        }

        $validated = $this->validateEmbedding($queryEmbedding);
        $vector = '['.implode(',', array_map(static fn (float $value): string => (string) $value, $validated)).']';

        $query = DB::table('hades_search_documents')
            ->where('project_id', $projectId)
            ->whereNotNull('embedding')
            ->when($workspaceBindingId !== null, function ($builder) use ($workspaceBindingId): void {
                $builder->where(function ($nested) use ($workspaceBindingId): void {
                    $nested->whereNull('workspace_binding_id')->orWhere('workspace_binding_id', $workspaceBindingId);
                });
            })
            ->when($domains !== [], function ($builder) use ($domains): void {
                $builder->whereIn('domain', $domains);
            })
            ->select(['source_table', 'source_id', 'metadata'])
            ->selectRaw('1 - (embedding <=> ?::vector) AS similarity', [$vector])
            ->orderByRaw('embedding <=> ?::vector ASC', [$vector])
            ->limit(max(10, $limit * 2))
            ->get();

        $results = [];

        foreach ($query as $row) {
            $evidenceRefs = $this->extractEvidenceRefs($row->source_table, $row->source_id, $row->metadata);
            $results[] = [
                'source_id' => (string) $row->source_id,
                'source_table' => (string) $row->source_table,
                'similarity' => (float) $row->similarity,
                'evidence_refs' => $evidenceRefs,
                'needs_verification' => $evidenceRefs === [],
            ];
        }

        return $results;
    }

    /**
     * Check whether the database supports vector operations.
     */
    public function supportsVectorSearch(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Check whether embedding storage and retrieval is fully operational.
     */
    public function supportsEmbeddings(): bool
    {
        if (! (bool) config('devboard.embeddings.enabled', false)) {
            return false;
        }

        if (trim((string) config('devboard.embeddings.provider')) === '' || trim((string) config('devboard.embeddings.model')) === '') {
            return false;
        }

        if ((int) config('devboard.embeddings.dimensions', 0) < 1) {
            return false;
        }

        if (! $this->supportsVectorSearch()) {
            return false;
        }

        try {
            $hasVector = (bool) DB::table('pg_extension')->where('extname', 'vector')->exists();
            $columns = DB::table('information_schema.columns')
                ->where('table_name', 'hades_search_documents')
                ->whereIn('column_name', ['embedding', 'embedding_status', 'embedding_model', 'embedding_dimensions', 'embedding_checksum', 'embedding_updated_at'])
                ->count();

            return $hasVector && $columns === 6;
        } catch (\Throwable) {
            return false;
        }
    }

    public function vectorModel(): ?string
    {
        $model = trim((string) config('devboard.embeddings.model', ''));

        return $model !== '' ? $model : null;
    }

    /**
     * @param  array<int, mixed>  $embedding
     * @return list<float>
     */
    private function validateEmbedding(array $embedding): array
    {
        $dimensions = (int) config('devboard.embeddings.dimensions', 1536);

        if (count($embedding) !== $dimensions) {
            throw new InvalidArgumentException('Embedding dimensions did not match configured dimensions.');
        }

        $validated = [];
        foreach ($embedding as $value) {
            if (! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException('Embedding values must be numeric floats.');
            }

            $float = (float) $value;
            if (! is_finite($float)) {
                throw new InvalidArgumentException('Embedding values must be finite.');
            }

            $validated[] = $float;
        }

        return $validated;
    }

    /**
     * Extract evidence refs for a given source record (usable without vector search).
     *
     * @return list<mixed>
     */
    public function extractEvidenceRefsForSource(string $sourceTable, string $sourceId): array
    {
        return $this->extractEvidenceRefs($sourceTable, $sourceId, null);
    }

    /**
     * Extract evidence refs from the source record.
     *
     * @return list<mixed>
     */
    private function extractEvidenceRefs(string $sourceTable, string $sourceId, mixed $metadata): array
    {
        $references = match ($sourceTable) {
            'wiki_revisions' => $this->wikiEvidenceRefs($sourceId),
            'hades_evidence_packs' => $this->tableEvidenceRefs('hades_evidence_packs', $sourceId),
            'hades_causal_packs' => $this->tableEvidenceRefs('hades_causal_packs', $sourceId),
            default => [],
        };

        if ($references === [] && is_string($metadata)) {
            $decoded = json_decode($metadata, true);

            if (is_array($decoded) && isset($decoded['evidence_refs']) && is_array($decoded['evidence_refs'])) {
                return $decoded['evidence_refs'];
            }
        }

        return $references;
    }

    /**
     * @return list<mixed>
     */
    private function wikiEvidenceRefs(string $sourceId): array
    {
        try {
            $row = DB::table('wiki_revisions')
                ->where('id', $sourceId)
                ->value('evidence_refs');
        } catch (\Throwable) {
            return [];
        }

        return $this->decodeList($row);
    }

    /**
     * @return list<mixed>
     */
    private function tableEvidenceRefs(string $table, string $sourceId): array
    {
        try {
            $row = DB::table($table)
                ->where('id', $sourceId)
                ->value('evidence_refs');
        } catch (\Throwable) {
            return [];
        }

        return $this->decodeList($row);
    }

    /**
     * @return list<mixed>
     */
    private function decodeList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }
}
