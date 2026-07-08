<?php

namespace App\Assistants\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

final class IntakeNormalizerAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are DevBoard's Intake Normalizer specialist.

Analyze raw free-text input from a PM, developer, or stakeholder and classify it into one of these types:
- bug: a defect, crash, error, regression, or unexpected behavior
- task: a concrete piece of work to be done
- feature: a new capability or enhancement
- question: a request for information or explanation

Extract or infer a short title and a normalized description that captures the core problem or request. Raise clarifying questions only when critical facts are missing (who, what, where, how to reproduce). Return concise structured output only and ground the classification in the text evidence.
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_type' => $schema->string()
                ->enum(['bug', 'task', 'feature', 'question'])
                ->required(),
            'suggested_title' => $schema->string()
                ->min(3)
                ->max(180)
                ->required(),
            'suggested_description' => $schema->string()
                ->min(1)
                ->max(5000)
                ->required(),
            'clarifying_questions' => $schema->array()
                ->items($schema->string()->min(4)->max(260))
                ->min(0)
                ->max(6)
                ->required(),
            'confidence' => $schema->number()
                ->min(0)
                ->max(1)
                ->required(),
        ];
    }
}
