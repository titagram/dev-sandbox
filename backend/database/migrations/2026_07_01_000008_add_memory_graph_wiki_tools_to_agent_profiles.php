<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->toolsByAgent() as $agentKey => $tools) {
            $current = DB::table('ai_agent_profiles')->where('agent_key', $agentKey)->value('allowed_tools');
            if (! is_string($current)) {
                continue;
            }

            $decoded = json_decode($current, true);
            $allowedTools = is_array($decoded) && array_is_list($decoded) ? $decoded : [];
            $merged = array_values(array_unique(array_merge($allowedTools, $tools)));

            DB::table('ai_agent_profiles')->where('agent_key', $agentKey)->update([
                'allowed_tools' => json_encode($merged, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->toolsByAgent() as $agentKey => $tools) {
            $current = DB::table('ai_agent_profiles')->where('agent_key', $agentKey)->value('allowed_tools');
            if (! is_string($current)) {
                continue;
            }

            $decoded = json_decode($current, true);
            $allowedTools = is_array($decoded) && array_is_list($decoded) ? $decoded : [];
            $remaining = array_values(array_diff($allowedTools, $tools));

            DB::table('ai_agent_profiles')->where('agent_key', $agentKey)->update([
                'allowed_tools' => json_encode($remaining, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function toolsByAgent(): array
    {
        return [
            'socrate_supervisor' => ['search_project_memory', 'query_project_graph'],
            'wiki_query' => ['search_project_memory', 'query_project_graph', 'write_wiki_revision'],
        ];
    }
};
