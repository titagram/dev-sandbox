<?php

namespace App\Services\Hades;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class HadesKanbanTaskIntakeService
{
    public function __construct(
        private readonly HadesSearchDocumentIndexer $indexer,
        private readonly HadesEvidencePolicy $policy,
    ) {}

    /**
     * Normalize raw free-text input into a structured preview without persisting anything.
     *
     * @return array{task_type: string, suggested_title: string, suggested_description: string, clarifying_questions: list<string>, requires_root_cause: bool, confidence: float, execution_mode: string}
     */
    public function normalizeFreeText(string $rawText, bool $projectContextKnown = false): array
    {
        $rawText = trim($rawText);
        $haystack = mb_strtolower($rawText);
        $isBug = str_contains($haystack, 'bug')
            || str_contains($haystack, 'fix')
            || str_contains($haystack, 'errore')
            || str_contains($haystack, 'error')
            || str_contains($haystack, 'exception')
            || str_contains($haystack, 'stack trace')
            || str_contains($haystack, 'stacktrace')
            || str_contains($haystack, 'http 500')
            || str_contains($haystack, 'crash')
            || str_contains($haystack, 'failure')
            || str_contains($haystack, 'failing')
            || str_contains($haystack, 'intermittent')
            || str_contains($haystack, 'regression')
            || str_contains($haystack, 'root cause')
            || str_contains($haystack, 'diagnose')
            || str_contains($haystack, 'diagnosi');
        $isAnalysis = ! $isBug && (str_contains($haystack, 'analyze')
            || str_contains($haystack, 'analysis')
            || str_contains($haystack, 'analisi')
            || str_contains($haystack, 'investigate')
            || str_contains($haystack, 'inspect'));
        $isFeature = ! $isBug && ! $isAnalysis && (str_contains($haystack, 'feature')
            || str_contains($haystack, 'add')
            || str_contains($haystack, 'implement')
            || str_contains($haystack, 'create')
            || str_contains($haystack, 'build')
            || str_contains($haystack, 'new'));
        $isQuestion = ! $isBug && ! $isAnalysis && ! $isFeature && (str_contains($rawText, '?')
            || str_contains($haystack, 'how')
            || str_contains($haystack, 'what')
            || str_contains($haystack, 'why')
            || str_contains($haystack, 'explain')
            || str_contains($haystack, 'documentation'));
        $taskType = $isBug ? "bug" : ($isFeature ? "feature" : ($isQuestion ? "question" : "task"));
        $questions = [];
        $lines = array_values(array_filter(explode("\n", $rawText), fn (string $line): bool => trim($line) !== ''));
        $firstLine = $lines[0] ?? $rawText;
        $title = $this->compact($firstLine, 180);
        $description = count($lines) > 1
            ? $this->compact(implode("\n", array_slice($lines, 1)), 1800)
            : '';
        $textLen = mb_strlen($rawText);

        if ($textLen < 20) {
            $questions[] = 'What observable symptom, workflow, or expected behavior should the team verify?';
        }

        if ($textLen < 60) {
            $questions[] = 'Which acceptance criteria prove that the work is complete?';
        }

        if (! $projectContextKnown && ! str_contains($haystack, "project") && ! str_contains($haystack, "repo")) {
            $questions[] = 'Which project or repository does this apply to?';
        }

        if ($isBug && ! str_contains($haystack, 'steps') && ! str_contains($haystack, 'reproduce')) {
            $questions[] = 'What are the reproduction steps for this bug?';
        }

        if ($isBug && ! str_contains($haystack, 'version') && ! str_contains($haystack, 'branch') && ! str_contains($haystack, 'commit')) {
            $questions[] = 'Which version, branch, or commit is affected?';
        }

        return [
            'task_type' => $taskType,
            'suggested_title' => $title,
            'suggested_description' => $description !== '' ? $description : $rawText,
            'clarifying_questions' => $questions,
            "requires_root_cause" => str_contains($haystack, "root cause") || str_contains($haystack, "diagnose") || str_contains($haystack, "diagnosi"),
            'confidence' => $questions === [] ? 0.70 : 0.40,
            'execution_mode' => 'deterministic_fallback',
        ];
    }

