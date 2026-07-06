<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'protocol_version' => 'v1',
            'service' => 'hades-backend',
            'status' => 'ok',
            'routes' => [
                'health' => '/api/hades/v1/health',
                'token_verify' => '/api/hades/v1/token/verify',
                'agents_register' => '/api/hades/v1/agents/register',
                'capabilities' => '/api/hades/v1/capabilities',
                'artifacts' => '/api/hades/v1/artifacts',
                'bug_reports' => '/api/hades/v1/bug-reports',
                'project_awareness_status' => '/api/hades/v1/project-awareness/status',
                'bug_evidence_search' => '/api/hades/v1/bug-evidence/search',
                'source_slices' => '/api/hades/v1/source-slices',
                'doctor_reports' => '/api/hades/v1/doctor/reports',
                'persephone_inbox' => '/api/hades/v1/persephone/inbox',
            ],
            'server_time' => now()->toISOString(),
        ]);
    }
}
