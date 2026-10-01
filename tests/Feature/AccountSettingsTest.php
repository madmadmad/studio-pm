<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_team_member_can_change_their_password(): void
    {
        $user = User::factory()->teamMember()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user)->putJson('/user/password', [
            'current_password' => 'old-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_the_current_password_has_to_be_right(): void
    {
        $user = User::factory()->teamMember()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user)->putJson('/user/password', [
            'current_password' => 'wrong',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_a_team_member_can_update_their_name_and_email(): void
    {
        $user = User::factory()->teamMember()->create();

        $this->actingAs($user)->putJson('/user/profile-information', ['name' => 'Jack White', 'email' => 'jack@example.com'])->assertOk();

        $this->assertSame('jack@example.com', $user->fresh()->email);
    }

    public function test_the_profile_page_says_what_a_password_needs(): void
    {
        $this->actingAs(User::factory()->teamMember()->create())->get('/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Profile/Index')->where('passwordHint', 'At least 8 characters.'));
    }

    public function test_a_forgotten_password_can_be_reset_from_the_login_page(): void
    {
        Notification::fake();
        $user = User::factory()->teamMember()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'reset-password-123',
            'password_confirmation' => 'reset-password-123',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('reset-password-123', $user->fresh()->password));
    }
}
