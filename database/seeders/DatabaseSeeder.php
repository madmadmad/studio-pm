<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Local development only -- the password's "password". A real
        // install's first account comes from `php artisan
        // app:create-super-admin` instead.
        //
        // firstOrNew + manual save (not updateOrCreate) so re-running this
        // against remote syncs name/role every time -- the idempotent way
        // to push admin/reference data -- without ever clobbering a
        // password someone has already set.
        $user = User::firstOrNew(['email' => 'bill@madmadmad.com']);
        $user->name = 'Bill Sattler';
        $user->role = User::ROLE_SUPER_ADMIN;
        if (! $user->exists) {
            $user->password = bcrypt('password');
        }
        $user->save();
    }
}
