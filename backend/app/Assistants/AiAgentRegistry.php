<?php

namespace App\Assistants;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AiAgentRegistry
{
    private const LEGACY_VISIBLE_AGENT_KEYS = [
        'socrate_supervisor' => ['agent_key' => 'socrates', 'label' => 'Socrates'],
        'task_clarifier' => ['agent_key' => 'platon', 'label' => 'Platon'],
        'backlog_triage' => ['agent_key' => 'aristoteles', 'label' => 'Aristoteles'],
    ];

    public function __construct(private readonly ProviderHttpClient $httpClient)
    {
    }

    /**
     * @return array{providers: list<array<string, mixed>>, modelProfiles: list<array<string, mixed>>, agentProfiles: list<array<string, mixed>>}
     */
    public function snapshot(): array
    {
        return [
            'providers' => $this->providers(),
            'modelProfiles' => $this->modelProfiles(),
            'agentProfiles' => $this->agentProfiles(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function providers(): array
    {
        return DB::table('ai_model_providers')
            ->orderByRaw("case when provider_key = 'openai' then 0 else 1 end")
            ->orderBy('display_name')
            ->get()
            ->map(fn (object $provider): array => $this->providerPayload($provider))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function modelProfiles(): array
    {
        return DB::table('ai_model_profiles')
            ->join('ai_model_providers', 'ai_model_providers.id', '=', 'ai_model_profiles.provider_id')
            ->select([
                'ai_model_profiles.id',
                'ai_model_profiles.profile_key',
                'ai_model_profiles.display_name',
                'ai_model_profiles.model_name',
                'ai_model_profiles.runtime_profile',
                'ai_model_profiles.max_context',
                'ai_model_profiles.max_output_tokens',
                'ai_model_profiles.temperature',
                'ai_model_profiles.timeout_seconds',
                'ai_model_profiles.enabled',
                'ai_model_providers.provider_key',
                'ai_model_providers.display_name as provider_name',
            ])
            ->orderBy('ai_model_profiles.display_name')
            ->get()
            ->map(fn (object $profile): array => $this->modelProfilePayload($profile))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function agentProfiles(): array
    {
        return DB::table('ai_agent_profiles')
            ->orderByRaw("case when agent_key = 'socrate_supervisor' then 0 else 1 end")
            ->orderBy('display_name')
            ->get()
            ->map(fn (object $agent): array => $this->agentProfilePayload($agent))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function agentOptionsForProject(?string $projectId): array
    {
        $options = [];

        foreach ($this->visibleServerAgentsForProject($projectId) as $agent) {
            $visibleKey = $this->visibleAgentKeyFor((string) $agent->agent_key);

            if (isset($options[$visibleKey])) {
                continue;
            }

            $options[$visibleKey] = [
                'agent_key' => $visibleKey,
                'label' => $this->visibleAgentLabelFor((string) $agent->agent_key, (string) $agent->display_name),
                'description' => (string) $agent->description,
                'runtime' => 'server_agent',
            ];
        }

        $options['local_agent'] = [
            'agent_key' => 'local_agent',
            'label' => 'Local agent',
            'description' => 'Local plugin work queue.',
            'runtime' => 'local_agent',
        ];

        return array_values($options);
    }

    public function agentVisibleForProject(string $agentKey, ?string $projectId): bool
    {
        if ($agentKey === 'local_agent') {
            return true;
        }

        $agent = $this->agentProfileRecordForVisibilityKey($agentKey);
        if (! $agent || ! $agent->enabled || $agent->default_model_profile_id === null) {
            return false;
        }

        return $this->agentIsVisibleByScope($agent, $projectId);
    }

    /**
     * @param  array{display_name: string, base_url?: string|null, api_key?: string|null, clear_api_key?: bool, enabled: bool}  $input
     * @return array<string, mixed>
     */
    public function updateProvider(string $providerKey, array $input, int $userId): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $providerKey)) {
            throw new InvalidArgumentException('Invalid provider key.');
        }

        $provider = DB::table('ai_model_providers')->where('provider_key', $providerKey)->first();
        $now = now();
        $values = [
            'display_name' => $input['display_name'],
            'base_url' => $input['base_url'] ?? null,
            'enabled' => $input['enabled'],
            'updated_at' => $now,
        ];

        if (($input['clear_api_key'] ?? false) === true) {
            $values['encrypted_api_key'] = null;
            $values['api_key_last_four'] = null;
            $values['api_key_updated_at'] = null;
        } elseif (isset($input['api_key']) && trim((string) $input['api_key']) !== '') {
            $apiKey = trim((string) $input['api_key']);
            $values['encrypted_api_key'] = Crypt::encryptString($apiKey);
            $values['api_key_last_four'] = substr($apiKey, -4);
            $values['api_key_updated_at'] = $now;
        }

        if ($provider) {
            DB::table('ai_model_providers')->where('id', $provider->id)->update($values);
            $providerId = (string) $provider->id;
        } else {
            $providerId = (string) Str::ulid();
            DB::table('ai_model_providers')->insert(array_merge($values, [
                'id' => $providerId,
                'provider_key' => $providerKey,
                'provider_type' => 'openai_compatible',
                'metadata' => '{}',
                'created_by_user_id' => $userId,
                'created_at' => $now,
            ]));
        }

        $updated = DB::table('ai_model_providers')->where('id', $providerId)->first();

        return $this->providerPayload($updated);
    }

    /**
     * @param  array{display_name: string, model_name: string, runtime_profile: string, max_context?: int|null, max_output_tokens: int, temperature: float|int|string, timeout_seconds: int, enabled: bool}  $input
     * @return array<string, mixed>
     */
    public function updateModelProfile(string $profileKey, array $input): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $profileKey)) {
            throw new InvalidArgumentException('Invalid model profile key.');
        }

        $profile = DB::table('ai_model_profiles')->where('profile_key', $profileKey)->first();

        if (! $profile) {
            throw new InvalidArgumentException('Model profile not found.');
        }

        DB::table('ai_model_profiles')->where('id', $profile->id)->update([
            'display_name' => $input['display_name'],
            'model_name' => $input['model_name'],
            'runtime_profile' => $input['runtime_profile'],
            'max_context' => $input['max_context'] ?? null,
            'max_output_tokens' => $input['max_output_tokens'],
            'temperature' => $input['temperature'],
            'timeout_seconds' => $input['timeout_seconds'],
            'enabled' => $input['enabled'],
            'updated_at' => now(),
        ]);

        return $this->modelProfileById((string) $profile->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteModelProfile(string $profileKey): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $profileKey)) {
            throw new InvalidArgumentException('Invalid model profile key.');
        }

