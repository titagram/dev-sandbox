<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WikiRevisionService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{wiki_page_id: string, wiki_revision_id: string, source_status: string}
     */
    public function write(array $payload, ?int $authorUserId = null, ?string $authorDeviceId = null): array
    {
        $evidence = $payload['evidence_refs'] ?? [];
        if (($payload['source_status'] ?? null) === 'verified_from_code' && $evidence === []) {
            throw new WikiRevisionException('schema_validation_failed', 'verified_from_code wiki revisions require evidence.');
        }

        $now = now();
        $pageId = $this->findPageId($payload);

        if (! $pageId) {
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

        $revisionId = (string) Str::ulid();
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

        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => $authorUserId,
            'actor_device_id' => $authorDeviceId,
            'actor_type' => $authorUserId ? 'user' : ($authorDeviceId ? 'plugin' : 'system'),
            'action' => 'wiki.updated',
            'target_type' => 'wiki_page',
            'target_id' => $pageId,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => json_encode(['wiki_revision_id' => $revisionId], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);

        return [
            'wiki_page_id' => $pageId,
            'wiki_revision_id' => $revisionId,
            'source_status' => $payload['source_status'],
        ];
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
