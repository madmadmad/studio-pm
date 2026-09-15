<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_a_user_can_log_in_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-password')]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_incorrect_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-password')]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_unauthenticated_api_requests_are_rejected(): void
    {
        $this->getJson('/api/companies')->assertUnauthorized();
    }
}
