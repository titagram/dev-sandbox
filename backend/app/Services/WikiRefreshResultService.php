<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class WikiRefreshResultService
{
    public function __construct(private readonly WikiRevisionService $revisions) {}

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
            $sourceStatus = $this->sourceStatus($page, $evidenceRefs);

            $payload = [
                'project_id' => (string) $job->project_id,
                'repository_id' => $repositoryId ? (string) $repositoryId : null,
                'slug' => (string) $page['slug'],
                'title' => (string) $page['title'],
                'page_type' => (string) ($page['page_type'] ?? 'technical'),
                'producer' => (string) ($page['producer'] ?? 'hades'),
                'source_type' => 'hades_wiki_refresh',
                'source_status' => $sourceStatus,
                'content_markdown' => (string) $page['content_markdown'],
                'evidence_refs' => $evidenceRefs,
            ];

            $written[] = [
                ...$this->revisions->write($payload),
                'slug' => $payload['slug'],
                'title' => $payload['title'],
            ];
        }

        return [
            'schema' => 'devboard.wiki_refresh_apply.v1',
            'pages_written' => count($written),
            'pages' => $written,
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

    /**
     * @param  array<string, mixed>  $page
     * @param  list<mixed>  $evidenceRefs
     */
    private function sourceStatus(array $page, array $evidenceRefs): string
    {
        $status = (string) ($page['source_status'] ?? $page['source'] ?? '');
        $allowed = [
            'verified_from_code',
            'developer_provided',
            'ai_generated',
            'needs_verification',
            'stale',
            'conflict_with_code',
        ];

        if (! in_array($status, $allowed, true)) {
            $status = $evidenceRefs === [] ? 'needs_verification' : 'verified_from_code';
        }

        if ($status === 'verified_from_code' && $evidenceRefs === []) {
            return 'needs_verification';
        }

        return $status;
    }

    private function repositoryBelongsToProject(string $repositoryId, string $projectId): bool
    {
        return DB::table('repositories')
            ->where('id', $repositoryId)
            ->where('project_id', $projectId)
            ->exists();
    }
}