    public function queueLocalAgentWorkForTask(string $taskId, string $projectId, int $userId): ?string
    {
        $existingId = DB::table('agent_work_items')
            ->where('task_id', $taskId)
            ->where('assigned_agent_key', 'local_agent')
            ->whereIn('status', ['draft', 'queued', 'claimed', 'running'])
            ->value('id');

        if ($existingId) {
            return (string) $existingId;
        }

        $task = DB::table('tasks')->where('id', $taskId)->first();

        if (! $task) {
            return null;
        }

        $repositoryId = DB::table('repository_task')
            ->where('task_id', $taskId)
            ->orderBy('created_at')
            ->value('repository_id');
        $acceptanceCriteria = $this->acceptanceCriteria($task);
        $normalization = $this->normalizeTask($task, $repositoryId ? (string) $repositoryId : null, $acceptanceCriteria);

        if (! $normalization['ready_for_agent_work']) {
            return null;
        }

        $bugIntake = $this->createBugIntakeIfNeeded(
            task: $task,
            projectId: $projectId,
            repositoryId: $repositoryId ? (string) $repositoryId : null,
            acceptanceCriteria: $acceptanceCriteria,
            normalization: $normalization,
        );
        $workItemId = (string) Str::ulid();
        $now = now();
        $payload = $this->workPayload(
            task: $task,
            projectId: $projectId,
            repositoryId: $repositoryId ? (string) $repositoryId : null,
            acceptanceCriteria: $acceptanceCriteria,
            normalization: $normalization,
            bugIntake: $bugIntake,
            userId: $userId,
            now: $now,
        );
        $safeTitle = $this->policy->redactTextMaterial((string) $task->title)['text'];

        DB::table('agent_work_items')->insert([
            'id' => $workItemId,
            'project_id' => $projectId,
            'repository_id' => $repositoryId,
            'task_id' => $taskId,
            'requested_by_user_id' => $userId,
            'assigned_agent_key' => 'local_agent',
            'status' => 'queued',
            'priority' => (string) $task->priority,
            'title' => $this->compact((string) $safeTitle, 191),
            'prompt' => $this->localAgentPromptForTask($task, $acceptanceCriteria, $normalization, $bugIntake),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'requires_memory_entry' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('agent_work_item_events')->insert([
            'id' => (string) Str::ulid(),
            'agent_work_item_id' => $workItemId,
            'actor_user_id' => $userId,
            'actor_device_id' => null,
            'event_type' => 'queued_from_kanban_task',
            'message' => 'Dashboard task queued for the local Hades agent.',
            'payload' => json_encode([
                'task_id' => (string) $task->id,
                'schema' => 'hades.kanban_task_work.v1',
                'normalization' => $normalization,
                'bug_intake' => $bugIntake,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $workItemId;
    }

    /**
     * @return list<string>
     */
    private function acceptanceCriteria(object $task): array
    {
        $decoded = json_decode((string) $task->acceptance_criteria, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $criterion): string => trim((string) $criterion), $decoded),
            fn (string $criterion): bool => $criterion !== '',
        ));
    }

    /**
     * @param  list<string>  $acceptanceCriteria
     * @return array<string, mixed>
     */
    private function normalizeTask(object $task, ?string $repositoryId, array $acceptanceCriteria): array
    {
        $title = trim((string) $task->title);
        $description = trim((string) ($task->description ?? ''));
        $haystack = Str::lower($title.' '.$description.' '.implode(' ', $acceptanceCriteria));
        $isBug = Str::contains($haystack, [
            'bug', 'fix', 'errore', 'error', 'exception', 'stack trace', 'stacktrace',
            'http 500', '500', 'crash', 'failure', 'failing', 'intermittent',
            'regression', 'root cause', 'diagnose', 'diagnosi',
        ]);
        $isAnalysis = ! $isBug && Str::contains($haystack, ['analyze', 'analysis', 'analisi', 'investigate', 'inspect']);
        $questions = [];

        if ($repositoryId === null) {
            $questions[] = 'Which repository should the local agent use for this task?';
        }

        if (mb_strlen($description) < 20) {
            $questions[] = 'What observable symptom, workflow, or expected behavior should the agent verify?';
        }

        if ($acceptanceCriteria === []) {
            $questions[] = 'Which acceptance criteria prove that the task is done?';
        }

        $taskType = $isBug ? 'bug' : ($isAnalysis ? 'analysis' : 'implementation');

        return [
            'task_type' => $taskType,
            'requires_root_cause' => $isBug || Str::contains($haystack, ['root cause', 'diagnose', 'diagnosi']),
            'normalized_problem' => $this->compact(trim($title.($description !== '' ? "\n\n".$description : '')), 1800),
            'required_context' => array_values(array_filter([
                'shared_project_memory',
                'project_awareness_status',
                $repositoryId !== null ? 'repository_scope' : null,
                $isBug ? 'bug_evidence' : null,
            ])),
            'ambiguities' => $questions,
            'clarifying_questions' => $questions,
            'clarification_status' => $questions === [] ? 'ready' : 'needs_clarification',
            'confidence' => $questions === [] ? 0.82 : 0.42,
            'ready_for_agent_work' => $questions === [],
        ];
    }

    /**
     * @param  list<string>  $acceptanceCriteria
     * @param  array<string, mixed>  $normalization
     * @return array<string, mixed>
     */
    private function createBugIntakeIfNeeded(object $task, string $projectId, ?string $repositoryId, array $acceptanceCriteria, array $normalization): array
    {
        if (($normalization['task_type'] ?? null) !== 'bug' && ($normalization['requires_root_cause'] ?? false) !== true) {
            return [
                'status' => 'not_applicable',
                'bug_report_id' => null,
                'evidence_refs' => [],
            ];
        }

        $existingEvidence = DB::table('hades_bug_evidence_items')
            ->where('project_id', $projectId)
            ->where('source', 'dashboard_kanban_task:'.(string) $task->id)
            ->orderBy('created_at')
            ->first();

        if ($existingEvidence) {
            return [
                'status' => 'existing',
                'workspace_binding_id' => (string) $existingEvidence->workspace_binding_id,
                'bug_report_id' => $existingEvidence->bug_report_id ? (string) $existingEvidence->bug_report_id : null,
                'evidence_refs' => [[
                    'type' => 'bug_evidence',
                    'id' => (string) $existingEvidence->id,
                    'source_status' => 'developer_provided',
                ]],
            ];
        }

        $binding = DB::table('hades_workspace_bindings')
            ->where('project_id', $projectId)
            ->where('status', 'linked')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('updated_at')
            ->first();

        if (! $binding) {
            return [
                'status' => 'missing_workspace_binding',
                'bug_report_id' => null,
                'evidence_refs' => [],
            ];
        }

        $now = now();
        $bugReportId = (string) Str::ulid();
        $evidenceId = (string) Str::ulid();
        $evidencePayload = [
            'schema' => 'hades.kanban_task_bug_evidence.v1',
            'source_status' => 'developer_provided',
            'task_id' => (string) $task->id,
            'repository_id' => $repositoryId,
            'title' => (string) $task->title,
            'description' => $task->description,
            'acceptance_criteria' => $acceptanceCriteria,
            'normalized_problem' => $normalization['normalized_problem'],
        ];
        $redactedEvidence = $this->policy->redactBugEvidenceMaterial(
            'Kanban task evidence: '.(string) $task->title,
            $evidencePayload,
        );
        $evidencePayload = $redactedEvidence['payload'];
        $evidenceSummary = (string) $redactedEvidence['summary'];
        $evidenceRedactions = (int) $redactedEvidence['redactions'];
        $evidenceJson = json_encode($evidencePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        DB::table('hades_bug_reports')->insert([
            'id' => $bugReportId,
            'project_id' => $projectId,
            'hades_agent_id' => $binding->hades_agent_id,
            'workspace_binding_id' => $binding->id,
            'title' => $this->compact((string) ($evidencePayload['title'] ?? $task->title), 191),
            'symptom' => $this->compact((string) ($evidencePayload['normalized_problem'] ?? $normalization['normalized_problem']), 8000),
            'severity' => $this->severityFromRisk((string) $task->risk_level),
            'status' => 'open',
            'environment' => json_encode([
                'source' => 'dashboard_kanban',
                'task_id' => (string) $task->id,
                'source_status' => 'developer_provided',
            ], JSON_THROW_ON_ERROR),
            'affected_refs' => json_encode(array_values(array_filter([
                ['type' => 'task', 'id' => (string) $task->id, 'source_status' => 'verified_from_code'],
                $repositoryId !== null ? ['type' => 'repository', 'id' => $repositoryId, 'source_status' => 'verified_from_code'] : null,
            ])), JSON_THROW_ON_ERROR),
            'observed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('hades_bug_evidence_items')->insert([
            'id' => $evidenceId,
            'project_id' => $projectId,
            'bug_report_id' => $bugReportId,
            'hades_agent_id' => $binding->hades_agent_id,
            'workspace_binding_id' => $binding->id,
            'kind' => 'user_steps',
            'summary' => $this->compact($evidenceSummary, 4000),
            'payload' => $evidenceJson,
            'source' => 'dashboard_kanban_task:'.(string) $task->id,
            'sha256' => hash('sha256', $evidenceJson),
            'redactions' => $evidenceRedactions,
            'retention_class' => 'runtime_evidence',
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $item = DB::table('hades_bug_evidence_items')->where('id', $evidenceId)->first();
        if ($item) {
            $this->indexer->indexBugEvidence($item);
        }

        return [
            'status' => 'created',
            'workspace_binding_id' => (string) $binding->id,
            'bug_report_id' => $bugReportId,
            'evidence_refs' => [[
                'type' => 'bug_evidence',
                'id' => $evidenceId,
                'source_status' => 'developer_provided',
            ]],
        ];
    }

    /**
     * @param  list<string>  $acceptanceCriteria
     * @param  array<string, mixed>  $normalization
     * @param  array<string, mixed>  $bugIntake
     * @return array<string, mixed>
     */
    private function workPayload(object $task, string $projectId, ?string $repositoryId, array $acceptanceCriteria, array $normalization, array $bugIntake, int $userId, Carbon $now): array
    {
        $payload = [
            'schema' => 'hades.kanban_task_work.v1',
            'source' => 'dashboard_kanban',
            'task_id' => (string) $task->id,
            'project_id' => $projectId,
            'repository_id' => $repositoryId,
            'workspace_binding_id' => $bugIntake['workspace_binding_id'] ?? null,
            'bug_report_id' => $bugIntake['bug_report_id'] ?? null,
            'evidence_refs' => $bugIntake['evidence_refs'] ?? [],
            'title' => (string) $task->title,
            'description' => $task->description,
            'acceptance_criteria' => $acceptanceCriteria,
            'priority' => (string) $task->priority,
            'risk' => (string) $task->risk_level,
            'normalized_problem' => $normalization['normalized_problem'],
            'task_type' => $normalization['task_type'],
            'required_context' => $normalization['required_context'],
            'ambiguities' => $normalization['ambiguities'],
            'clarifying_questions' => $normalization['clarifying_questions'],
            'clarification_status' => $normalization['clarification_status'],
            'ready_for_agent_work' => $normalization['ready_for_agent_work'],
            'source_access_policy' => [
                'mode' => 'source_free_first',
                'allow_source_slice_jobs' => true,
                'direct_source_required' => false,
            ],
            'project_awareness_required' => true,
            'memory_required' => true,
            'bug_intake' => $bugIntake,
            'created_from' => [
                'type' => 'kanban_task',
                'source' => 'dashboard_kanban',
                'assigned_by_user_id' => $userId,
                'normalized_at' => $now->toJSON(),
            ],
        ];
        $redacted = $this->policy->redactBugEvidenceMaterial('kanban task work payload', $payload);
        $payload = $redacted['payload'];
        if ($redacted['redactions'] > 0) {
            $payload['redactions'] = $redacted['redactions'];
        }

        return $payload;
    }

    /**
     * @param  list<string>  $acceptanceCriteria
     * @param  array<string, mixed>  $normalization
     * @param  array<string, mixed>  $bugIntake
     */
    private function localAgentPromptForTask(object $task, array $acceptanceCriteria, array $normalization, array $bugIntake): string
    {
        $lines = [
            'Work on this backend Kanban task using shared Hades project memory and project awareness before making claims.',
            'If source-free diagnosis is possible, prefer backend memory, bug evidence, graph traversal, causal packs, and approved source slices before local source access.',
            '',
            'Task: '.(string) $task->title,
            '',
            'Normalized problem:',
            (string) $normalization['normalized_problem'],
            '',
            'Task type: '.(string) $normalization['task_type'],
        ];

        if (($bugIntake['bug_report_id'] ?? null) !== null) {
            $lines[] = 'Bug report: '.(string) $bugIntake['bug_report_id'];
        }

        if ($acceptanceCriteria !== []) {
            $lines[] = '';
            $lines[] = 'Acceptance criteria:';
            foreach ($acceptanceCriteria as $criterion) {
                $lines[] = '- '.$criterion;
            }
        }

        $lines[] = '';
        $lines[] = 'Before final response, record useful shared memory or evidence refs for future agents.';

        $redacted = $this->policy->redactTextMaterial(implode(PHP_EOL, $lines));

        return $redacted['text'];
    }

    private function severityFromRisk(string $risk): string
    {
        return match ($risk) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            default => 'unknown',
        };
    }

    private function compact(string $value, int $limit): string
    {
        $value = trim($value);

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, max(0, $limit - 1)).'...';
    }
}
