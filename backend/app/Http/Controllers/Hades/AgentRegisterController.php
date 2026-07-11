<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\Hades\HadesCapabilityPolicy;
use App\Services\Hades\HadesTokenException;
use App\Services\Hades\HadesTokenService;
use App\Services\Hades\HadesPluginCredentialIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentRegisterController extends Controller
{
    public function __construct(
        private readonly HadesTokenService $tokens,
        private readonly HadesCapabilityPolicy $capabilities,
        private readonly HadesPluginCredentialIssuer $pluginCredentials,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'agent_id' => ['required', 'string', 'max:191'],
            'label' => ['nullable', 'string', 'max:191'],
            'platform' => ['nullable', 'string', 'max:191'],
            'version' => ['nullable', 'string', 'max:191'],
            'capabilities' => ['nullable', 'array'],
            'plugin_device' => ['nullable', 'array'],
            'plugin_device.fingerprint_hash' => ['required_with:plugin_device', 'string', 'max:255'],
            'plugin_device.name' => ['required_with:plugin_device', 'string', 'max:255'],
            'plugin_device.platform_os' => ['required_with:plugin_device', 'string', 'max:64'],
            'plugin_device.platform_arch' => ['required_with:plugin_device', 'string', 'max:64'],
            'plugin_device.plugin_version' => ['required_with:plugin_device', 'string', 'max:64'],
        ]);

        try {
            $auth = $this->tokens->authenticateBootstrapToken(
                (string) $request->bearerToken(),
                $validated['project_id'],
            );
        } catch (HadesTokenException $exception) {
            return $exception->toResponse();
        }

        $now = now();
        $externalAgentId = $validated['agent_id'];
        $declaredCapabilities = $this->capabilities->normalizeNames($validated['capabilities'] ?? []);
        $allowedCapabilities = $this->capabilities->normalizeNames(
            json_decode($auth['token']->allowed_capabilities ?? '[]', true, 512, JSON_THROW_ON_ERROR) ?: [],
        );
        $effectiveCapabilities = $this->capabilities->intersect($declaredCapabilities, $allowedCapabilities);

        $agent = DB::transaction(function () use ($validated, $externalAgentId, $declaredCapabilities, $effectiveCapabilities, $now) {
            $existing = DB::table('hades_agents')
                ->where('project_id', $validated['project_id'])
                ->where('external_agent_id', $externalAgentId)
                ->first();

            $attributes = [
                'label' => $validated['label'] ?? $externalAgentId,
                'platform' => $validated['platform'] ?? 'unknown',
                'version' => $validated['version'] ?? 'unknown',
                'declared_capabilities' => json_encode($declaredCapabilities, JSON_THROW_ON_ERROR),
                'effective_capabilities' => json_encode($effectiveCapabilities, JSON_THROW_ON_ERROR),
                'last_seen_at' => $now,
                'status' => 'active',
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('hades_agents')->where('id', $existing->id)->update($attributes);

                return DB::table('hades_agents')->where('id', $existing->id)->first();
            }

            $id = (string) Str::ulid();

            DB::table('hades_agents')->insert(array_merge($attributes, [
                'id' => $id,
                'project_id' => $validated['project_id'],
                'external_agent_id' => $externalAgentId,
                'created_at' => $now,
            ]));

            return DB::table('hades_agents')->where('id', $id)->first();
        });

        $agentToken = $this->tokens->createAgentToken($agent);
        $capabilityMap = $this->capabilities->toMap($effectiveCapabilities);
        $pluginCredentials = isset($validated['plugin_device'])
            ? $this->pluginCredentials->issue($agent, $validated['plugin_device'])
            : null;

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $agent->project_id,
            'agent_id' => $agent->external_agent_id,
            'backend_agent_id' => $agent->id,
            'external_agent_id' => $agent->external_agent_id,
            'agent_token_id' => $agentToken['id'],
            'agent_token' => $agentToken['plain_token'],
            'plugin_credentials' => $pluginCredentials,
            'capabilities' => $capabilityMap,
            'capability_names' => array_values($effectiveCapabilities),
            'policy' => $this->capabilities->m1Policy(),
            'server_time' => now()->toISOString(),
        ]);
    }
}