        $profile = DB::table('ai_model_profiles')->where('profile_key', $profileKey)->first();
        if (! $profile) {
            throw new InvalidArgumentException('Model profile not found.');
        }

        $payload = $this->modelProfileById((string) $profile->id);
        $assignedAgentKeys = DB::table('ai_agent_profiles')
            ->where('default_model_profile_id', $profile->id)
            ->pluck('agent_key')
            ->map(fn (mixed $agentKey): string => (string) $agentKey)
            ->all();

        DB::transaction(function () use ($profile): void {
            DB::table('ai_agent_profiles')
                ->where('default_model_profile_id', $profile->id)
                ->update([
                    'default_model_profile_id' => null,
                    'updated_at' => now(),
                ]);

            DB::table('ai_model_profiles')->where('id', $profile->id)->delete();
        });

        return [
            ...$payload,
            'unassigned_agent_keys' => $assignedAgentKeys,
        ];
    }

    /**
     * @param  array{provider_key: string, profile_key: string, display_name: string, model_name: string, runtime_profile: string, max_context?: int|null, max_output_tokens: int, temperature: float|int|string, timeout_seconds: int, enabled: bool}  $input
     * @return array<string, mixed>
     */
    public function createModelProfile(array $input): array
    {
        foreach (['provider_key', 'profile_key'] as $key) {
            if (! preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $input[$key])) {
                throw new InvalidArgumentException('Invalid model profile input.');
            }
        }

        $provider = DB::table('ai_model_providers')->where('provider_key', $input['provider_key'])->first();
        if (! $provider) {
            throw new InvalidArgumentException('Model provider not found.');
        }

        if (DB::table('ai_model_profiles')->where('profile_key', $input['profile_key'])->exists()) {
            throw new InvalidArgumentException('Model profile already exists.');
        }

        $profileId = (string) Str::ulid();
        $now = now();

        DB::table('ai_model_profiles')->insert([
            'id' => $profileId,
            'provider_id' => $provider->id,
            'profile_key' => $input['profile_key'],
            'display_name' => $input['display_name'],
            'model_name' => $input['model_name'],
            'runtime_profile' => $input['runtime_profile'],
            'max_context' => $input['max_context'] ?? null,
            'max_output_tokens' => $input['max_output_tokens'],
            'temperature' => $input['temperature'],
            'timeout_seconds' => $input['timeout_seconds'],
            'enabled' => $input['enabled'],
            'metadata' => json_encode([
                'source_status' => 'dashboard_configured',
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->modelProfileById($profileId);
    }

    /**
     * @param  array{
     *     agent_key: string,
     *     display_name: string,
     *     description: string,
     *     agent_type: string,
     *     delegation_mode: string,
     *     parent_agent_key?: string|null,
     *     default_model_profile_id?: string|null,
     *     requires_human_approval: bool,
     *     enabled: bool,
     *     allowed_tools?: array<int, string>,
     *     output_schema?: array<string, mixed>|array<int, mixed>,
     *     trigger_events?: array<int, string>,
     *     visibility_scope?: string,
     *     project_ids?: array<int, string>,
     * } $input
     * @return array<string, mixed>
     */
    public function createAgentProfile(array $input): array
    {
        $this->validateAgentKey($input['agent_key']);

        if (DB::table('ai_agent_profiles')->where('agent_key', $input['agent_key'])->exists()) {
            throw new InvalidArgumentException('Agent profile already exists.');
        }

        $agentId = (string) Str::ulid();
        $now = now();
        $visibilityScope = (string) ($input['visibility_scope'] ?? 'global');
        $projectIds = $input['project_ids'] ?? [];

        DB::transaction(function () use ($agentId, $input, $now, $projectIds, $visibilityScope): void {
            DB::table('ai_agent_profiles')->insert([
                'id' => $agentId,
                'agent_key' => $input['agent_key'],
                'display_name' => $input['display_name'],
                'description' => $input['description'],
                'agent_type' => $input['agent_type'],
                'delegation_mode' => $input['delegation_mode'],
                'parent_agent_key' => $input['parent_agent_key'] ?? null,
                'default_model_profile_id' => $input['default_model_profile_id'] ?? null,
                'requires_human_approval' => $input['requires_human_approval'],
                'enabled' => $input['enabled'],
                'allowed_tools' => json_encode(array_values($input['allowed_tools'] ?? []), JSON_THROW_ON_ERROR),
                'output_schema' => json_encode($input['output_schema'] ?? (object) [], JSON_THROW_ON_ERROR),
                'trigger_events' => json_encode(array_values($input['trigger_events'] ?? []), JSON_THROW_ON_ERROR),
                'visibility_scope' => $visibilityScope,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->syncAgentProjectVisibility($agentId, $visibilityScope, $projectIds, $now);
        });

        return $this->agentProfileById($agentId);
    }

    /**
     * @param  array{
     *     display_name: string,
     *     description: string,
     *     agent_type: string,
     *     delegation_mode: string,
     *     parent_agent_key?: string|null,
     *     default_model_profile_id?: string|null,
     *     requires_human_approval: bool,
     *     enabled: bool,
     *     allowed_tools?: array<int, string>,
     *     output_schema?: array<string, mixed>|array<int, mixed>,
     *     trigger_events?: array<int, string>,
     *     visibility_scope?: string,
     *     project_ids?: array<int, string>,
     * } $input
     * @return array<string, mixed>
     */
    public function replaceAgentProfile(string $agentKey, array $input): array
    {
        $agent = $this->agentProfileRecordByKey($agentKey);
        $now = now();
        $visibilityScope = (string) ($input['visibility_scope'] ?? 'global');
        $projectIds = $input['project_ids'] ?? [];

        DB::transaction(function () use ($agent, $input, $now, $projectIds, $visibilityScope): void {
            DB::table('ai_agent_profiles')->where('id', $agent->id)->update([
                'display_name' => $input['display_name'],
                'description' => $input['description'],
                'agent_type' => $input['agent_type'],
                'delegation_mode' => $input['delegation_mode'],
                'parent_agent_key' => $input['parent_agent_key'] ?? null,
                'default_model_profile_id' => $input['default_model_profile_id'] ?? null,
                'requires_human_approval' => $input['requires_human_approval'],
                'enabled' => $input['enabled'],
                'allowed_tools' => json_encode(array_values($input['allowed_tools'] ?? []), JSON_THROW_ON_ERROR),
                'output_schema' => json_encode($input['output_schema'] ?? (object) [], JSON_THROW_ON_ERROR),
                'trigger_events' => json_encode(array_values($input['trigger_events'] ?? []), JSON_THROW_ON_ERROR),
                'visibility_scope' => $visibilityScope,
                'updated_at' => $now,
            ]);

            $this->syncAgentProjectVisibility((string) $agent->id, $visibilityScope, $projectIds, $now);
        });

        return $this->agentProfileById((string) $agent->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteAgentProfile(string $agentKey): array
    {
        $agent = $this->agentProfileRecordByKey($agentKey);
        $payload = $this->agentProfileById((string) $agent->id);

        DB::table('ai_agent_profiles')->where('id', $agent->id)->delete();

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function validateProvider(string $providerKey): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $providerKey)) {
            throw new InvalidArgumentException('Invalid provider key.');
        }

        $provider = DB::table('ai_model_providers')->where('provider_key', $providerKey)->first();
        if (! $provider) {
            throw new InvalidArgumentException('Model provider not found.');
        }

        $payload = $this->providerPayload($provider);
        $checks = [
            'enabled' => (bool) $payload['enabled'],
            'base_url_configured' => $payload['base_url'] !== null,
            'api_key_configured' => (bool) $payload['api_key_configured'],
        ];

        $status = 'ready_for_runtime';
        if (! $checks['enabled']) {
            $status = 'disabled';
        } elseif (! $checks['base_url_configured'] || ! $checks['api_key_configured']) {
            $status = 'missing_configuration';
        }

        $connection = null;
        if ($status === 'ready_for_runtime' && $providerKey === 'opencode_go') {
            $connection = $this->validateOpenCodeGoConnection($provider);
            $checks['api_reachable'] = $connection['ok'];
            $checks['models_endpoint'] = $connection['models_endpoint'];
            $checks['chat_completions_endpoint'] = $connection['chat_completions_endpoint'];
            $checks['checked_model'] = $connection['checked_model'];
            if (! $connection['ok']) {
                $status = 'invalid';
            }
        }

        return [
            'provider_key' => $payload['provider_key'],
            'provider_type' => $payload['provider_type'],
            'status' => $status,
            'checks' => $checks,
            'message' => $this->providerValidationMessage($status, $providerKey, $connection),
            'redacted_error' => $connection['redacted_error'] ?? null,
            'models' => $connection['models'] ?? [],
            'checked_at' => now()->toISOString(),
        ];
    }

    /**
     * @return array{ok: bool, models_endpoint: string, chat_completions_endpoint: string, checked_model: string|null, models: list<string>, redacted_error?: string}
     */
    private function validateOpenCodeGoConnection(object $provider): array
    {
        $baseUrl = (string) $provider->base_url;

        $modelsEndpoint = $this->modelsEndpoint($baseUrl);
        $chatEndpoint = $this->chatCompletionsEndpoint($baseUrl);
        $apiKey = $this->decryptProviderApiKey($provider);

        if ($apiKey === null) {
            return [
                'ok' => false,
                'models_endpoint' => $modelsEndpoint,
                'chat_completions_endpoint' => $chatEndpoint,
                'checked_model' => null,
                'models' => [],
                'redacted_error' => 'Configured API key could not be decrypted.',
            ];
        }

        try {
            $response = $this->httpClient
                ->withToken($apiKey)
                ->acceptJson()
                ->timeout(10)
                ->get($modelsEndpoint);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'models_endpoint' => $modelsEndpoint,
                'chat_completions_endpoint' => $chatEndpoint,
                'checked_model' => null,
                'models' => [],
                'redacted_error' => $exception->getMessage(),
            ];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'models_endpoint' => $modelsEndpoint,
                'chat_completions_endpoint' => $chatEndpoint,
                'checked_model' => null,
                'models' => [],
                'redacted_error' => 'OpenCode Go models endpoint returned HTTP '.$response->status().'.',
            ];
        }

        $models = $this->modelIdsFromResponse($response->json());
        $checkedModel = $this->firstOpenCodeGoChatModel($models);
        if ($checkedModel === null) {
            return [
                'ok' => false,
                'models_endpoint' => $modelsEndpoint,
                'chat_completions_endpoint' => $chatEndpoint,
                'checked_model' => null,
                'models' => $models,
                'redacted_error' => 'OpenCode Go did not return a chat/completions-compatible model.',
            ];
        }

        try {
            $chatResponse = $this->httpClient
                ->withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post($chatEndpoint, [
                    'model' => $checkedModel,
                    'messages' => [
                        ['role' => 'user', 'content' => 'ping'],
                    ],
                    'max_tokens' => 1,
                ]);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'models_endpoint' => $modelsEndpoint,
                'chat_completions_endpoint' => $chatEndpoint,
                'checked_model' => $checkedModel,
                'models' => $models,
                'redacted_error' => $exception->getMessage(),
            ];
        }

        if (! $chatResponse->successful()) {
            return [
                'ok' => false,
                'models_endpoint' => $modelsEndpoint,
                'chat_completions_endpoint' => $chatEndpoint,
                'checked_model' => $checkedModel,
                'models' => $models,
                'redacted_error' => 'OpenCode Go chat/completions endpoint returned HTTP '.$chatResponse->status().'.',
            ];
        }

        return [
            'ok' => true,
            'models_endpoint' => $modelsEndpoint,
            'chat_completions_endpoint' => $chatEndpoint,
            'checked_model' => $checkedModel,
            'models' => $models,
        ];
    }

    private function openCodeApiRoot(string $baseUrl): string
    {
        $base = rtrim($baseUrl, '/');
        foreach (['/chat/completions', '/messages', '/models'] as $suffix) {
            if (str_ends_with($base, $suffix)) {
                return substr($base, 0, -strlen($suffix));
            }
        }

        return $base;
    }

    private function modelsEndpoint(string $baseUrl): string
    {
        return $this->openCodeApiRoot($baseUrl).'/models';
    }

    private function chatCompletionsEndpoint(string $baseUrl): string
    {
        return $this->openCodeApiRoot($baseUrl).'/chat/completions';
    }

    private function decryptProviderApiKey(object $provider): ?string
    {
        if (! is_string($provider->encrypted_api_key) || trim($provider->encrypted_api_key) === '') {
            return null;
        }

        try {
            return Crypt::decryptString($provider->encrypted_api_key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{provider_key: string, models: list<array{id: string}>, source: string, checked_at: string, message?: string}
     */
    public function getProviderModels(string $providerKey): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $providerKey)) {
            throw new InvalidArgumentException('Invalid provider key.');
        }

        $provider = DB::table('ai_model_providers')->where('provider_key', $providerKey)->first();
        if (! $provider) {
            throw new InvalidArgumentException('Model provider not found.');
        }

        $baseUrl = (string) $provider->base_url;

        $modelsEndpoint = $this->modelsEndpoint($baseUrl);
        $now = now()->toISOString();

        if (! $provider->enabled) {
            return [
                'provider_key' => $providerKey,
                'models' => [],
                'source' => 'remote',
                'checked_at' => $now,
                'message' => 'Provider is disabled.',
            ];
        }

        $apiKey = $this->decryptProviderApiKey($provider);

        if ($apiKey === null) {
            return [
                'provider_key' => $providerKey,
                'models' => [],
                'source' => 'remote',
                'checked_at' => $now,
                'message' => 'API key not configured.',
            ];
        }

        try {
            $response = $this->httpClient
                ->withToken($apiKey)
                ->acceptJson()
                ->timeout(10)
                ->get($modelsEndpoint);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Provider endpoint URL is not allowed.') {
                return [
                    'provider_key' => $providerKey,
                    'models' => [],
                    'source' => 'remote',
                    'checked_at' => $now,
                    'message' => 'Provider endpoint URL is not allowed.',
                ];
            }

            return [
                'provider_key' => $providerKey,
                'models' => [],
                'source' => 'remote',
                'checked_at' => $now,
                'message' => 'Could not reach the models endpoint.',
            ];
        } catch (Throwable) {
            return [
                'provider_key' => $providerKey,
                'models' => [],
                'source' => 'remote',
                'checked_at' => $now,
                'message' => 'Could not reach the models endpoint.',
            ];
        }

        if (! $response->successful()) {
            return [
                'provider_key' => $providerKey,
                'models' => [],
                'source' => 'remote',
                'checked_at' => $now,
                'message' => 'Models endpoint returned an error.',
            ];
        }

        $allModelIds = $this->modelIdsFromResponse($response->json());

        $models = $allModelIds;
        if ($providerKey === 'opencode_go') {
            $models = array_values(array_filter(
                $allModelIds,
                fn (string $id): bool => $this->isOpenCodeGoChatModel($id),
            ));
        }

        return [
            'provider_key' => $providerKey,
            'models' => array_map(fn (string $id): array => ['id' => $id], $models),
            'source' => 'remote',
            'checked_at' => $now,
            'message' => count($models) > 0 ? null : 'No chat/completions-compatible models found.',
        ];
    }

    /**
     * @return list<string>
     */
    private function modelIdsFromResponse(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $models = $payload['data'] ?? $payload['models'] ?? [];
        if (! is_array($models)) {
            return [];
        }

        return collect($models)
            ->map(fn (mixed $model): ?string => is_array($model) && isset($model['id']) ? (string) $model['id'] : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $models
     */
    private function firstOpenCodeGoChatModel(array $models): ?string
    {
        $preferred = $this->openCodeGoChatModelIds();

        foreach ($preferred as $model) {
            if (in_array($model, $models, true)) {
                return $model;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function openCodeGoChatModelIds(): array
    {
        return [
            'glm-5.2',
            'glm-5.1',
            'kimi-k2.7-code',
            'kimi-k2.6',
            'deepseek-v4-flash',
            'deepseek-v4-pro',
            'mimo-v2.5',
            'mimo-v2.5-pro',
        ];
    }

    private function isOpenCodeGoChatModel(string $modelId): bool
    {
        return in_array($modelId, $this->openCodeGoChatModelIds(), true);
    }

    /**
     * @param  array<string, mixed>|null  $connection
     */
    private function providerValidationMessage(string $status, string $providerKey, ?array $connection): string
    {
        if ($status === 'ready_for_runtime' && $providerKey === 'opencode_go') {
            return 'OpenCode Go API connection succeeded.';
        }

        if ($status === 'invalid' && $providerKey === 'opencode_go') {
            return 'OpenCode Go API connection failed.';
        }

        if ($status === 'ready_for_runtime') {
            return 'Provider configuration is present. Runtime model compatibility is validated by the configured adapter.';
        }

        return 'Provider configuration is incomplete or disabled.';
    }

    /**
     * @return list<object>
     */
    private function visibleServerAgentsForProject(?string $projectId): array
    {
        return DB::table('ai_agent_profiles')
            ->where('enabled', true)
            ->whereNotNull('default_model_profile_id')
            ->orderByRaw("case when agent_key = 'socrate_supervisor' then 0 when agent_key = 'task_clarifier' then 1 when agent_key = 'backlog_triage' then 2 else 3 end")
            ->orderBy('display_name')
            ->get()
            ->filter(fn (object $agent): bool => $this->agentIsVisibleByScope($agent, $projectId))
            ->values()
            ->all();
    }

    private function agentIsVisibleByScope(object $agent, ?string $projectId): bool
    {
        $visibilityScope = (string) ($agent->visibility_scope ?? 'global');

        if ($visibilityScope === 'global') {
            return true;
        }

        if ($visibilityScope !== 'project' || $projectId === null) {
            return false;
        }

        return DB::table('ai_agent_project_visibility')
            ->where('ai_agent_profile_id', $agent->id)
            ->where('project_id', $projectId)
            ->exists();
    }

    private function visibleAgentKeyFor(string $agentKey): string
    {
        return self::LEGACY_VISIBLE_AGENT_KEYS[$agentKey]['agent_key'] ?? $agentKey;
    }

    private function visibleAgentLabelFor(string $agentKey, string $displayName): string
    {
        return self::LEGACY_VISIBLE_AGENT_KEYS[$agentKey]['label'] ?? $displayName;
    }

    private function agentProfileRecordForVisibilityKey(string $agentKey): ?object
    {
        $backingAgentKey = $this->backingAgentKeyForVisibility($agentKey);

        return DB::table('ai_agent_profiles')->where('agent_key', $backingAgentKey)->first();
    }

    private function backingAgentKeyForVisibility(string $agentKey): string
    {
        return match ($agentKey) {
            'socrates' => 'socrate_supervisor',
            'platon' => 'task_clarifier',
            'aristoteles' => 'backlog_triage',
            default => $agentKey,
        };
    }

    /**
     * @param  array{default_model_profile_id?: string|null, enabled: bool}  $input
     * @return array<string, mixed>
     */
    public function updateAgentProfile(string $agentKey, array $input): array
    {
        $agent = $this->agentProfileRecordByKey($agentKey);

        DB::table('ai_agent_profiles')->where('id', $agent->id)->update([
            'default_model_profile_id' => $input['default_model_profile_id'] ?? null,
            'enabled' => $input['enabled'],
            'updated_at' => now(),
        ]);

        $updated = DB::table('ai_agent_profiles')->where('id', $agent->id)->first();

        return $this->agentProfilePayload($updated);
    }

    /**
     * @return array<string, mixed>
     */
    private function providerPayload(object $provider): array
    {
        return [
            'id' => (string) $provider->id,
            'provider_key' => (string) $provider->provider_key,
            'display_name' => (string) $provider->display_name,
            'provider_type' => (string) $provider->provider_type,
            'base_url' => $provider->base_url ? (string) $provider->base_url : null,
            'api_key_configured' => $provider->encrypted_api_key !== null,
            'api_key_last_four' => $provider->api_key_last_four ? (string) $provider->api_key_last_four : null,
            'api_key_updated_at' => $provider->api_key_updated_at ? (string) $provider->api_key_updated_at : null,
            'enabled' => (bool) $provider->enabled,
            'metadata' => $this->decodeJsonObject($provider->metadata),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function modelProfileById(string $profileId): array
    {
        $profile = DB::table('ai_model_profiles')
            ->join('ai_model_providers', 'ai_model_providers.id', '=', 'ai_model_profiles.provider_id')
            ->select([
                'ai_model_profiles.id',
                'ai_model_profiles.profile_key',
                'ai_model_profiles.display_name',
                'ai_model_profiles.model_name',
                'ai_model_profiles.runtime_profile',
                'ai_model_profiles.max_context',
                'ai_model_profiles.max_output_tokens',
                'ai_model_profiles.temperature',
                'ai_model_profiles.timeout_seconds',
                'ai_model_profiles.enabled',
                'ai_model_providers.provider_key',
                'ai_model_providers.display_name as provider_name',
            ])
            ->where('ai_model_profiles.id', $profileId)
            ->first();

        return $this->modelProfilePayload($profile);
    }

    private function agentProfileById(string $agentId): array
    {
        $agent = DB::table('ai_agent_profiles')->where('id', $agentId)->first();

        if (! $agent) {
            throw new InvalidArgumentException('Agent profile not found.');
        }

        return $this->agentProfilePayload($agent);
    }

    private function agentProfileRecordByKey(string $agentKey): object
    {
        $this->validateAgentKey($agentKey);

        $agent = DB::table('ai_agent_profiles')->where('agent_key', $agentKey)->first();

        if (! $agent) {
            throw new InvalidArgumentException('Agent profile not found.');
        }

        return $agent;
    }

    private function validateAgentKey(string $agentKey): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $agentKey)) {
            throw new InvalidArgumentException('Invalid agent key.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function modelProfilePayload(object $profile): array
    {
        return [
            'id' => (string) $profile->id,
            'profile_key' => (string) $profile->profile_key,
            'display_name' => (string) $profile->display_name,
            'provider_key' => (string) $profile->provider_key,
            'provider_name' => (string) $profile->provider_name,
            'model_name' => (string) $profile->model_name,
            'runtime_profile' => (string) $profile->runtime_profile,
            'max_context' => $profile->max_context === null ? null : (int) $profile->max_context,
            'max_output_tokens' => (int) $profile->max_output_tokens,
            'temperature' => (float) $profile->temperature,
            'timeout_seconds' => (int) $profile->timeout_seconds,
            'enabled' => (bool) $profile->enabled,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function agentProfilePayload(object $agent): array
    {
        return [
            'id' => (string) $agent->id,
            'agent_key' => (string) $agent->agent_key,
            'display_name' => (string) $agent->display_name,
            'description' => (string) $agent->description,
            'agent_type' => (string) $agent->agent_type,
            'delegation_mode' => (string) $agent->delegation_mode,
            'parent_agent_key' => $agent->parent_agent_key ? (string) $agent->parent_agent_key : null,
            'default_model_profile_id' => $agent->default_model_profile_id ? (string) $agent->default_model_profile_id : null,
            'requires_human_approval' => (bool) $agent->requires_human_approval,
            'enabled' => (bool) $agent->enabled,
            'visibility_scope' => (string) ($agent->visibility_scope ?? 'global'),
            'project_ids' => $this->agentProjectIds((string) $agent->id),
            'allowed_tools' => $this->decodeJsonList($agent->allowed_tools),
            'output_schema' => $this->decodeJsonObject($agent->output_schema),
            'trigger_events' => $this->decodeJsonList($agent->trigger_events),
        ];
    }

    /**
     * @return list<string>
     */
    private function agentProjectIds(string $agentId): array
    {
        return DB::table('ai_agent_project_visibility')
            ->where('ai_agent_profile_id', $agentId)
            ->orderBy('project_id')
            ->pluck('project_id')
            ->map(fn (mixed $projectId): string => (string) $projectId)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $projectIds
     */
    private function syncAgentProjectVisibility(string $agentId, string $visibilityScope, array $projectIds, mixed $now): void
    {
        DB::table('ai_agent_project_visibility')->where('ai_agent_profile_id', $agentId)->delete();

        if ($visibilityScope !== 'project') {
            return;
        }

        $distinctProjectIds = array_values(array_unique(array_map(
            fn (mixed $projectId): string => (string) $projectId,
            $projectIds,
        )));

        if ($distinctProjectIds === []) {
            return;
        }

        DB::table('ai_agent_project_visibility')->insert(array_map(
            fn (string $projectId): array => [
                'id' => (string) Str::ulid(),
                'ai_agent_profile_id' => $agentId,
                'project_id' => $projectId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $distinctProjectIds,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<mixed>
     */
    private function decodeJsonList(mixed $value): array
    {
        $decoded = $this->decodeJsonObject($value);

        return array_is_list($decoded) ? $decoded : [];
    }
}
