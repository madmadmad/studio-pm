<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// The first account on a fresh install.
class CreateSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_super_admin_with_a_random_password_it_shows_once(): void
    {
        $this->artisan('app:create-super-admin', ['email' => 'Bill@Example.com', 'name' => 'Bill Sattler'])
            ->expectsOutputToContain('Super admin bill@example.com created.')
            ->expectsOutputToContain('Password (shown once):')
            ->assertSuccessful();

        $user = User::where('email', 'bill@example.com')->sole();
        $this->assertTrue($user->isSuperAdmin());
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_it_refuses_an_email_already_in_use(): void
    {
        User::factory()->create(['email' => 'bill@example.com']);

        $this->artisan('app:create-super-admin', ['email' => 'bill@example.com', 'name' => 'Bill'])->assertFailed();
        $this->assertSame(1, User::count());
    }
}
