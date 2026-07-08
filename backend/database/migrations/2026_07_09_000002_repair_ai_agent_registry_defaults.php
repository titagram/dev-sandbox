<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $this->repairOpenCodeGoDefaultModelProfile($now);

        $defaultModelProfileId = $this->defaultModelProfileId();

        foreach ($this->defaultAgentProfiles() as $agent) {
            $existingId = DB::table('ai_agent_profiles')->where('agent_key', $agent['agent_key'])->value('id');
            $values = [
                'display_name' => $agent['display_name'],
                'description' => $agent['description'],
                'agent_type' => $agent['agent_type'],
                'delegation_mode' => 'controlled_registry',
                'parent_agent_key' => $agent['parent_agent_key'],
                'default_model_profile_id' => $defaultModelProfileId,
                'requires_human_approval' => true,
                'enabled' => true,
                'allowed_tools' => json_encode($agent['allowed_tools'], JSON_THROW_ON_ERROR),
                'output_schema' => json_encode($agent['output_schema'], JSON_THROW_ON_ERROR),
                'trigger_events' => json_encode($agent['trigger_events'], JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ];

            if ($existingId) {
                DB::table('ai_agent_profiles')->where('id', $existingId)->update($values);
                continue;
            }

            DB::table('ai_agent_profiles')->insert(array_merge($values, [
                'id' => (string) Str::ulid(),
                'agent_key' => $agent['agent_key'],
                'created_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        // Data repair migration: rolling back should not delete registry rows that may now be in use.
    }

    private function repairOpenCodeGoDefaultModelProfile(mixed $now): void
    {
        $profile = DB::table('ai_model_profiles')->where('profile_key', 'opencode_go_default')->first();

        if (! $profile || (string) $profile->model_name !== 'opencode-go') {
            return;
        }

        DB::table('ai_model_profiles')->where('id', $profile->id)->update([
            'model_name' => 'glm-5.2',
            'updated_at' => $now,
        ]);
    }

    private function defaultModelProfileId(): ?string
    {
        foreach (['openai_default_text', 'opencode_go_default'] as $profileKey) {
            $profileId = DB::table('ai_model_profiles')->where('profile_key', $profileKey)->value('id');
            if ($profileId) {
                return (string) $profileId;
            }
        }

        $profileId = DB::table('ai_model_profiles')->orderBy('profile_key')->value('id');

        return $profileId ? (string) $profileId : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultAgentProfiles(): array
    {
        return [
            [
                'agent_key' => 'socrate_supervisor',
                'display_name' => 'Socrate Supervisor',
                'description' => 'Project-level read-only supervisor that routes requests to controlled specialist flows.',
                'agent_type' => 'supervisor',
                'parent_agent_key' => null,
                'allowed_tools' => ['read_project_summary', 'search_project_memory', 'query_project_graph', 'read_agent_profile_registry', 'append_assistant_suggestion'],
                'trigger_events' => ['manual_chat', 'project_summary_request'],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['answer', 'delegations', 'evidence_refs', 'approval_required'],
                    'properties' => [
                        'answer' => ['type' => 'string'],
                        'delegations' => ['type' => 'array'],
                        'evidence_refs' => ['type' => 'array'],
                        'approval_required' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'agent_key' => 'task_clarifier',
                'display_name' => 'Task Clarifier',
                'description' => 'Reviews PM task drafts and proposes questions, acceptance criteria, risks, dependencies, and test hints.',
                'agent_type' => 'specialist',
                'parent_agent_key' => 'socrate_supervisor',
                'allowed_tools' => ['read_task_detail', 'read_project_summary', 'search_project_memory', 'search_wiki_revisions', 'append_assistant_suggestion'],
                'trigger_events' => ['task_created', 'task_updated', 'manual_review'],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['questions', 'acceptance_criteria', 'risks', 'missing_context', 'confidence'],
                    'properties' => [
                        'questions' => ['type' => 'array'],
                        'acceptance_criteria' => ['type' => 'array'],
                        'risks' => ['type' => 'array'],
                        'missing_context' => ['type' => 'array'],
                        'confidence' => ['type' => 'number'],
                    ],
                ],
            ],
            [
                'agent_key' => 'backlog_triage',
                'display_name' => 'Backlog Triage',
                'description' => 'Finds vague, duplicate, stale, oversized, blocked, or risky work and emits recommendations only.',
                'agent_type' => 'specialist',
                'parent_agent_key' => 'socrate_supervisor',
                'allowed_tools' => ['read_project_tasks', 'read_project_summary', 'search_project_memory', 'append_assistant_suggestion'],
                'trigger_events' => ['manual_triage', 'scheduled_triage'],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['summary', 'groups', 'recommendations', 'risks', 'confidence'],
                    'properties' => [
                        'summary' => ['type' => 'string'],
                        'groups' => ['type' => 'array'],
                        'recommendations' => ['type' => 'array'],
                        'risks' => ['type' => 'array'],
                        'confidence' => ['type' => 'number'],
                    ],
                ],
            ],
            [
                'agent_key' => 'wiki_query',
                'display_name' => 'Wiki Query',
                'description' => 'Answers from DevBoard-held wiki evidence and flags stale or conflicting pages.',
                'agent_type' => 'specialist',
                'parent_agent_key' => 'socrate_supervisor',
                'allowed_tools' => ['search_wiki_revisions', 'search_project_memory', 'query_project_graph', 'write_wiki_revision', 'read_artifact_metadata', 'append_assistant_suggestion'],
                'trigger_events' => ['manual_chat', 'wiki_freshness_check'],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['answer', 'citations', 'freshness_warnings', 'confidence'],
                    'properties' => [
                        'answer' => ['type' => 'string'],
                        'citations' => ['type' => 'array'],
                        'freshness_warnings' => ['type' => 'array'],
                        'confidence' => ['type' => 'number'],
                    ],
                ],
            ],
            [
                'agent_key' => 'watchman',
                'display_name' => 'Watchman',
                'description' => 'Correlates logbook, run, graph, wiki, artifact, and quality signals into warnings and follow-up suggestions.',
                'agent_type' => 'specialist',
                'parent_agent_key' => 'socrate_supervisor',
                'allowed_tools' => ['read_logbook_entries', 'read_run_summary', 'read_quality_report_summaries', 'append_assistant_suggestion'],
                'trigger_events' => ['logbook_entry_created', 'run_finished', 'manual_scan'],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['warnings', 'follow_up_suggestions', 'evidence_refs', 'confidence'],
                    'properties' => [
                        'warnings' => ['type' => 'array'],
                        'follow_up_suggestions' => ['type' => 'array'],
                        'evidence_refs' => ['type' => 'array'],
                        'confidence' => ['type' => 'number'],
                    ],
                ],
            ],
            [
                'agent_key' => 'intake_normalizer',
                'display_name' => 'Intake Normalizer',
                'description' => 'Classifies raw free-text input (bug, task, feature, question) and extracts a normalized title, description, and clarifying questions.',
                'agent_type' => 'specialist',
                'parent_agent_key' => 'socrate_supervisor',
                'allowed_tools' => [],
                'trigger_events' => ['manual_intake'],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['task_type', 'suggested_title', 'suggested_description', 'clarifying_questions', 'confidence'],
                    'properties' => [
                        'task_type' => ['type' => 'string', 'enum' => ['bug', 'task', 'feature', 'question']],
                        'suggested_title' => ['type' => 'string'],
                        'suggested_description' => ['type' => 'string'],
                        'clarifying_questions' => ['type' => 'array'],
                        'confidence' => ['type' => 'number'],
                    ],
                ],
            ],
        ];
    }
};
