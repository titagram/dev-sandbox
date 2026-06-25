<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Dashboard\DashboardApiReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Projects\ProjectLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DashboardProjectRepositoryController extends Controller
{
    use ChecksDashboardRoles;

    public function store(Request $request, DashboardApiReader $reader, ProjectLifecycleService $lifecycle, string $project): JsonResponse
    {
        abort_unless(
            $this->userHasRole($request->user(), 'Admin') || $this->userHasRole($request->user(), 'PM'),
            403,
        );

        if ($error = $lifecycle->assertProjectActiveForDashboard($project)) {
            return $error;
        }

        $payload = $this->validatedRepositoryPayload($request, $project);
        $repositoryId = (string) Str::ulid();
        $now = now();

        DB::transaction(function () use ($payload, $project, $repositoryId, $request, $now): void {
            DB::table('repositories')->insert([
                'id' => $repositoryId,
                'project_id' => $project,
                'name' => $payload['name'],
                'slug' => $payload['slug'],
                'default_branch' => $payload['default_branch'],
                'local_only' => true,
                'code_exposure_policy' => 'metadata_only',
                'protected_paths' => json_encode($payload['protected_paths'], JSON_THROW_ON_ERROR),
                'excluded_paths' => json_encode($payload['excluded_paths'], JSON_THROW_ON_ERROR),
                'stack_hints' => json_encode($payload['stack_hints'], JSON_THROW_ON_ERROR),
                'graph_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('audit_logs')->insert([
                'id' => (string) Str::ulid(),
                'actor_user_id' => $request->user()->id,
                'actor_device_id' => null,
                'actor_type' => 'user',
                'action' => 'repository.declared',
                'target_type' => 'repository',
                'target_id' => $repositoryId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'payload' => json_encode([
                    'project_id' => $project,
                    'repository' => [
                        'id' => $repositoryId,
                        'key' => $payload['slug'],
                        'name' => $payload['name'],
                        'default_branch' => $payload['default_branch'],
                        'local_only' => true,
                        'code_exposure_policy' => 'metadata_only',
                        'protected_paths' => $payload['protected_paths'],
                        'excluded_paths' => $payload['excluded_paths'],
                        'stack_hints' => $payload['stack_hints'],
                    ],
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        });

        return response()->json($reader->project($project), 201);
    }

    /**
     * @return array{name: string, slug: string, default_branch: string, protected_paths: list<string>, excluded_paths: list<string>, stack_hints: list<string>}
     */
    private function validatedRepositoryPayload(Request $request, string $project): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'key' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'default_branch' => ['nullable', 'string', 'max:255'],
            'protected_paths' => ['nullable', 'array'],
            'protected_paths.*' => ['string', 'max:1024'],
            'excluded_paths' => ['nullable', 'array'],
            'excluded_paths.*' => ['string', 'max:1024'],
            'stack_hints' => ['nullable', 'array'],
            'stack_hints.*' => ['string', 'max:128'],
        ]);

        $name = (string) $validated['name'];
        $slug = (string) ($validated['key'] ?? Str::slug($name));

        if ($slug === '') {
            throw ValidationException::withMessages([
                'key' => 'Repository key must contain at least one letter or number.',
            ]);
        }

        $exists = DB::table('repositories')
            ->where('project_id', $project)
            ->where('slug', $slug)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'key' => 'Repository key is already in use for this project.',
            ]);
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'default_branch' => (string) ($validated['default_branch'] ?? 'main'),
            'protected_paths' => array_values($validated['protected_paths'] ?? []),
            'excluded_paths' => array_values($validated['excluded_paths'] ?? []),
            'stack_hints' => array_values($validated['stack_hints'] ?? []),
        ];
    }
}
