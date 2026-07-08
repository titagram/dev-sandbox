<?php

namespace App\Assistants\Agents;

use App\Assistants\AiAgentToolRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

final class TaskClarifierAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are DevBoard's Task Clarifier specialist.

Search shared project memory before clarifying a PM-authored task, then review the task before developers start implementation. Ask only questions that reduce ambiguity, propose acceptance criteria that are observable by a developer, and call out risks or missing context. Do not rewrite or mutate the task. Return concise structured output only and ground questions in memory/task evidence when available.
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'questions' => $schema->array()
                ->items($schema->string()->min(8)->max(240))
                ->min(1)
                ->max(6)
                ->required(),
            'acceptance_criteria' => $schema->array()
                ->items($schema->string()->min(8)->max(260))
                ->min(1)
                ->max(8)
                ->required(),
            'risks' => $schema->array()
                ->items($schema->string()->min(8)->max(260))
                ->min(1)
                ->max(6)
                ->required(),
            'missing_context' => $schema->array()
                ->items($schema->string()->min(2)->max(80))
                ->min(0)
                ->max(8)
                ->required(),
            'confidence' => $schema->number()
                ->min(0)
                ->max(1)
                ->required(),
        ];
    }

    public function tools(): iterable
    {
        return app(AiAgentToolRegistry::class)->forAgentKey('task_clarifier');
    }
}
