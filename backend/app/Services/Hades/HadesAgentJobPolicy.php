<?php

namespace App\Services\Hades;

use Symfony\Component\HttpFoundation\Response;

class HadesAgentJobPolicy
{
    /** @var array<string, list<string>> */
    private const STATUS_TRANSITIONS = [
        'queued' => ['received', 'failed', 'expired'],
        'received' => ['waiting_confirmation', 'started', 'failed', 'expired'],
        'waiting_confirmation' => ['cancelled', 'expired'],
        'started' => ['failed', 'expired'],
    ];

    /** @return list<string> */
    public function effectiveCapabilities(object $agent): array
    {
        $decoded = is_string($agent->effective_capabilities ?? null)
            ? json_decode($agent->effective_capabilities, true)
            : ($agent->effective_capabilities ?? []);

        return array_values(array_unique(array_filter(
            is_array($decoded) ? $decoded : [],
            fn (mixed $capability): bool => is_string($capability) && $capability !== '',
        )));
    }

    public function assertCapability(object $agent, object $job): void
    {
        if (! in_array((string) $job->capability, $this->effectiveCapabilities($agent), true)) {
            throw new HadesJobException(
                'job_capability_not_allowed',
                'The job capability is not enabled for this Hades agent.',
                Response::HTTP_FORBIDDEN,
            );
        }
    }

    public function assertStatusTransition(object $job, string $nextStatus): void
    {
        $currentStatus = (string) $job->status;
        if (! in_array($nextStatus, self::STATUS_TRANSITIONS[$currentStatus] ?? [], true)) {
            throw new HadesJobException(
                'job_transition_invalid',
                "Hades job cannot transition from {$currentStatus} to {$nextStatus}.",
                Response::HTTP_CONFLICT,
            );
        }

        if ($nextStatus === 'started' && $currentStatus === 'received' && $this->requiresConfirmation($job)) {
            throw new HadesJobException(
                'job_confirmation_required',
                'The Hades job must enter waiting_confirmation before it can be started.',
                Response::HTTP_CONFLICT,
            );
        }

        if ($nextStatus !== 'expired' && $job->deadline_at !== null && now()->isAfter($job->deadline_at)) {
            throw new HadesJobException(
                'job_deadline_expired',
                'The Hades job deadline has expired.',
                Response::HTTP_CONFLICT,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function assertResult(object $job, string $status, array $result): void
    {
        $currentStatus = (string) $job->status;
        if ($currentStatus !== 'started' && $this->resultPreparationStatuses($job) === []) {
            throw new HadesJobException(
                'job_transition_invalid',
                "Hades job cannot transition from {$currentStatus} to {$status} through a result.",
                Response::HTTP_CONFLICT,
            );
        }

        if ($job->deadline_at !== null && now()->isAfter($job->deadline_at)) {
            throw new HadesJobException(
                'job_deadline_expired',
                'The Hades job deadline has expired.',
                Response::HTTP_CONFLICT,
            );
        }

        if (isset($result['status']) && $result['status'] !== $status) {
            throw new HadesJobException(
                'job_result_status_mismatch',
                'The result status does not match the submitted job status.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $jobType = (string) ($job->job_type ?? '');
        if ($jobType === 'wiki_refresh' && $job->capability !== 'populate_project_wiki') {
            throw new HadesJobException(
                'job_type_capability_mismatch',
                'Wiki refresh jobs must use the populate_project_wiki capability.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($status === 'failed') {
            return;
        }

        $schema = $result['schema'] ?? null;
        $artifactSchema = is_array($result['artifact'] ?? null) ? ($result['artifact']['schema'] ?? null) : null;
        $expectedSchemas = match ((string) $job->capability) {
            'populate_project_wiki' => ['devboard.wiki_refresh_result.v1'],
            'sync_git_tree', 'project_inspection' => ['hades.git_tree.v1'],
            'populate_backend_ast' => ['hades.symbols.v1', 'hades.php_graph.v1', 'hades.code_graph.v1'],
            default => [],
        };

        if ($jobType === 'wiki_refresh' || $job->capability === 'populate_project_wiki') {
            if ($schema !== 'devboard.wiki_refresh_result.v1') {
                throw new HadesJobException(
                    'job_result_schema_mismatch',
                    'Wiki refresh results must use schema devboard.wiki_refresh_result.v1.',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            return;
        }

        if ($expectedSchemas !== [] && ! in_array($artifactSchema, $expectedSchemas, true)) {
            throw new HadesJobException(
                'job_result_schema_mismatch',
                'The result artifact schema does not match the Hades job capability.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($schema !== null && $expectedSchemas === []) {
            throw new HadesJobException(
                'job_result_schema_mismatch',
                'This Hades job capability does not accept a typed result schema.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }

    /** @return list<string> */
    public function resultPreparationStatuses(object $job): array
    {
        if (! $this->allowsDirectWikiResult($job)) {
            return [];
        }

        return match ((string) $job->status) {
            'queued' => ['received', 'started'],
            'received' => ['started'],
            default => [],
        };
    }

    public function requiresConfirmation(object $job): bool
    {
        return (bool) $job->requires_confirmation
            || in_array((string) $job->policy, ['confirm', 'manual', 'approval_required'], true);
    }

    private function allowsDirectWikiResult(object $job): bool
    {
        return $job->capability === 'populate_project_wiki'
            && ($job->job_type ?? null) === 'wiki_refresh'
            && ! $this->requiresConfirmation($job);
    }
}
