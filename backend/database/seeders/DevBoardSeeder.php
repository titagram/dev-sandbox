<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DevBoardSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private array $permissions = [
        'users.manage',
        'roles.manage',
        'tokens.manage',
        'projects.read',
        'projects.write',
        'repositories.read',
        'repositories.write',
        'tasks.read',
        'tasks.write',
        'runs.read',
        'runs.write',
        'artifacts.read',
        'artifacts.write',
        'wiki.read',
        'wiki.write',
        'policies.read',
        'policies.write',
        'graph.read',
        'graph.write',
        'audit.read',
        'system.health.read',
    ];

    public function run(): void
    {
        $now = now();

        foreach ($this->permissions as $permission) {
            $this->upsertUlid('permissions', ['name' => $permission], [
                'updated_at' => $now,
            ]);
        }

        foreach ($this->rolePermissions() as $role => $permissions) {
            $this->upsertUlid('roles', ['name' => $role], [
                'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);
        }

        $this->seedAiAgentRegistry($now);

        if (app()->environment('testing')) {
            $this->call(DemoDevBoardSeeder::class);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function rolePermissions(): array
    {
        return [
            'Admin' => $this->permissions,
            'PM' => [
                'projects.read',
                'repositories.read',
                'tasks.read',
                'tasks.write',
                'runs.read',
                'artifacts.read',
                'wiki.read',
                'wiki.write',
                'graph.read',
                'audit.read',
            ],
            'Developer' => [
                'projects.read',
                'repositories.read',
                'tasks.read',
                'tasks.write',
                'runs.read',
                'runs.write',
                'artifacts.read',
                'artifacts.write',
                'wiki.read',
                'wiki.write',
                'policies.read',
                'graph.read',
            ],
            'Sysadmin' => [
                'projects.read',
                'repositories.read',
                'runs.read',
                'artifacts.read',
                'audit.read',
                'system.health.read',
                'graph.read',
            ],
            'Agent' => [
                'projects.read',
                'repositories.read',
                'runs.write',
                'artifacts.write',
                'wiki.write',
                'policies.read',
                'graph.write',
            ],
        ];
    }

    private function seedAiAgentRegistry(mixed $now): void
    {
        $openAiProviderId = $this->upsertUlid('ai_model_providers', ['provider_key' => 'openai'], [
            'display_name' => 'OpenAI',
            'provider_type' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'metadata' => json_encode([
                'source_status' => 'developer_provided',
                'notes' => 'Admin-configured provider for future server-side DevBoard assistants.',
            ], JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ]);

        $this->upsertUlid('ai_model_providers', ['provider_key' => 'opencode_go'], [
            'display_name' => 'OpenCode Go',
            'provider_type' => 'openai_compatible',
            'base_url' => 'https://opencode.ai/zen/go/v1',
            'enabled' => false,
            'metadata' => json_encode([
                'source_status' => 'verified_from_docs',
                'notes' => 'First-class configurable provider slot for OpenCode Go. Credentials are supplied by an Admin.',
            ], JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ]);

        $defaultTextModelProfileId = $this->upsertUlid('ai_model_profiles', ['profile_key' => 'openai_default_text'], [
            'provider_id' => $openAiProviderId,
            'display_name' => 'OpenAI Default Text',
            'model_name' => 'gpt-5.4',
            'runtime_profile' => 'compact_readonly',
            'max_context' => null,
            'max_output_tokens' => 2048,
            'temperature' => 0,
            'timeout_seconds' => 30,
            'enabled' => true,
            'metadata' => json_encode([
                'source_status' => 'verified_from_code',
                'notes' => 'Matches the installed Laravel AI SDK OpenAI default text model.',
            ], JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ]);

        foreach ($this->defaultAgentProfiles() as $agent) {
            $this->upsertUlid('ai_agent_profiles', ['agent_key' => $agent['agent_key']], [
                'display_name' => $agent['display_name'],
                'description' => $agent['description'],
                'agent_type' => $agent['agent_type'],
                'delegation_mode' => 'controlled_registry',
                'parent_agent_key' => $agent['parent_agent_key'],
                'default_model_profile_id' => $defaultTextModelProfileId,
                'requires_human_approval' => true,
                'enabled' => true,
                'allowed_tools' => json_encode($agent['allowed_tools'], JSON_THROW_ON_ERROR),
                'output_schema' => json_encode($agent['output_schema'], JSON_THROW_ON_ERROR),
                'trigger_events' => json_encode($agent['trigger_events'], JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);
        }
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
                'description' => 'Finds vague, duplicate, stale, oversized, or inconsistent backlog work and emits recommendations only.',
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

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, mixed>  $values
     */
    private function upsertUlid(string $table, array $where, array $values): string
    {
        $id = DB::table($table)->where($where)->value('id');
        $now = now();

        if ($id) {
            DB::table($table)->where('id', $id)->update($values);

            return $id;
        }

        $id = (string) Str::ulid();

        DB::table($table)->insert(array_merge($where, $values, [
            'id' => $id,
            'created_at' => $now,
            'updated_at' => $values['updated_at'] ?? $now,
        ]));

        return $id;
    }
}
