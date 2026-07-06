<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\Hades\HadesCapabilityPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapabilitiesController extends Controller
{
    public function __construct(private readonly HadesCapabilityPolicy $capabilities) {}

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
                'workspaces_bind' => '/api/hades/v1/workspaces/bind',
                'workspaces_unlink' => '/api/hades/v1/workspaces/{workspace_binding_id}/unlink',
                'memory_snapshot' => '/api/hades/v1/memory/snapshot',
                'memory_proposals' => '/api/hades/v1/memory/proposals',
                'project_awareness_status' => '/api/hades/v1/project-awareness/status',
                'bug_reports' => '/api/hades/v1/bug-reports',
                'bug_report' => '/api/hades/v1/bug-reports/{bug_report_id}',
                'bug_evidence' => '/api/hades/v1/bug-evidence',
                'bug_evidence_search' => '/api/hades/v1/bug-evidence/search',
                'source_slices' => '/api/hades/v1/source-slices',
                'agent_jobs' => '/api/hades/v1/agent/jobs',
                'agent_job_status' => '/api/hades/v1/agent/jobs/{job_id}/status',
                'agent_job_result' => '/api/hades/v1/agent/jobs/{job_id}/result',
                'artifacts' => '/api/hades/v1/artifacts',
                'doctor_reports' => '/api/hades/v1/doctor/reports',
                'persephone_inbox' => '/api/hades/v1/persephone/inbox',
                'persephone_events' => '/api/hades/v1/persephone/events',
                'persephone_messages' => '/api/hades/v1/persephone/messages',
            ],
            'server_time' => now()->toISOString(),
        ]);
    }
}
