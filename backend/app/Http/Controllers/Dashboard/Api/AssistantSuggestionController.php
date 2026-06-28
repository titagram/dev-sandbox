<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Assistants\TaskClarifierService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
