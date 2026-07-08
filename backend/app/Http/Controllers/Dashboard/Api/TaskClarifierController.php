<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Assistants\TaskClarifierService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskClarifierController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request, TaskClarifierService $clarifier, string $task): JsonResponse
    {
        abort_unless(
            $this->userHasRole($request->user(), 'Admin')
            || $this->userHasRole($request->user(), 'PM'),
            403,
        );

        return response()->json($clarifier->clarify($task, $request->user()->id), 201);
    }
}
