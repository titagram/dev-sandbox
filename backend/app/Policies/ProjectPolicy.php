<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProjectPolicy
{
    public function read(User $user): bool
    {
        return DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)
            ->whereIn('roles.name', ['Admin', 'PM', 'Developer', 'Sysadmin'])
            ->exists();
    }

    public function write(User $user): bool
    {
        return DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)
            ->whereIn('roles.name', ['Admin', 'PM'])
            ->exists();
    }
}
