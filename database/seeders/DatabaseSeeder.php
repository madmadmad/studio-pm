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
        // firstOrNew + manual save (not updateOrCreate) so re-running this
        // against remote syncs name/role every time -- the idempotent way
        // to push admin/reference data -- without ever clobbering a
        // password someone has already set.
        $user = User::firstOrNew(['email' => 'bill@madmadmad.com']);
        $user->name = 'Bill Sattler';
        $user->role = User::ROLE_MANAGER;
        if (! $user->exists) {
            $user->password = bcrypt('password');
        }
        $user->save();
    }
}
