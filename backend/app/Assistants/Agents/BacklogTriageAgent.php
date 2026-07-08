<?php

namespace App\Assistants\Agents;

use App\Assistants\AiAgentToolRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

final class BacklogTriageAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are DevBoard's Backlog Triage specialist.

Search shared project memory before making recommendations, then review project backlog and Kanban task evidence. Find vague, duplicate, stale, oversized, blocked, or risky work. Produce project-level recommendations only. Do not mutate tasks, Kanban columns, owners, priority, due dates, wiki, runs, or project state. Return concise structured output only and ground claims in memory/task evidence when available.
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()
                ->min(12)
                ->max(500)
                ->required(),
            'groups' => $schema->array()
                ->items($schema->object([
                    'label' => $schema->string()->min(3)->max(120)->required(),
                    'task_ids' => $schema->array()->items($schema->string()->min(6)->max(40))->min(0)->max(12)->required(),
                    'reason' => $schema->string()->min(8)->max(360)->required(),
                ]))
                ->min(1)
                ->max(6)
                ->required(),
            'recommendations' => $schema->array()
                ->items($schema->object([
                    'title' => $schema->string()->min(6)->max(160)->required(),
                    'body' => $schema->string()->min(12)->max(500)->required(),
                    'task_ids' => $schema->array()->items($schema->string()->min(6)->max(40))->min(0)->max(12)->required(),
                    'priority' => $schema->string()->enum(['low', 'normal', 'high'])->required(),
                ]))
                ->min(1)
                ->max(8)
                ->required(),
            'risks' => $schema->array()
                ->items($schema->string()->min(8)->max(280))
                ->min(0)
                ->max(6)
                ->required(),
            'confidence' => $schema->number()
                ->min(0)
                ->max(1)
                ->required(),
        ];
    }

    public function tools(): iterable
    {
        return app(AiAgentToolRegistry::class)->forAgentKey('backlog_triage');
    }
}
