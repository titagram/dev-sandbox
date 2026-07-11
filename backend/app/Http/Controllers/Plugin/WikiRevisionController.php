<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use App\Services\PluginInvariantService;
use App\Services\PluginProjectScope;
use App\Services\WikiRevisionException;
use App\Services\WikiRevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class WikiRevisionController extends Controller
{
    public function __construct(
        private readonly WikiRevisionService $wiki,
        private readonly ProjectLifecycleService $lifecycle,
        private readonly PluginInvariantService $invariants,
        private readonly PluginProjectScope $projectScope,
    ) {}

    public function __invoke(Request $request, string $run): JsonResponse
    {
        $runRow = DB::table('runs')->where('id', $run)->first();
        abort_unless($runRow, Response::HTTP_NOT_FOUND);

        if ($error = $this->projectScope->authorize($request, (string) $runRow->project_id)) {
            return $error;
        }

        if ($error = $this->lifecycle->pluginRunWriteGuard($run)) {
            return $error;
        }

        if ($error = $this->invariants->assertRunOwnership($request, $runRow)) {
            return $error;
        }

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

        if ($error = $this->lifecycle->pluginProjectWriteGuard($validated['project_id'])) {
            return $error;
        }

        $repository = isset($validated['repository_id'])
            ? DB::table('repositories')->where('id', $validated['repository_id'])->first()
            : null;
        if ($error = $this->invariants->assertReferences(
            (string) $runRow->project_id === (string) $validated['project_id']
                && (! $repository || ((string) $repository->project_id === (string) $validated['project_id']
                    && (string) $repository->id === (string) $runRow->repository_id)),
            'Wiki project, repository, and run references are inconsistent.',
        )) {
            return $error;
        }

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
