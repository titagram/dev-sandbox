<?php

namespace App\Services\Hades;

use App\Enums\SourceStatus;
use App\Services\AuditLogger;
use App\Services\WikiRevisionService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class WikiVerificationService
{
    public function __construct(
        private readonly WikiVerificationEvidencePolicy $evidence,
        private readonly WikiRevisionService $revisions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $evidenceRefs
     * @return array{wiki_page_id: string, wiki_revision_id: string, source_status: string, created: bool}
     */
    public function verify(
        string $projectId,
        string $workspaceBindingId,
        string $pageId,
        string $expectedCurrentRevisionId,
        array $evidenceRefs,
        object $agent,
    ): array {
        return DB::transaction(function () use (
            $projectId,
            $workspaceBindingId,
            $pageId,
            $expectedCurrentRevisionId,
            $evidenceRefs,
            $agent,
        ): array {
            $page = DB::table('wiki_pages')
                ->where('id', $pageId)
                ->where('project_id', $projectId)
                ->lockForUpdate()
                ->first();

            if ($page === null) {
                throw new HadesTokenException(
                    'wiki_page_not_found',
                    'Hades wiki page was not found.',
                    Response::HTTP_NOT_FOUND,
                );
            }

            if ($page->current_revision_id !== $expectedCurrentRevisionId) {
                throw new HadesTokenException(
                    'revision_conflict',
                    'The wiki page current revision has changed.',
                    Response::HTTP_CONFLICT,
                );
            }

            $currentRevision = DB::table('wiki_revisions')
                ->where('id', $page->current_revision_id)
                ->where('wiki_page_id', $page->id)
                ->first();

            if ($currentRevision === null) {
                throw new HadesTokenException(
                    'revision_conflict',
                    'The wiki page current revision is unavailable.',
                    Response::HTTP_CONFLICT,
                );
            }

            $resolvedEvidence = $this->evidence->resolve($projectId, $workspaceBindingId, $evidenceRefs);
            $result = $this->revisions->write([
                'project_id' => $projectId,
                'repository_id' => $page->repository_id,
                'slug' => $page->slug,
                'title' => $page->title,
                'page_type' => $page->page_type,
                'producer' => 'hades',
                'source_type' => 'hades_agent_verification',
                'source_status' => SourceStatus::VerifiedFromCode->value,
                'content_markdown' => $currentRevision->content_markdown,
                'evidence_refs' => $resolvedEvidence,
            ]);

            if ($result['created']) {
                throw new RuntimeException('Wiki verification unexpectedly created a new page.');
            }

            $this->audit->record('wiki.verified', 'wiki_page', $page->id, [
                'prior_revision_id' => $currentRevision->id,
                'new_revision_id' => $result['wiki_revision_id'],
                'workspace_binding_id' => $workspaceBindingId,
                'actor' => [
                    'hades_agent_id' => $agent->id,
                    'external_agent_id' => $agent->external_agent_id,
                ],
            ], [
                'type' => 'hades_agent',
            ]);

            return $result;
        }, 3);
    }
}
