<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Assistants\TaskClarifierService;
use App\Dashboard\DashboardApiReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Projects\ProjectLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AssistantSuggestionController extends Controller
{
    use ChecksDashboardRoles;

    public function update(Request $request, TaskClarifierService $clarifier, string $suggestion): JsonResponse
    {
        abort_unless(
            $this->userHasRole($request->user(), 'Admin')
            || $this->userHasRole($request->user(), 'PM'),
            403,
        );

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:accepted,rejected'],
        ]);

        return response()->json([
            'suggestion' => $clarifier->resolveSuggestion(
                $suggestion,
                $request->user()->id,
                $validated['status'],
            ),
        ]);
    }

    public function apply(
        Request $request,
        TaskClarifierService $clarifier,
        DashboardApiReader $reader,
        ProjectLifecycleService $lifecycle,
        string $suggestion
    ): JsonResponse {
        abort_unless(
            $this->userHasRole($request->user(), 'Admin')
            || $this->userHasRole($request->user(), 'PM'),
            403,
        );

        $suggestionRow = DB::table('assistant_suggestions')
            ->where('id', $suggestion)
            ->where('suggestion_type', 'task_clarification')
            ->where('target_type', 'task')
            ->first();

        abort_unless($suggestionRow, 404);

        if ($error = $lifecycle->assertTaskProjectActive((string) $suggestionRow->target_id)) {
            return $error;
        }

        $result = $clarifier->applySuggestion($suggestion, $request->user()->id);

        return response()->json([
            'suggestion' => $result['suggestion'],
            'task' => $reader->task($result['task_id']),
        ]);
    }
}
