<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDevBoardSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $roleIds = DB::table('roles')->pluck('id', 'name')->all();

        $userId = $this->seedUser('DevBoard Admin', 'admin@example.com', 'password', 'Admin', $roleIds, $now);

        foreach ([
            ['DevBoard Admin', 'admin@devboard.local', 'devboard', 'Admin'],
            ['DevBoard PM', 'pm@devboard.local', 'devboard', 'PM'],
            ['DevBoard Developer', 'dev@devboard.local', 'devboard', 'Developer'],
            ['DevBoard Sysadmin', 'sysadmin@devboard.local', 'devboard', 'Sysadmin'],
        ] as [$name, $email, $password, $role]) {
            $this->seedUser($name, $email, $password, $role, $roleIds, $now);
        }

        $projectId = $this->upsertUlid('projects', ['slug' => 'demo-project'], [
            'name' => 'Demo Project',
            'description' => 'Seed project for DevBoard onboarding and Genesis import.',
            'status' => 'active',
            'default_code_exposure_policy' => 'full_code_artifacts',
            'created_by_user_id' => $userId,
            'updated_at' => $now,
        ]);

        $repositoryId = $this->upsertUlid('repositories', [
            'project_id' => $projectId,
            'slug' => 'demo-repository',
        ], [
            'name' => 'demo-repository',
            'default_branch' => 'main',
            'local_only' => true,
            'code_exposure_policy' => 'full_code_artifacts',
            'protected_paths' => json_encode(['.env', '*.key', '*.pem'], JSON_THROW_ON_ERROR),
            'excluded_paths' => json_encode(['vendor/', 'node_modules/', 'storage/framework/'], JSON_THROW_ON_ERROR),
            'stack_hints' => json_encode(['python'], JSON_THROW_ON_ERROR),
            'graph_enabled' => true,
            'updated_at' => $now,
        ]);

        $boardId = $this->upsertUlid('kanban_boards', [
            'project_id' => $projectId,
            'name' => 'Default Board',
        ], [
            'is_default' => true,
            'updated_at' => $now,
        ]);

        foreach ($this->defaultColumns() as $position => $column) {
            $this->upsertUlid('kanban_columns', [
                'board_id' => $boardId,
                'status_key' => $column['status_key'],
            ], [
                'name' => $column['name'],
                'position' => $position + 1,
                'wip_limit' => null,
                'updated_at' => $now,
            ]);
        }

        $this->upsertUlid('wiki_pages', [
            'project_id' => $projectId,
            'repository_id' => $repositoryId,
            'slug' => 'project-overview',
        ], [
            'title' => 'Project Overview',
            'page_type' => 'business',
            'current_revision_id' => null,
            'source_status' => 'developer_provided',
            'updated_at' => $now,
        ]);
    }

    /**
     * @return list<array{name: string, status_key: string}>
     */
    private function defaultColumns(): array
    {
        return [
            ['name' => 'Backlog', 'status_key' => 'backlog'],
            ['name' => 'Ready', 'status_key' => 'ready'],
            ['name' => 'In Progress', 'status_key' => 'in_progress'],
            ['name' => 'Blocked', 'status_key' => 'blocked'],
            ['name' => 'Review', 'status_key' => 'review'],
            ['name' => 'Done', 'status_key' => 'done'],
        ];
    }

    /**
     * @param  array<string, string>  $roleIds
     */
    private function seedUser(string $name, string $email, string $password, string $role, array $roleIds, mixed $now): string
    {
        $userId = DB::table('users')->where('email', $email)->value('id');
        $values = [
            'name' => $name,
            'password' => Hash::make($password),
            'status' => 'active',
            'updated_at' => $now,
        ];

        if ($userId) {
            DB::table('users')->where('id', $userId)->update($values);
        } else {
            $userId = DB::table('users')->insertGetId(array_merge($values, [
                'email' => $email,
                'created_at' => $now,
            ]));
        }

        DB::table('role_user')->updateOrInsert(
            ['user_id' => $userId, 'role_id' => $roleIds[$role]],
            ['updated_at' => $now, 'created_at' => $now],
        );

        return (string) $userId;
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
