<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class BootstrapAdminCommand extends Command
{
    protected $signature = 'devboard:bootstrap-admin
        {--name= : Admin display name}
        {--email= : Admin email address}';

    protected $description = 'Create the first DevBoard administrator using a hidden password prompt';

    public function handle(): int
    {
        $adminRole = DB::table('roles')->where('name', 'Admin')->first();
        if (! $adminRole) {
            $this->error('Admin role is missing. Run the DevBoard structural seeder first.');

            return self::FAILURE;
        }

        $adminExists = DB::table('role_user')
            ->where('role_id', $adminRole->id)
            ->exists();
        if ($adminExists) {
            $this->error('An administrator already exists. This one-shot command will not replace it.');

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: $this->ask('Admin display name'));
        $email = (string) ($this->option('email') ?: $this->ask('Admin email address'));
        $password = (string) $this->secret('Admin password');
        $confirmation = (string) $this->secret('Confirm admin password');

        if (! hash_equals($password, $confirmation)) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => [
                    'required',
                    'string',
                    Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
                ],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $passwordHash = Hash::make($password);

        $created = DB::transaction(function () use ($adminRole, $email, $name, $passwordHash): bool {
            $lockedAdminRole = DB::table('roles')
                ->where('id', $adminRole->id)
                ->where('name', 'Admin')
                ->lockForUpdate()
                ->first();

            if (! $lockedAdminRole || DB::table('role_user')->where('role_id', $lockedAdminRole->id)->exists()) {
                return false;
            }

            $now = now();
            $userId = DB::table('users')->insertGetId([
                'name' => $name,
                'email' => $email,
                'email_verified_at' => $now,
                'password' => $passwordHash,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('role_user')->insert([
                'user_id' => $userId,
                'role_id' => $lockedAdminRole->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return true;
        });

        if (! $created) {
            $this->error('An administrator already exists. This one-shot command will not replace it.');

            return self::FAILURE;
        }

        $this->info("Administrator {$email} created.");

        return self::SUCCESS;
    }
}
