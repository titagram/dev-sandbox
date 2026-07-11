<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class PluginTokenPolicy
{
    public function manage(User $user): bool
    {
        return DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)
            ->where('roles.name', 'Admin')
            ->exists();
    }
}
