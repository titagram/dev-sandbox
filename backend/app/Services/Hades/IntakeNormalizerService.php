<?php

namespace App\Services\Hades;

use App\Assistants\Agents\IntakeNormalizerAgent;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

final class IntakeNormalizerService
{
    public function __construct(
        private readonly HadesKanbanTaskIntakeService $kanbanIntake,
        private readonly HadesEvidencePolicy $evidencePolicy,
    ) {}

    /**
     * Normalize raw free-text input into a structured preview.
     *
     * @return array{task_type: string, suggested_title: string, suggested_description: string, clarifying_questions: list<string>, requires_root_cause: bool, confidence: float, execution_mode: string}
     */
    public function normalize(string $rawText, ?string $projectId = null): array
    {
        $agent = DB::table('ai_agent_profiles')->where('agent_key', 'intake_normalizer')->first();
        $modelProfile = $agent ? $this->modelProfileForAgent($agent) : null;
        $shouldUseSdk = IntakeNormalizerAgent::isFaked() || $this->modelProfileCanCallProvider($modelProfile);

        if (! $shouldUseSdk) {
            return $this->kanbanIntake->normalizeFreeText($rawText, $projectId !== null);
        }

        if ($modelProfile) {
            $this->configureLaravelAiProvider($modelProfile);
        }

        // Redact raw text before sending it to the LLM; keep original for local fallback.
        $redacted = $this->evidencePolicy->redactTextMaterial($rawText);
        $prompt = $this->promptForRawText($redacted['text'], $projectId);

        try {
            $response = IntakeNormalizerAgent::make()->prompt(
                $prompt,
                provider: $modelProfile?->provider_key ? (string) $modelProfile->provider_key : null,
                model: $modelProfile?->model_name ? (string) $modelProfile->model_name : null,
                timeout: $modelProfile?->timeout_seconds ? (int) $modelProfile->timeout_seconds : null,
            );
        } catch (Throwable) {
            return $this->kanbanIntake->normalizeFreeText($rawText, $projectId !== null);
        }

        $structured = $this->normalizeStructuredResult(
            $response instanceof StructuredAgentResponse
                ? $response->structured
                : json_decode($response->text, true) ?? [],
            $rawText,
            $projectId,
        );

        $haystack = mb_strtolower($rawText);

        return [
            'task_type' => $structured['task_type'],
            'suggested_title' => $structured['suggested_title'],
            'suggested_description' => $structured['suggested_description'],
            'clarifying_questions' => $structured['clarifying_questions'],
            "requires_root_cause" => str_contains($haystack, "root cause")
                || str_contains($haystack, "diagnose")
                || str_contains($haystack, "diagnosi"),
            'confidence' => (float) $structured['confidence'],
            'execution_mode' => IntakeNormalizerAgent::isFaked() ? 'laravel_ai_sdk_fake' : 'laravel_ai_sdk',
        ];
    }

    private function modelProfileForAgent(object $agentProfile): ?object
    {
        if (! $agentProfile->default_model_profile_id) {
            return null;
        }

        return DB::table('ai_model_profiles')
            ->join('ai_model_providers', 'ai_model_providers.id', '=', 'ai_model_profiles.provider_id')
            ->select([
                'ai_model_profiles.id as model_profile_id',
                'ai_model_profiles.model_name',
                'ai_model_profiles.timeout_seconds',
                'ai_model_profiles.enabled as model_profile_enabled',
                'ai_model_providers.id as model_provider_id',
                'ai_model_providers.provider_key',
                'ai_model_providers.provider_type',
                'ai_model_providers.base_url',
                'ai_model_providers.encrypted_api_key',
                'ai_model_providers.enabled as provider_enabled',
            ])
            ->where('ai_model_profiles.id', $agentProfile->default_model_profile_id)
            ->first();
    }

    private function modelProfileCanCallProvider(?object $modelProfile): bool
    {
        return $modelProfile
            && (bool) $modelProfile->model_profile_enabled
            && (bool) $modelProfile->provider_enabled
            && filled($modelProfile->encrypted_api_key);
    }

    private function configureLaravelAiProvider(object $modelProfile): void
    {
        $providerKey = (string) $modelProfile->provider_key;
        $current = config("ai.providers.{$providerKey}", ['driver' => $providerKey]);
        $driver = (string) ($modelProfile->provider_type === 'openai_compatible'
            ? 'openai'
            : ($current['driver'] ?? $providerKey));

        config([
            "ai.providers.{$providerKey}" => array_replace_recursive($current, [
                'driver' => $driver,
                'key' => filled($modelProfile->encrypted_api_key)
                    ? Crypt::decryptString((string) $modelProfile->encrypted_api_key)
                    : ($current['key'] ?? null),
                'url' => $modelProfile->base_url ?: ($current['url'] ?? null),
                'models' => [
                    'text' => [
                        'default' => (string) $modelProfile->model_name,
                    ],
                ],
            ]),
        ]);

        Ai::forgetInstance($providerKey);
    }

    private function promptForRawText(string $rawText, ?string $projectId): string
    {
        $safeText = json_encode($rawText, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $projectContext = $projectId ? "Project context is already known from the route: {$projectId}. Do not ask which project or repository this applies to unless the text explicitly names a conflicting one." : "No project context was provided.";

        return <<<PROMPT
Analyze this raw free-text input from a team member and produce an intake normalization.

Use only the provided text. If something is not present and critical, list it as clarifying_questions. {$projectContext}
Classify into bug, task, feature, or question. Extract or infer a short title and normalized description.

Raw text:
{$safeText}

Return task_type, suggested_title, suggested_description, clarifying_questions, and confidence.
PROMPT;
    }

    /**
     * @param array<string, mixed> $structured
     * @return array{task_type: string, suggested_title: string, suggested_description: string, clarifying_questions: list<string>, confidence: float}
     */
    private function normalizeStructuredResult(array $structured, string $rawText, ?string $projectId): array
    {
        $fallback = $this->kanbanIntake->normalizeFreeText($rawText, $projectId !== null);
        $allowedTypes = ['bug', 'task', 'feature', 'question'];
        $taskType = in_array($structured['task_type'] ?? null, $allowedTypes, true)
            ? (string) $structured['task_type']
            : $fallback['task_type'];

        $title = is_string($structured['suggested_title'] ?? null) && trim((string) $structured['suggested_title']) !== ''
            ? mb_substr(trim((string) $structured['suggested_title']), 0, 180)
            : $fallback['suggested_title'];

        $description = is_string($structured['suggested_description'] ?? null) && trim((string) $structured['suggested_description']) !== ''
            ? mb_substr(trim((string) $structured['suggested_description']), 0, 5000)
            : $fallback['suggested_description'];

        $questions = is_array($structured['clarifying_questions'] ?? null)
            ? array_values(array_filter(
                array_map(fn (mixed $q): string => mb_substr(trim((string) $q), 0, 260), $structured['clarifying_questions']),
                fn (string $q): bool => $q !== '',
            ))
            : $fallback['clarifying_questions'];

        $confidence = round(max(0, min(1, (float) ($structured['confidence'] ?? $fallback['confidence']))), 2);

        return [
            'task_type' => $taskType,
            'suggested_title' => $title,
            'suggested_description' => $description,
            'clarifying_questions' => array_slice($questions, 0, 6),
            'confidence' => $confidence,
        ];
    }
}
