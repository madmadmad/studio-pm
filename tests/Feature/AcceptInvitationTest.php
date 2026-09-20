<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AcceptInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function invitedUser(): array
    {
        $rawToken = 'a-raw-invite-token';
        $user = User::factory()->teamMember()->create([
            'password' => Hash::make(Str::random(40)),
            'invite_token' => hash('sha256', $rawToken),
            'invite_expires_at' => now()->addDays(7),
        ]);

        return [$user, $rawToken];
    }

    public function test_a_valid_invite_link_lets_a_user_set_their_password(): void
    {
        [$user, $rawToken] = $this->invitedUser();

        $response = $this->postJson("/invite/{$rawToken}", [
            'email' => $user->email,
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertNull($user->fresh()->invite_token);
        $this->assertTrue(Hash::check('a-strong-password', $user->fresh()->password));
    }

    public function test_an_expired_invite_link_is_rejected(): void
    {
        $rawToken = 'expired-token';
        $user = User::factory()->teamMember()->create([
            'invite_token' => hash('sha256', $rawToken),
            'invite_expires_at' => now()->subDay(),
        ]);

        $this->postJson("/invite/{$rawToken}", [
            'email' => $user->email,
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_a_bogus_token_is_rejected(): void
    {
        [$user] = $this->invitedUser();

        $this->postJson('/invite/not-the-real-token', [
            'email' => $user->email,
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertUnprocessable();

        $this->assertGuest();
    }
}
