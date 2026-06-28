<?php

namespace App\Assistants;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AiAgentRegistry
{
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
     * @param array{display_name: string, base_url?: string|null, api_key?: string|null, clear_api_key?: bool, enabled: bool} $input
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
     * @param array{display_name: string, model_name: string, runtime_profile: string, max_context?: int|null, max_output_tokens: int, temperature: float|int|string, timeout_seconds: int, enabled: bool} $input
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
     * @param array{default_model_profile_id?: string|null, enabled: bool} $input
     * @return array<string, mixed>
     */
    public function updateAgentProfile(string $agentKey, array $input): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $agentKey)) {
            throw new InvalidArgumentException('Invalid agent key.');
        }

        $agent = DB::table('ai_agent_profiles')->where('agent_key', $agentKey)->first();

        if (! $agent) {
            throw new InvalidArgumentException('Agent profile not found.');
        }

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
            'allowed_tools' => $this->decodeJsonList($agent->allowed_tools),
            'output_schema' => $this->decodeJsonObject($agent->output_schema),
            'trigger_events' => $this->decodeJsonList($agent->trigger_events),
        ];
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
