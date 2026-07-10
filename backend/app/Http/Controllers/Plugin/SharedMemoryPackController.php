<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Projects\ProjectLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SharedMemoryPackController extends Controller
{
    public function __construct(private readonly ProjectLifecycleService $lifecycle) {}

    public function __invoke(Request $request, string $project): JsonResponse
    {
        if ($error = $this->lifecycle->pluginProjectWriteGuard($project)) {
            return $error;
        }

        $validated = $request->validate([
            'repository_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('repositories', 'id')->where(fn ($query) => $query->where('project_id', $project)),
            ],
        ]);

        $repositoryId = $validated['repository_id'] ?? null;

        $entries = DB::table('project_memory_entries')
            ->where('project_id', $project)
            ->when($repositoryId !== null, function ($query) use ($repositoryId): void {
                $query->where(function ($query) use ($repositoryId): void {
                    $query->whereNull('repository_id')
                        ->orWhere('repository_id', $repositoryId);
                });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (object $entry): array => $this->memoryEntry($entry))
            ->all();

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $project,
            'repository_id' => $repositoryId,
            'entries' => $entries,
            'generated_at' => now()->toJSON(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function memoryEntry(object $entry): array
    {
        return [
            'id' => (string) $entry->id,
            'project_id' => (string) $entry->project_id,
            'repository_id' => $entry->repository_id ? (string) $entry->repository_id : null,
            'task_id' => $entry->task_id ? (string) $entry->task_id : null,
            'run_id' => $entry->run_id ? (string) $entry->run_id : null,
            'agent_key' => $entry->agent_key ? (string) $entry->agent_key : null,
            'source' => (string) $entry->source,
            'kind' => (string) $entry->kind,
            'completeness' => (string) $entry->completeness,
            'summary' => (string) $entry->summary,
            'payload' => json_decode((string) $entry->payload, true, flags: JSON_THROW_ON_ERROR),
            'occurred_at' => (string) $entry->occurred_at,
        ];
    }
}
