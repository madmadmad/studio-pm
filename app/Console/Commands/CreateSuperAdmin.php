<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

// The first account on a fresh install (Laravel Cloud's command runner, a
// new server): a super admin with a long random password, shown once.
// Sign in with it and change it under Profile. Everyone after that is
// invited from Team.
class CreateSuperAdmin extends Command
{
    protected $signature = 'app:create-super-admin {email} {name}';

    protected $description = 'Create a super admin with a random password, shown once';

    public function handle(): int
    {
        $data = ['email' => strtolower(trim($this->argument('email'))), 'name' => trim($this->argument('name'))];

        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = Str::password(24, symbols: false);
        $user = User::create([...$data, 'password' => $password, 'role' => User::ROLE_SUPER_ADMIN]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->info("Super admin {$user->email} created.");
        $this->line("Password (shown once): {$password}");
        $this->line('Sign in and change it under Profile.');

        return self::SUCCESS;
    }
}
