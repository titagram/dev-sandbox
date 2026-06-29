<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Assistants\BacklogTriageService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Projects\ProjectLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BacklogTriageController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(
        Request $request,
        BacklogTriageService $triage,
        ProjectLifecycleService $lifecycle,
        string $project
    ): JsonResponse {
        abort_unless(
            $this->userHasRole($request->user(), 'Admin')
            || $this->userHasRole($request->user(), 'PM'),
            403,
        );

        if ($error = $lifecycle->assertProjectActiveForDashboard($project)) {
            return $error;
        }

        return response()->json($triage->triage($project, $request->user()->id), 201);
    }
}
