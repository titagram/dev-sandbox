<?php

namespace App\Services;

use App\Exceptions\ProjectLogbookException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ProjectLogbookReferenceValidator
{
    private const MAX_REFERENCES = 20;

    /** @var list<string> */
    private const KINDS = [
        'wiki_page',
        'wiki_revision',
        'graph_import',
        'verification_work',
        'kanban_task',
        'run',
        'repository',
        'commit',
        'file',
    ];

    /**
     * @param  array<mixed>  $references
     * @return list<array{kind:string,id:string}>
     */
    public function canonicalize(string $projectId, array $references): array
    {
        if (! array_is_list($references) || count($references) > self::MAX_REFERENCES) {
            throw ProjectLogbookException::referenceInvalid('References must be a list of at most 20 items.');
        }

        $canonical = [];
        $seen = [];

        foreach ($references as $reference) {
            if (! is_array($reference)
                || array_diff(array_keys($reference), ['kind', 'id']) !== []
                || array_diff(['kind', 'id'], array_keys($reference)) !== []
                || ! is_string($reference['kind'])
                || ! is_string($reference['id'])
                || ! in_array($reference['kind'], self::KINDS, true)) {
                throw ProjectLogbookException::referenceInvalid('Each reference requires one supported kind and one string id.');
            }

            $kind = $reference['kind'];
            $id = $reference['id'];
            $this->assertIdentifier($projectId, $kind, $id);

            $key = $kind."\0".$id;
            if (isset($seen[$key])) {
                throw ProjectLogbookException::referenceInvalid('Duplicate references are not allowed.');
            }

            $seen[$key] = true;
            $canonical[] = ['kind' => $kind, 'id' => $id];
        }

        usort(
            $canonical,
            static fn (array $left, array $right): int => [$left['kind'], $left['id']] <=> [$right['kind'], $right['id']],
        );

        return $canonical;
    }

    private function assertIdentifier(string $projectId, string $kind, string $id): void
    {
        if ($kind === 'commit') {
            if (preg_match('/\A[0-9a-f]{40}\z/D', $id) !== 1) {
                throw ProjectLogbookException::referenceInvalid('Commit references require a lowercase 40-character hexadecimal id.');
            }

            return;
        }

        if ($kind === 'file') {
            if (! $this->isSafeRelativePath($id)) {
                throw ProjectLogbookException::referenceInvalid('File references require a safe relative path.');
            }

            return;
        }

        $exists = match ($kind) {
            'wiki_page' => $this->projectRowExists('wiki_pages', $projectId, $id),
            'wiki_revision' => Schema::hasTable('wiki_revisions') && DB::table('wiki_revisions')
                ->join('wiki_pages', 'wiki_pages.id', '=', 'wiki_revisions.wiki_page_id')
                ->where('wiki_pages.project_id', $projectId)
                ->where('wiki_revisions.id', $id)
                ->exists(),
            'graph_import' => $this->projectRowExists('hades_graph_imports', $projectId, $id),
            'verification_work' => $this->projectRowExists('verification_work_items', $projectId, $id),
            'kanban_task' => $this->projectRowExists('tasks', $projectId, $id),
            'run' => $this->projectRowExists('runs', $projectId, $id),
            'repository' => $this->projectRowExists('repositories', $projectId, $id),
            default => false,
        };

        if (! $exists) {
            throw ProjectLogbookException::referenceNotFound();
        }
    }

    private function projectRowExists(string $table, string $projectId, string $id): bool
    {
        return Schema::hasTable($table)
            && DB::table($table)->where('project_id', $projectId)->where('id', $id)->exists();
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === ''
            || strlen($path) > 2048
            || str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1
            || preg_match('/[\x00-\x1F\x7F]/u', $path) === 1) {
            return false;
        }

        $parts = preg_split('/[\\\\\/]/', $path);
        if (! is_array($parts) || in_array('', $parts, true)) {
            return false;
        }

        return collect($parts)->every(static fn (string $part): bool => $part !== '.' && $part !== '..');
    }
}
