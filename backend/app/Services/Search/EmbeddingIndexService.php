<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;

class EmbeddingIndexService
{
    /**
     * Store a vector embedding for a hades_search_documents entry.
     *
     * @param  array<int, float>  $embedding  Flat array of 1536 float values.
     */
    public function indexDocument(string $sourceTable, string $sourceId, array $embedding): void
    {
        if (! $this->supportsVectorSearch()) {
            return;
        }

        $vector = '[' . implode(',', array_map('strval', $embedding)) . ']';

        DB::table('hades_search_documents')
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId)
            ->update(['embedding' => DB::raw("'{$vector}'::vector")]);
    }

    /**
     * Find similar documents by cosine similarity.
     *
     * @param  array<int, float>  $queryEmbedding  Flat array of 1536 float values.
     * @param  list<string>       $domains         Optional domain filter.
     * @return list<array{source_id: string, source_table: string, similarity: float, evidence_refs: list<mixed>, needs_verification: bool}>
     */
    public function searchSimilar(string $projectId, array $queryEmbedding, int $limit, ?string $workspaceBindingId = null, array $domains = []): array
    {
        if (! $this->supportsVectorSearch()) {
            return [];
        }

        $vector = '[' . implode(',', array_map('strval', $queryEmbedding)) . ']';

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
            ->select([
                'source_table',
                'source_id',
                'metadata',
                DB::raw("1 - (embedding <=> '{$vector}'::vector) AS similarity"),
            ])
            ->orderByRaw("embedding <=> '{$vector}'::vector ASC")
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
        return $this->supportsVectorSearch();
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
