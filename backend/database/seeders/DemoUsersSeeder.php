<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    /**
     * @return array<string, int>
     */
    public function seedUsers(): array
    {
        $now = now();
        $roleIds = DB::table('roles')->pluck('id', 'name')->all();
        $accounts = [
            ['DevBoard Admin', 'admin@example.com', 'password', 'Admin'],
            ['DevBoard Admin', 'admin@devboard.local', 'devboard', 'Admin'],
            ['DevBoard PM', 'pm@devboard.local', 'devboard', 'PM'],
            ['DevBoard Developer', 'dev@devboard.local', 'devboard', 'Developer'],
            ['DevBoard Sysadmin', 'sysadmin@devboard.local', 'devboard', 'Sysadmin'],
        ];

        foreach ($accounts as [, , , $role]) {
            if (! isset($roleIds[$role])) {
                throw new \RuntimeException("Required role [{$role}] is missing. Run DevBoardSeeder first.");
            }
        }

        $userIds = [];
        foreach ($accounts as [$name, $email, $password, $role]) {
            $userIds[$email] = $this->seedUser($name, $email, $password, $roleIds[$role], $now);
        }

        return $userIds;
    }

    public function run(): void
    {
        $this->seedUsers();
    }

    private function seedUser(string $name, string $email, string $password, string $roleId, mixed $now): int
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
            ['user_id' => $userId, 'role_id' => $roleId],
            ['updated_at' => $now, 'created_at' => $now],
        );

        return (int) $userId;
    }
}
