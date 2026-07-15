<?php

namespace App\Services;

use App\Services\Hades\HadesSearchDocumentIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WikiRevisionService
{
    public function __construct(private readonly HadesSearchDocumentIndexer $searchIndexer) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{wiki_page_id: string, wiki_revision_id: string, source_status: string, created: bool}
     */
    public function write(array $payload, ?int $authorUserId = null, ?string $authorDeviceId = null, ?array $auditActor = null): array
    {
        if (($payload['producer'] ?? null) === 'dashboard_user' || ($payload['source_type'] ?? null) === 'user_manual') {
            $payload['source_status'] = 'needs_verification';
        }

        $evidence = $payload['evidence_refs'] ?? [];
        if (($payload['source_status'] ?? null) === 'verified_from_code' && $evidence === []) {
            throw new WikiRevisionException('schema_validation_failed', 'verified_from_code wiki revisions require evidence.');
        }

        $revisionId = (string) Str::ulid();
        $write = DB::transaction(function () use ($payload, $evidence, $revisionId, $authorUserId, $authorDeviceId, $auditActor): array {
            DB::table('projects')
                ->where('id', $payload['project_id'])
                ->lockForUpdate()
                ->first();

            $pageId = $this->findPageId($payload);
            $created = $pageId === null;
            $now = now();

            if ($created) {
                $pageId = (string) Str::ulid();
                DB::table('wiki_pages')->insert([
                    'id' => $pageId,
                    'project_id' => $payload['project_id'],
                    'repository_id' => $payload['repository_id'] ?? null,
                    'slug' => $payload['slug'],
                    'title' => $payload['title'],
                    'page_type' => $payload['page_type'],
                    'current_revision_id' => null,
                    'source_status' => $payload['source_status'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('wiki_revisions')->insert([
                'id' => $revisionId,
                'wiki_page_id' => $pageId,
                'author_user_id' => $authorUserId,
                'author_device_id' => $authorDeviceId,
                'producer' => $payload['producer'],
                'source_type' => $payload['source_type'],
                'source_status' => $payload['source_status'],
                'content_markdown' => $payload['content_markdown'],
                'evidence_refs' => json_encode($evidence, JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);

            DB::table('wiki_pages')->where('id', $pageId)->update([
                'title' => $payload['title'],
                'page_type' => $payload['page_type'],
                'current_revision_id' => $revisionId,
                'source_status' => $payload['source_status'],
                'updated_at' => $now,
            ]);

            app(AuditLogger::class)->record('wiki.updated', 'wiki_page', $pageId, [
                'wiki_revision_id' => $revisionId,
                ...($auditActor['payload'] ?? []),
            ], $auditActor['actor'] ?? [
                'type' => $authorUserId ? 'user' : ($authorDeviceId ? 'plugin' : 'system'),
                'user_id' => $authorUserId,
                'device_id' => $authorDeviceId,
            ]);

            return [
                'wiki_page_id' => $pageId,
                'wiki_revision_id' => $revisionId,
                'source_status' => $payload['source_status'],
                'created' => $created,
            ];
        }, 3);

        $page = DB::table('wiki_pages')->where('id', $write['wiki_page_id'])->first();
        $revision = DB::table('wiki_revisions')->where('id', $revisionId)->first();
        if ($page && $revision) {
            $this->searchIndexer->indexWikiRevision($page, $revision);
        }

        return $write;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findPageId(array $payload): ?string
    {
        $query = DB::table('wiki_pages')
            ->where('project_id', $payload['project_id'])
            ->where('slug', $payload['slug']);

        if (array_key_exists('repository_id', $payload) && $payload['repository_id'] !== null) {
            $query->where('repository_id', $payload['repository_id']);
        } else {
            $query->whereNull('repository_id');
        }

        return $query->value('id');
    }
}
