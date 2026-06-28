<?php

namespace App\Assistants;

use App\Assistants\Tools\ReadProjectSummaryTool;
use App\Assistants\Tools\ReadTaskDetailTool;
use App\Assistants\Tools\SearchWikiRevisionsTool;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;

final class AiAgentToolRegistry
{
    /**
     * @var array<string, class-string<Tool>>
     */
    private array $tools = [
        'read_project_summary' => ReadProjectSummaryTool::class,
        'read_task_detail' => ReadTaskDetailTool::class,
        'search_wiki_revisions' => SearchWikiRevisionsTool::class,
    ];

    /**
     * @return list<Tool>
     */
    public function forAgentKey(string $agentKey): array
    {
        $allowedTools = DB::table('ai_agent_profiles')->where('agent_key', $agentKey)->value('allowed_tools');

        if (! is_string($allowedTools) || trim($allowedTools) === '') {
            return [];
        }

        $decoded = json_decode($allowedTools, true);
        $toolKeys = is_array($decoded) && array_is_list($decoded) ? $decoded : [];

        return collect($toolKeys)
            ->filter(fn (mixed $toolKey): bool => is_string($toolKey) && isset($this->tools[$toolKey]))
            ->map(fn (string $toolKey): Tool => $this->make($toolKey))
            ->values()
            ->all();
    }

    public function make(string $toolKey): Tool
    {
        if (! isset($this->tools[$toolKey])) {
            throw new InvalidArgumentException("Unknown AI agent tool [{$toolKey}].");
        }

        return app($this->tools[$toolKey]);
    }
}
