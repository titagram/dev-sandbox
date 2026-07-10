<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Services\Hades\IntakeNormalizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IntakeNormalizerController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request, IntakeNormalizerService $normalizer, string $project): JsonResponse
    {
        abort_unless(
            $this->userHasRole($request->user(), 'Admin')
            || $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer'),
            403,
        );

        $validated = $request->validate([
            'raw_text' => ['required', 'string', 'min:5', 'max:5000'],
        ]);

        $result = $normalizer->normalize($validated['raw_text'], $project);

        return response()->json([
            'project_id' => $project,
            'normalization' => $result,
        ]);
    }
}
