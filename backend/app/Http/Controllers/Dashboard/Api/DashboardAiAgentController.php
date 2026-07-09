<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Assistants\AiAgentRegistry;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Validation\Rule;

final class DashboardAiAgentController extends Controller
{
    use ChecksDashboardRoles;

    public function index(Request $request, AiAgentRegistry $registry): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        return response()->json($registry->snapshot());
    }

    public function updateProvider(Request $request, AiAgentRegistry $registry, string $provider): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'base_url' => ['nullable', 'url', 'max:2048'],
            'api_key' => ['nullable', 'string', 'max:4096'],
            'clear_api_key' => ['sometimes', 'boolean'],
            'enabled' => ['required', 'boolean'],
        ]);

        try {
            $payload = $registry->updateProvider($provider, $validated, $request->user()->id);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => $request->user()->id,
            'actor_device_id' => null,
            'actor_type' => 'user',
            'action' => 'ai_model_provider.updated',
            'target_type' => 'ai_model_provider',
            'target_id' => $payload['id'],
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'payload' => json_encode([
                'provider_key' => $payload['provider_key'],
                'display_name' => $payload['display_name'],
                'base_url' => $payload['base_url'],
                'enabled' => $payload['enabled'],
                'api_key_configured' => $payload['api_key_configured'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response()->json(['provider' => $payload]);
    }

    public function updateModelProfile(Request $request, AiAgentRegistry $registry, string $profile): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'model_name' => ['required', 'string', 'max:180'],
            'runtime_profile' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'max_context' => ['nullable', 'integer', 'min:1', 'max:2000000'],
            'max_output_tokens' => ['required', 'integer', 'min:1', 'max:200000'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'timeout_seconds' => ['required', 'integer', 'min:1', 'max:300'],
            'enabled' => ['required', 'boolean'],
        ]);

        try {
            $payload = $registry->updateModelProfile($profile, $validated);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => $request->user()->id,
            'actor_device_id' => null,
            'actor_type' => 'user',
            'action' => 'ai_model_profile.updated',
            'target_type' => 'ai_model_profile',
            'target_id' => $payload['id'],
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'payload' => json_encode([
                'profile_key' => $payload['profile_key'],
                'display_name' => $payload['display_name'],
                'provider_key' => $payload['provider_key'],
                'model_name' => $payload['model_name'],
                'runtime_profile' => $payload['runtime_profile'],
                'max_context' => $payload['max_context'],
                'max_output_tokens' => $payload['max_output_tokens'],
                'temperature' => $payload['temperature'],
                'timeout_seconds' => $payload['timeout_seconds'],
                'enabled' => $payload['enabled'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response()->json(['model_profile' => $payload]);
    }

    public function storeModelProfile(Request $request, AiAgentRegistry $registry): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $request->validate([
            'provider_key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'profile_key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'display_name' => ['required', 'string', 'max:120'],
            'model_name' => ['required', 'string', 'max:180'],
            'runtime_profile' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'max_context' => ['nullable', 'integer', 'min:1', 'max:2000000'],
            'max_output_tokens' => ['required', 'integer', 'min:1', 'max:200000'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'timeout_seconds' => ['required', 'integer', 'min:1', 'max:300'],
            'enabled' => ['required', 'boolean'],
        ]);

        try {
            $payload = $registry->createModelProfile($validated);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => $request->user()->id,
            'actor_device_id' => null,
            'actor_type' => 'user',
            'action' => 'ai_model_profile.created',
            'target_type' => 'ai_model_profile',
            'target_id' => $payload['id'],
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'payload' => json_encode([
                'profile_key' => $payload['profile_key'],
                'display_name' => $payload['display_name'],
                'provider_key' => $payload['provider_key'],
                'model_name' => $payload['model_name'],
                'runtime_profile' => $payload['runtime_profile'],
                'enabled' => $payload['enabled'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response()->json(['model_profile' => $payload], 201);
    }

    public function destroyModelProfile(Request $request, AiAgentRegistry $registry, string $profile): JsonResponse|Response
    {
        $this->abortUnlessAdmin($request);

        try {
            $payload = $registry->deleteModelProfile($profile);
        } catch (InvalidArgumentException) {
            abort(404);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => $request->user()->id,
            'actor_device_id' => null,
            'actor_type' => 'user',
            'action' => 'ai_model_profile.deleted',
            'target_type' => 'ai_model_profile',
            'target_id' => $payload['id'],
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'payload' => json_encode([
                'profile_key' => $payload['profile_key'],
                'display_name' => $payload['display_name'],
                'provider_key' => $payload['provider_key'],
                'model_name' => $payload['model_name'],
                'unassigned_agent_keys' => $payload['unassigned_agent_keys'] ?? [],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response()->noContent();
    }

    public function storeAgentProfile(Request $request, AiAgentRegistry $registry): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $request->validate($this->agentProfileRules());

        try {
            $payload = $registry->createAgentProfile($validated);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => $request->user()->id,
            'actor_device_id' => null,
            'actor_type' => 'user',
            'action' => 'ai_agent_profile.created',
            'target_type' => 'ai_agent_profile',
            'target_id' => $payload['id'],
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'payload' => json_encode([
                'agent_key' => $payload['agent_key'],
                'display_name' => $payload['display_name'],
                'description' => $payload['description'],
                'agent_type' => $payload['agent_type'],
                'delegation_mode' => $payload['delegation_mode'],
                'parent_agent_key' => $payload['parent_agent_key'],
                'default_model_profile_id' => $payload['default_model_profile_id'],
                'requires_human_approval' => $payload['requires_human_approval'],
                'enabled' => $payload['enabled'],
                'allowed_tools' => $payload['allowed_tools'],
                'output_schema' => $payload['output_schema'],
                'trigger_events' => $payload['trigger_events'],
                'visibility_scope' => $payload['visibility_scope'],
                'project_ids' => $payload['project_ids'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response()->json(['agent_profile' => $payload], 201);
    }

    public function replaceAgentProfile(Request $request, AiAgentRegistry $registry, string $agent): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $request->validate($this->agentProfileRules(false));

        try {
            $payload = $registry->replaceAgentProfile($agent, $validated);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => $request->user()->id,
            'actor_device_id' => null,
            'actor_type' => 'user',
            'action' => 'ai_agent_profile.replaced',
            'target_type' => 'ai_agent_profile',
            'target_id' => $payload['id'],
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'payload' => json_encode([
                'agent_key' => $payload['agent_key'],
                'display_name' => $payload['display_name'],
                'description' => $payload['description'],
                'agent_type' => $payload['agent_type'],
                'delegation_mode' => $payload['delegation_mode'],
                'parent_agent_key' => $payload['parent_agent_key'],
                'default_model_profile_id' => $payload['default_model_profile_id'],
                'requires_human_approval' => $payload['requires_human_approval'],
                'enabled' => $payload['enabled'],
                'allowed_tools' => $payload['allowed_tools'],
                'output_schema' => $payload['output_schema'],
                'trigger_events' => $payload['trigger_events'],
                'visibility_scope' => $payload['visibility_scope'],
                'project_ids' => $payload['project_ids'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response()->json(['agent_profile' => $payload]);
    }

    public function destroyAgentProfile(Request $request, AiAgentRegistry $registry, string $agent): Response
    {
        $this->abortUnlessAdmin($request);

        try {
            $payload = $registry->deleteAgentProfile($agent);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => $request->user()->id,
            'actor_device_id' => null,
            'actor_type' => 'user',
            'action' => 'ai_agent_profile.deleted',
            'target_type' => 'ai_agent_profile',
            'target_id' => $payload['id'],
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'payload' => json_encode([
                'agent_key' => $payload['agent_key'],
                'display_name' => $payload['display_name'],
                'description' => $payload['description'],
                'agent_type' => $payload['agent_type'],
                'delegation_mode' => $payload['delegation_mode'],
                'parent_agent_key' => $payload['parent_agent_key'],
                'default_model_profile_id' => $payload['default_model_profile_id'],
                'requires_human_approval' => $payload['requires_human_approval'],
                'enabled' => $payload['enabled'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response()->noContent();
    }

    public function validateProvider(Request $request, AiAgentRegistry $registry, string $provider): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        try {
            $validation = $registry->validateProvider($provider);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => $request->user()->id,
            'actor_device_id' => null,
            'actor_type' => 'user',
            'action' => 'ai_model_provider.validated',
            'target_type' => 'ai_model_provider',
            'target_id' => $validation['provider_key'],
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'payload' => json_encode([
                'provider_key' => $validation['provider_key'],
                'status' => $validation['status'],
                'checks' => $validation['checks'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response()->json(['validation' => $validation]);
    }

    public function providerModels(Request $request, AiAgentRegistry $registry, string $provider): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        try {
            $result = $registry->getProviderModels($provider);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return response()->json($result);
    }

    public function updateAgentProfile(Request $request, AiAgentRegistry $registry, string $agent): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $request->validate([
            'default_model_profile_id' => ['nullable', 'string', 'exists:ai_model_profiles,id'],
            'enabled' => ['required', 'boolean'],
        ]);

        try {
            $payload = $registry->updateAgentProfile($agent, $validated);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => $request->user()->id,
            'actor_device_id' => null,
            'actor_type' => 'user',
            'action' => 'ai_agent_profile.updated',
            'target_type' => 'ai_agent_profile',
            'target_id' => $payload['id'],
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'payload' => json_encode([
                'agent_key' => $payload['agent_key'],
                'default_model_profile_id' => $payload['default_model_profile_id'],
                'enabled' => $payload['enabled'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return response()->json(['agent_profile' => $payload]);
    }

    private function abortUnlessAdmin(Request $request): void
    {
        abort_unless($this->userHasRole($request->user(), 'Admin'), 403);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function agentProfileRules(bool $includeAgentKey = true): array
    {
        $rules = [
            'display_name' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:2000'],
            'agent_type' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'delegation_mode' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'parent_agent_key' => ['nullable', 'string', 'exists:ai_agent_profiles,agent_key'],
            'default_model_profile_id' => ['nullable', 'string', 'exists:ai_model_profiles,id'],
            'requires_human_approval' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
            'allowed_tools' => ['sometimes', 'array'],
            'allowed_tools.*' => ['string', 'max:120', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'output_schema' => ['sometimes', 'array'],
            'trigger_events' => ['sometimes', 'array'],
            'trigger_events.*' => ['string', 'max:120', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'visibility_scope' => ['sometimes', 'string', Rule::in(['global', 'project'])],
            'project_ids' => ['sometimes', 'array'],
            'project_ids.*' => ['string', 'exists:projects,id'],
        ];

        if ($includeAgentKey) {
            $rules['agent_key'] = ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_.-]*$/', 'unique:ai_agent_profiles,agent_key'];
        }

        return $rules;
    }
}
