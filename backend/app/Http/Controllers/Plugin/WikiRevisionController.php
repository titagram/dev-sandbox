<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Services\WikiRevisionException;
use App\Services\WikiRevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class WikiRevisionController extends Controller
{
    public function __construct(private readonly WikiRevisionService $wiki)
    {
    }

    public function __invoke(Request $request, string $run): JsonResponse
    {
        abort_unless(DB::table('runs')->where('id', $run)->exists(), 404);

        $validated = $request->validate([
            'project_id' => ['required', 'string', 'exists:projects,id'],
            'repository_id' => ['nullable', 'string', 'exists:repositories,id'],
            'slug' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'page_type' => ['required', 'string', 'in:business,technical,runbook,audit'],
            'producer' => ['required', 'string', 'in:human,plugin,analyzer,ai'],
            'source_type' => ['required', 'string'],
            'source_status' => ['required', 'string'],
            'content_markdown' => ['required', 'string'],
            'evidence_refs' => ['nullable', 'array'],
        ]);

        $auth = $request->attributes->get('plugin_auth');

        try {
            $result = $this->wiki->write(
                array_merge($validated, ['evidence_refs' => $validated['evidence_refs'] ?? []]),
                null,
                $auth['token']->device_id,
            );
        } catch (WikiRevisionException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json($result);
    }
}
