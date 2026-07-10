<?php

namespace App\Console\Commands\Hades;

use App\Contracts\EmbeddingGenerator;
use App\Jobs\GenerateSearchDocumentEmbedding;
use App\Services\Hades\HadesSearchDocumentIndexer;
use App\Services\Search\EmbeddingIndexService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class ReindexSearchDocumentsCommand extends Command
{
    protected $signature = 'hades:search-documents-reindex
        {--project= : Restrict to a project id}
        {--workspace-binding= : Restrict workspace-scoped Hades records to one binding id}
        {--domain=* : Restrict domains: memory,wiki,artifacts,bug_evidence,source_slices,evidence_packs}
        {--limit=5000 : Maximum rows to scan per domain}
        {--dry-run : Count rows without writing search documents}
        {--embeddings : Generate missing/stale embeddings after rebuilding search documents}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Backfill Hades materialized search documents from existing memory, wiki, artifact, evidence, source slice, and evidence pack records.';

    public function __construct(
        private readonly HadesSearchDocumentIndexer $indexer,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly EmbeddingIndexService $embeddingIndex,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 100000) {
            $this->error('Invalid --limit value. Use an integer from 1 to 100000.');

            return SymfonyCommand::FAILURE;
        }

        $domains = $this->domains();
        if ($domains === []) {
            return SymfonyCommand::FAILURE;
        }

        $projectId = $this->option('project') ?: null;
        $workspaceBindingId = $this->option('workspace-binding') ?: null;
        $dryRun = (bool) $this->option('dry-run');
        $embeddings = (bool) $this->option('embeddings') && ! $dryRun;
        $result = [
            'schema' => 'hades.search_documents_reindex.v1',
            'project_id' => $projectId,
            'workspace_binding_id' => $workspaceBindingId,
            'dry_run' => $dryRun,
            'limit' => $limit,
            'domains' => [],
            'scanned' => 0,
            'indexed' => 0,
            'embeddings' => 0,
        ];

        foreach ($domains as $domain) {
            $method = 'reindex'.str_replace(' ', '', ucwords(str_replace('_', ' ', $domain)));
            $stats = $this->{$method}($projectId, $workspaceBindingId, $limit, $dryRun, $embeddings);
            $result['domains'][$domain] = $stats;
            $result['scanned'] += $stats['scanned'];
            $result['indexed'] += $stats['indexed'];
            $result['embeddings'] += $stats['embeddings'];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return SymfonyCommand::SUCCESS;
        }

        $this->info("Scanned {$result['scanned']} source record(s).");
        $verb = $dryRun ? 'Would index' : 'Indexed';
        $this->info("{$verb} {$result['indexed']} search document(s).");
        if ($embeddings) {
            $this->info("Backfilled {$result['embeddings']} embedding(s).");
        }
        foreach ($result['domains'] as $domain => $stats) {
            $this->line("- {$domain}: scanned {$stats['scanned']}, indexed {$stats['indexed']}, embeddings {$stats['embeddings']}");
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function domains(): array
    {
        $requested = array_values(array_filter(array_map('strval', (array) $this->option('domain'))));
        $domains = ['memory', 'wiki', 'artifacts', 'bug_evidence', 'source_slices', 'evidence_packs'];

        if ($requested === []) {
            return $domains;
        }

        $unknown = array_values(array_diff($requested, $domains));
        if ($unknown !== []) {
            $this->error('Unknown domain(s): '.implode(', ', $unknown));

            return [];
        }

        return $requested;
    }

    /**
     * @return array{scanned:int,indexed:int,embeddings:int}
     */
    private function reindexMemory(?string $projectId, ?string $workspaceBindingId, int $limit, bool $dryRun, bool $embeddings): array
    {
        $rows = DB::table('project_memory_entries')
            ->when($projectId !== null, fn ($builder) => $builder->where('project_id', $projectId))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->indexRows($rows, $dryRun, fn (object $row) => $this->indexer->indexMemoryEntry($row), $embeddings, fn (object $row): array => ['project_memory_entries', (string) $row->id]);
    }

    /**
     * @return array{scanned:int,indexed:int,embeddings:int}
     */
    private function reindexWiki(?string $projectId, ?string $workspaceBindingId, int $limit, bool $dryRun, bool $embeddings): array
    {
        $rows = DB::table('wiki_pages')
            ->join('wiki_revisions', 'wiki_revisions.id', '=', 'wiki_pages.current_revision_id')
            ->when($projectId !== null, fn ($builder) => $builder->where('wiki_pages.project_id', $projectId))
            ->select([
                'wiki_pages.id as page_id',
                'wiki_pages.project_id',
                'wiki_pages.repository_id',
                'wiki_pages.slug',
                'wiki_pages.title',
                'wiki_pages.page_type',
                'wiki_pages.source_status as page_source_status',
                'wiki_pages.created_at as page_created_at',
                'wiki_pages.updated_at as page_updated_at',
                'wiki_revisions.id as revision_id',
                'wiki_revisions.author_user_id',
                'wiki_revisions.author_device_id',
                'wiki_revisions.producer',
                'wiki_revisions.source_type',
                'wiki_revisions.source_status as revision_source_status',
                'wiki_revisions.content_markdown',
                'wiki_revisions.evidence_refs',
                'wiki_revisions.created_at as revision_created_at',
            ])
            ->orderByDesc('wiki_pages.updated_at')
            ->orderByDesc('wiki_revisions.created_at')
            ->limit($limit)
            ->get();

        return $this->indexRows($rows, $dryRun, function (object $row): void {
            $page = (object) [
                'id' => $row->page_id,
                'project_id' => $row->project_id,
                'repository_id' => $row->repository_id,
                'slug' => $row->slug,
                'title' => $row->title,
                'page_type' => $row->page_type,
                'source_status' => $row->page_source_status,
                'created_at' => $row->page_created_at,
                'updated_at' => $row->page_updated_at,
            ];
            $revision = (object) [
                'id' => $row->revision_id,
                'wiki_page_id' => $row->page_id,
                'author_user_id' => $row->author_user_id,
                'author_device_id' => $row->author_device_id,
                'producer' => $row->producer,
                'source_type' => $row->source_type,
                'source_status' => $row->revision_source_status,
                'content_markdown' => $row->content_markdown,
                'evidence_refs' => $row->evidence_refs,
                'created_at' => $row->revision_created_at,
            ];
            $this->indexer->indexWikiRevision($page, $revision);
        }, $embeddings, fn (object $row): array => ['wiki_revisions', (string) $row->revision_id]);
    }

    /**
     * @return array{scanned:int,indexed:int,embeddings:int}
     */
    private function reindexArtifacts(?string $projectId, ?string $workspaceBindingId, int $limit, bool $dryRun, bool $embeddings): array
    {
        $rows = DB::table('hades_agent_artifacts')
            ->when($projectId !== null, fn ($builder) => $builder->where('project_id', $projectId))
            ->when($workspaceBindingId !== null, fn ($builder) => $builder->where('workspace_binding_id', $workspaceBindingId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->indexRows($rows, $dryRun, function (object $row): void {
            $artifact = json_decode((string) $row->artifact, true);
            $artifact = is_array($artifact) ? $artifact : [];
            $artifactJson = json_encode($artifact, JSON_THROW_ON_ERROR);
            $this->indexer->indexArtifact($row, $artifact, $artifactJson);
        }, $embeddings, fn (object $row): array => ['hades_agent_artifacts', (string) $row->id]);
    }

    /**
     * @return array{scanned:int,indexed:int,embeddings:int}
     */
    private function reindexBugEvidence(?string $projectId, ?string $workspaceBindingId, int $limit, bool $dryRun, bool $embeddings): array
    {
        $rows = DB::table('hades_bug_evidence_items')
            ->when($projectId !== null, fn ($builder) => $builder->where('project_id', $projectId))
            ->when($workspaceBindingId !== null, fn ($builder) => $builder->where('workspace_binding_id', $workspaceBindingId))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->indexRows($rows, $dryRun, fn (object $row) => $this->indexer->indexBugEvidence($row), $embeddings, fn (object $row): array => ['hades_bug_evidence_items', (string) $row->id]);
    }

    /**
     * @return array{scanned:int,indexed:int,embeddings:int}
     */
    private function reindexSourceSlices(?string $projectId, ?string $workspaceBindingId, int $limit, bool $dryRun, bool $embeddings): array
    {
        $rows = DB::table('hades_source_slices')
            ->when($projectId !== null, fn ($builder) => $builder->where('project_id', $projectId))
            ->when($workspaceBindingId !== null, fn ($builder) => $builder->where('workspace_binding_id', $workspaceBindingId))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->indexRows($rows, $dryRun, fn (object $row) => $this->indexer->indexSourceSlice($row), $embeddings, fn (object $row): array => ['hades_source_slices', (string) $row->id]);
    }

    /**
     * @return array{scanned:int,indexed:int,embeddings:int}
     */
    private function reindexEvidencePacks(?string $projectId, ?string $workspaceBindingId, int $limit, bool $dryRun, bool $embeddings): array
    {
        $rows = DB::table('hades_evidence_packs')
            ->when($projectId !== null, fn ($builder) => $builder->where('project_id', $projectId))
            ->when($workspaceBindingId !== null, fn ($builder) => $builder->where('workspace_binding_id', $workspaceBindingId))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->indexRows($rows, $dryRun, fn (object $row) => $this->indexer->indexEvidencePack($row), $embeddings, fn (object $row): array => ['hades_evidence_packs', (string) $row->id]);
    }

    /**
     * @param  iterable<object>  $rows
     * @return array{scanned:int,indexed:int,embeddings:int}
     */
    private function indexRows(iterable $rows, bool $dryRun, callable $callback, bool $embeddings, callable $source): array
    {
        $scanned = 0;
        $indexed = 0;
        $embedded = 0;

        foreach ($rows as $row) {
            $scanned++;
            if (! $dryRun) {
                $callback($row);
                [$sourceTable, $sourceId] = $source($row);
                if ($embeddings && $this->backfillEmbedding($sourceTable, $sourceId)) {
                    $embedded++;
                }
            }
            $indexed++;
        }

        return ['scanned' => $scanned, 'indexed' => $indexed, 'embeddings' => $embedded];
    }

    private function backfillEmbedding(string $sourceTable, string $sourceId): bool
    {
        $checksum = DB::table('hades_search_documents')
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId)
            ->value('checksum');

        if (! is_string($checksum) || $checksum === '') {
            return false;
        }

        (new GenerateSearchDocumentEmbedding($sourceTable, $sourceId, $checksum))->handle($this->embeddingGenerator, $this->embeddingIndex);

        return DB::table('hades_search_documents')
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId)
            ->where('embedding_status', 'ready')
            ->exists();
    }
}
