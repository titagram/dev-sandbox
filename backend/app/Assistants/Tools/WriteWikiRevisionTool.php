<?php

namespace App\Assistants\Tools;

use App\Services\WikiRevisionException;
use App\Services\WikiRevisionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class WriteWikiRevisionTool implements Tool
{
    public function __construct(private readonly WikiRevisionService $wiki) {}

    public function name(): string
    {
        return 'write_wiki_revision';
    }

    public function description(): Stringable|string
    {
        return 'Write a controlled DevBoard wiki revision through WikiRevisionService. The write is audited and verified_from_code requires evidence_refs.';
    }

    public function handle(Request $request): Stringable|string
    {
        return json_encode($this->payload($request->all()), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(array $arguments): array
    {
        $projectId = (string) ($arguments['project_id'] ?? '');
        $repositoryId = $arguments['repository_id'] ?? null;
        $agentKey = trim((string) ($arguments['agent_key'] ?? 'unknown_agent')) ?: 'unknown_agent';

        $projectExists = DB::table('projects')
            ->where('id', $projectId)
            ->where('status', '!=', 'deleted')
            ->exists();

        if (! $projectExists) {
            return $this->failed('project_not_found_or_deleted', 'Project was not found or is deleted.');
        }

        if ($repositoryId !== null && ! DB::table('repositories')->where('id', $repositoryId)->where('project_id', $projectId)->exists()) {
            return $this->failed('repository_not_found', 'Repository does not belong to the project.');
        }

        foreach (['slug', 'title', 'content_markdown'] as $required) {
            if (! is_string($arguments[$required] ?? null) || trim((string) $arguments[$required]) === '') {
                return $this->failed('validation_failed', "{$required} is required.");
            }
        }

        $sourceStatus = $this->sourceStatus((string) ($arguments['source_status'] ?? 'needs_verification'));

        try {
            $result = $this->wiki->write([
                'project_id' => $projectId,
                'repository_id' => $repositoryId,
                'slug' => trim((string) $arguments['slug']),
                'title' => trim((string) $arguments['title']),
                'page_type' => trim((string) ($arguments['page_type'] ?? 'technical')) ?: 'technical',
                'producer' => 'ai_agent:'.$agentKey,
                'source_type' => 'controlled_agent_tool',
                'source_status' => $sourceStatus,
                'content_markdown' => (string) $arguments['content_markdown'],
                'evidence_refs' => is_array($arguments['evidence_refs'] ?? null) ? $arguments['evidence_refs'] : [],
            ]);
        } catch (WikiRevisionException $exception) {
            return $this->failed($exception->errorCode, $exception->getMessage());
        }

        return [
            'tool' => $this->name(),
            'source_status' => 'verified_from_code',
            'written' => true,
            'agent_key' => $agentKey,
            'wiki_page_id' => $result['wiki_page_id'],
            'wiki_revision_id' => $result['wiki_revision_id'],
            'revision_source_status' => $result['source_status'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->description('Project ULID.')->required(),
            'repository_id' => $schema->string()->description('Optional repository ULID scoped to the same project.'),
            'slug' => $schema->string()->description('Wiki page slug.')->required(),
            'title' => $schema->string()->description('Wiki page title.')->required(),
            'page_type' => $schema->string()->description('Wiki page type, for example technical or business.'),
            'source_status' => $schema->string()
                ->description('Source status label.')
                ->enum(['verified_from_code', 'developer_provided', 'inferred', 'needs_verification']),
            'content_markdown' => $schema->string()->description('Markdown content for the new revision.')->required(),
            'evidence_refs' => $schema->array()
                ->description('Evidence references. Required when source_status is verified_from_code.')
                ->items($schema->object()),
            'agent_key' => $schema->string()->description('Controlled agent key creating the revision.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failed(string $code, string $message): array
    {
        return [
            'tool' => $this->name(),
            'source_status' => 'verified_from_code',
            'written' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    private function sourceStatus(string $sourceStatus): string
    {
        $allowed = ['verified_from_code', 'developer_provided', 'inferred', 'needs_verification'];

        return in_array($sourceStatus, $allowed, true) ? $sourceStatus : 'needs_verification';
    }
}
