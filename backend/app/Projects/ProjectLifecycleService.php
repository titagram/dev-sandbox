<?php

namespace App\Projects;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ProjectLifecycleService
{
    public const ACTIVE = 'active';

    public const ARCHIVED = 'archived';

    public const DELETED = 'deleted';

    private const TERMINAL_RUN_STATUSES = ['finished', 'failed', 'aborted'];

    private const ACTIVE_UPLOAD_STATUSES = ['uploading', 'active', 'started', 'queued', 'running'];

    public function transition(string $projectId, string $action, User $actor, ?string $reason, Request $request): array|JsonResponse
    {
        $project = DB::table('projects')->where('id', $projectId)->first();
        abort_unless($project, Response::HTTP_NOT_FOUND);

        $targetStatus = match ($action) {
            'archive' => self::ARCHIVED,
            'delete' => self::DELETED,
            'restore' => self::ACTIVE,
            default => throw new \InvalidArgumentException("Unsupported lifecycle action [{$action}]."),
        };

        if (! $this->transitionAllowed((string) $project->status, $targetStatus)) {
            return $this->conflict('invalid_project_lifecycle_transition', 'Invalid project lifecycle transition.');
        }

        if (in_array($targetStatus, [self::ARCHIVED, self::DELETED], true)) {
            $activeWork = $this->activeWorkSummary($projectId);
            if ($activeWork['runs'] > 0 || $activeWork['uploads'] > 0) {
                return $this->conflict('project_lifecycle_blocked', 'Project has active work.', $activeWork);
            }
        }

        $now = now();
        $updates = [
            'status' => $targetStatus,
            'updated_at' => $now,
        ];

        if ($targetStatus === self::ARCHIVED) {
            $updates['archived_at'] = $now;
            $updates['archived_by_user_id'] = $actor->id;
        }

        if ($targetStatus === self::DELETED) {
            $updates['deleted_at'] = $now;
            $updates['deleted_by_user_id'] = $actor->id;
        }

        if ($targetStatus === self::ACTIVE) {
            $updates['restored_at'] = $now;
            $updates['restored_by_user_id'] = $actor->id;
        }

        DB::transaction(function () use ($project, $projectId, $updates, $action, $targetStatus, $actor, $reason, $request): void {
            DB::table('projects')->where('id', $projectId)->update($updates);

            app(AuditLogger::class)->record(
                match ($action) {
                    'archive' => 'project.archived',
                    'delete' => 'project.deleted',
                    'restore' => 'project.restored',
                },
                'project',
                $projectId,
                [
                    'project' => [
                        'id' => $projectId,
                        'slug' => (string) $project->slug,
                        'name' => (string) $project->name,
                    ],
                    'previous_status' => (string) $project->status,
                    'new_status' => $targetStatus,
                    'reason' => $reason,
                    'actor' => [
                        'id' => $actor->id,
                        'email' => $actor->email,
                    ],
                ],
                [
                    'type' => 'user',
                    'user_id' => $actor->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            );
        });

        return ['status' => $targetStatus];
    }

    /**
     * @return array{runs: int, uploads: int}
     */
    public function activeWorkSummary(string $projectId): array
    {
        $runs = DB::table('runs')
            ->where('project_id', $projectId)
            ->whereNotIn('status', self::TERMINAL_RUN_STATUSES)
            ->count();

        $genesis = DB::table('genesis_imports')
            ->where('project_id', $projectId)
            ->whereIn('status', self::ACTIVE_UPLOAD_STATUSES)
            ->count();

        $delta = DB::table('delta_syncs')
            ->where('project_id', $projectId)
            ->whereIn('status', self::ACTIVE_UPLOAD_STATUSES)
            ->count();

        return [
            'runs' => (int) $runs,
            'uploads' => (int) $genesis + (int) $delta,
        ];
    }

    public function assertProjectActiveForDashboard(string $projectId): ?JsonResponse
    {
        $project = DB::table('projects')->where('id', $projectId)->first();
        abort_unless($project, Response::HTTP_NOT_FOUND);

        if ((string) $project->status !== self::ACTIVE) {
            return $this->conflict('project_not_active', 'Project is not active.');
        }

        return null;
    }

    public function assertTaskProjectActive(string $taskId): ?JsonResponse
    {
        $task = DB::table('tasks')->where('id', $taskId)->first();
        abort_unless($task, Response::HTTP_NOT_FOUND);

        return $this->assertProjectActiveForDashboard((string) $task->project_id);
    }

    public function assertRunProjectActive(string $runId): ?JsonResponse
    {
        $run = DB::table('runs')->where('id', $runId)->first();
        abort_unless($run, Response::HTTP_NOT_FOUND);

        return $this->assertProjectActiveForDashboard((string) $run->project_id);
    }

    public function pluginProjectWriteGuard(string $projectId): ?JsonResponse
    {
        $project = DB::table('projects')->where('id', $projectId)->first();

        if (! $project || (string) $project->status === self::DELETED) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if ((string) $project->status === self::ARCHIVED) {
            return $this->conflict('project_archived', 'Project is archived and read-only.');
        }

        return null;
    }

    public function pluginRepositoryWriteGuard(string $repositoryId): ?JsonResponse
    {
        $repository = DB::table('repositories')->where('id', $repositoryId)->first();
        abort_unless($repository, Response::HTTP_NOT_FOUND);

        return $this->pluginProjectWriteGuard((string) $repository->project_id);
    }

    public function pluginRunWriteGuard(string $runId): ?JsonResponse
    {
        $run = DB::table('runs')->where('id', $runId)->first();
        abort_unless($run, Response::HTTP_NOT_FOUND);

        return $this->pluginProjectWriteGuard((string) $run->project_id);
    }

    public function pluginGenesisWriteGuard(string $genesisImportId): ?JsonResponse
    {
        $import = DB::table('genesis_imports')->where('id', $genesisImportId)->first();
        abort_unless($import, Response::HTTP_NOT_FOUND);

        return $this->pluginProjectWriteGuard((string) $import->project_id);
    }

    public function pluginDeltaWriteGuard(string $deltaSyncId): ?JsonResponse
    {
        $delta = DB::table('delta_syncs')->where('id', $deltaSyncId)->first();
        abort_unless($delta, Response::HTTP_NOT_FOUND);

        return $this->pluginProjectWriteGuard((string) $delta->project_id);
    }

    private function transitionAllowed(string $current, string $target): bool
    {
        return match ($current) {
            self::ACTIVE => in_array($target, [self::ARCHIVED, self::DELETED], true),
            self::ARCHIVED => in_array($target, [self::ACTIVE, self::DELETED], true),
            self::DELETED => $target === self::ACTIVE,
            default => false,
        };
    }

    private function conflict(string $code, string $message, array $details = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], Response::HTTP_CONFLICT);
    }
}
