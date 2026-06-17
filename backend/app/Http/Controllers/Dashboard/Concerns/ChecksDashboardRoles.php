<?php

namespace App\Http\Controllers\Dashboard\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;

trait ChecksDashboardRoles
{
    protected function userHasRole(User $user, string $role): bool
    {
        return DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)
            ->where('roles.name', $role)
            ->exists();
    }

    /**
     * @return list<string>
     */
    protected function dashboardRoles(User $user): array
    {
        return DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->all();
    }

    /**
     * @return array{name: string, email: string, roles: list<string>}
     */
    protected function dashboardUser(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $this->dashboardRoles($user),
        ];
    }

    /**
     * @return list<array{label: string, href: string, key: string}>
     */
    protected function dashboardNavigation(User $user, ?string $projectId = null): array
    {
        $roles = $this->dashboardRoles($user);

        if ($roles === ['Agent']) {
            return [];
        }

        $items = [
            ['label' => 'Projects', 'href' => $projectId ? "/projects/{$projectId}" : '/kanban', 'key' => 'projects'],
            ['label' => 'Kanban', 'href' => '/kanban', 'key' => 'kanban'],
            ['label' => 'Runs', 'href' => '/runs', 'key' => 'runs'],
            ['label' => 'Wiki', 'href' => '/wiki', 'key' => 'wiki'],
            ['label' => 'Graph', 'href' => '/graph', 'key' => 'graph'],
            ['label' => 'Artifacts', 'href' => '/kanban', 'key' => 'artifacts'],
        ];

        if (in_array('Admin', $roles, true)) {
            return [
                ...$items,
                ['label' => 'Admin', 'href' => '/admin/plugin-tokens', 'key' => 'admin'],
                ['label' => 'System', 'href' => '/kanban', 'key' => 'system'],
            ];
        }

        if (in_array('Sysadmin', $roles, true)) {
            return [
                ['label' => 'Projects', 'href' => $projectId ? "/projects/{$projectId}" : '/kanban', 'key' => 'projects'],
                ['label' => 'Runs', 'href' => '/runs', 'key' => 'runs'],
                ['label' => 'Artifacts', 'href' => '/kanban', 'key' => 'artifacts'],
                ['label' => 'System', 'href' => '/kanban', 'key' => 'system'],
            ];
        }

        return $items;
    }
}
