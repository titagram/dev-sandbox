<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\Hades\HadesCapabilityPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapabilitiesController extends Controller
{
    public function __construct(private readonly HadesCapabilityPolicy $capabilities)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $effectiveCapabilities = json_decode($agent->effective_capabilities, true, 512, JSON_THROW_ON_ERROR) ?: [];

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $agent->project_id,
            'agent_id' => $agent->external_agent_id,
            'backend_agent_id' => $agent->id,
            'external_agent_id' => $agent->external_agent_id,
            'capabilities' => $this->capabilities->toMap($effectiveCapabilities),
            'capability_names' => array_values($effectiveCapabilities),
            'limits' => $this->capabilities->m1Limits(),
            'policy' => $this->capabilities->m1Policy(),
            'routes' => [
                'health' => '/api/hades/v1/health',
                'token_verify' => '/api/hades/v1/token/verify',
                'agents_register' => '/api/hades/v1/agents/register',
                'capabilities' => '/api/hades/v1/capabilities',
            ],
            'server_time' => now()->toISOString(),
        ]);
    }
}
