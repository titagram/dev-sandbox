<?php

namespace App\Http\Controllers\Dashboard;

use App\Assistants\AiAgentRegistry;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class AiAgentsIndexController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request, AiAgentRegistry $registry): Response
    {
        abort_unless($this->userHasRole($request->user(), 'Admin'), 403);

        $project = DB::table('projects')->orderBy('created_at')->first();
        $snapshot = $registry->snapshot();

        return Inertia::render('Admin/AiAgents', [
            ...$snapshot,
            'project' => $project,
            'dashboard' => [
                'user' => $this->dashboardUser($request->user()),
                'navigation' => $this->dashboardNavigation($request->user(), $project?->id),
            ],
        ]);
    }
}
