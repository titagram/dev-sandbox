<?php

namespace App\Services;

use App\Enums\SourceStatus;
use App\Services\Hades\HadesSearchDocumentIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WikiRefreshResultService
{
    public function __construct(
        private readonly WikiRevisionService $revisions,
        private readonly HadesSearchDocumentIndexer $searchIndexer,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function apply(object $job, array $result): array
    {
        if (($result['schema'] ?? null) !== 'devboard.wiki_refresh_result.v1') {
            throw new WikiRevisionException('schema_validation_failed', 'Wiki refresh results must use schema devboard.wiki_refresh_result.v1.');
        }

        $pages = $result['pages'] ?? $result['wiki_revisions'] ?? $result['revisions'] ?? [];
        if (! is_array($pages)) {
            throw new WikiRevisionException('schema_validation_failed', 'Wiki refresh result pages must be an array.');
        }

        $auditActor = $this->auditActor($job);
        $written = [];
        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            $repositoryId = $page['repository_id'] ?? ($job->repository_id ?? null);
            if ($repositoryId !== null && ! $this->repositoryBelongsToProject((string) $repositoryId, (string) $job->project_id)) {
                throw new WikiRevisionException('repository_mismatch', 'Wiki refresh result repository does not belong to the job project.');
            }

            foreach (['slug', 'title', 'content_markdown'] as $field) {
                if (! isset($page[$field]) || ! is_string($page[$field]) || trim($page[$field]) === '') {
                    throw new WikiRevisionException('schema_validation_failed', "Wiki refresh result page is missing {$field}.");
                }
            }

            $evidenceRefs = $this->evidenceRefs($page);

            $payload = [
                'project_id' => (string) $job->project_id,
                'repository_id' => $repositoryId ? (string) $repositoryId : null,
                'slug' => (string) $page['slug'],
                'title' => (string) $page['title'],
                'page_type' => (string) ($page['page_type'] ?? 'technical'),
                'producer' => 'hades',
                'source_type' => 'hades_wiki_refresh',
                'source_status' => SourceStatus::NeedsVerification->value,
                'content_markdown' => (string) $page['content_markdown'],
                'evidence_refs' => $evidenceRefs,
            ];

            $written[] = [
                ...$this->revisions->write($payload, null, null, $auditActor),
                'slug' => $payload['slug'],
                'title' => $payload['title'],
            ];
        }

        $memoryEntry = $this->recordMemorySummary($job, $written, $result);

        return [
            'schema' => 'devboard.wiki_refresh_apply.v1',
            'pages_written' => count($written),
            'pages' => $written,
            'memory_entry' => $memoryEntry,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $written
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function recordMemorySummary(object $job, array $written, array $result): ?array
    {
        if ($written === []) {
            return null;
        }

        $existing = DB::table('project_memory_entries')
            ->where('project_id', $job->project_id)
            ->where('source', 'hades_wiki_refresh')
            ->where('kind', 'project_awareness')
            ->where('payload->job_id', (string) $job->id)
            ->first();

        if ($existing) {
            return [
                'id' => (string) $existing->id,
                'created' => false,
            ];
        }

        $now = now();
        $entryId = (string) Str::ulid();
        $titles = array_values(array_filter(array_map(
            fn (array $page): string => (string) ($page['title'] ?? ''),
            $written,
        )));
        $summary = 'Hades project awareness wiki refreshed: '.count($written).' page(s) written';
        if ($titles !== []) {
            $summary .= ' - '.implode(', ', array_slice($titles, 0, 5));
        }

        $payload = [
            'schema' => 'hades.project_awareness_memory.v1',
            'job_id' => (string) $job->id,
            'workspace_binding_id' => (string) $job->workspace_binding_id,
            'wiki_pages' => array_map(
                fn (array $page): array => [
                    'wiki_page_id' => (string) ($page['wiki_page_id'] ?? ''),
                    'wiki_revision_id' => (string) ($page['wiki_revision_id'] ?? ''),
                    'slug' => (string) ($page['slug'] ?? ''),
                    'title' => (string) ($page['title'] ?? ''),
                    'source_status' => (string) ($page['source_status'] ?? ''),
                ],
                $written,
            ),
            'source_artifacts' => $result['source_artifacts'] ?? [],
            'raw_source_included' => false,
        ];

        DB::table('project_memory_entries')->insert([
            'id' => $entryId,
            'project_id' => (string) $job->project_id,
            'repository_id' => $job->repository_id ? (string) $job->repository_id : null,
            'task_id' => null,
            'run_id' => null,
            'author_user_id' => null,
            'agent_key' => 'hades',
            'source' => 'hades_wiki_refresh',
            'kind' => 'project_awareness',
            'completeness' => 'complete',
            'summary' => $summary,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($written as $page) {
            if (($page['wiki_page_id'] ?? null) === null) {
                continue;
            }

            DB::table('project_memory_links')->insert([
                'id' => (string) Str::ulid(),
                'memory_entry_id' => $entryId,
                'target_type' => 'wiki_page',
                'target_id' => (string) $page['wiki_page_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $entry = DB::table('project_memory_entries')->where('id', $entryId)->first();
        if ($entry) {
            $this->searchIndexer->indexMemoryEntry($entry);
        }

        return [
            'id' => $entryId,
            'created' => true,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @return list<mixed>
     */
    private function evidenceRefs(array $page): array
    {
        foreach (['evidence_refs', 'evidence', 'citations', 'code_evidence'] as $field) {
            if (! is_array($page[$field] ?? null)) {
                continue;
            }

            return array_values($page[$field]);
        }

        return [];
    }

    /** @return array{actor: array{type: string}, payload: array<string, mixed>} */
    private function auditActor(object $job): array
    {
        $hadesAgentId = trim((string) ($job->hades_agent_id ?? ''));
        $workspaceBindingId = trim((string) ($job->workspace_binding_id ?? ''));
        if ($hadesAgentId === '' || $workspaceBindingId === '') {
            throw new WikiRevisionException(
                'schema_validation_failed',
                'Wiki refresh jobs require Hades agent and workspace provenance.',
            );
        }

        $agentPayload = ['hades_agent_id' => $hadesAgentId];
        $externalAgentId = DB::table('hades_agents')
            ->where('id', $hadesAgentId)
            ->where('project_id', (string) $job->project_id)
            ->value('external_agent_id');
        if (is_string($externalAgentId) && trim($externalAgentId) !== '') {
            $agentPayload['external_agent_id'] = $externalAgentId;
        }

        return [
            'actor' => ['type' => 'hades_agent'],
            'payload' => [
                'workspace_binding_id' => $workspaceBindingId,
                'actor' => $agentPayload,
            ],
        ];
    }

    private function repositoryBelongsToProject(string $repositoryId, string $projectId): bool
    {
        return DB::table('repositories')
            ->where('id', $repositoryId)
            ->where('project_id', $projectId)
            ->exists();
    }
}
