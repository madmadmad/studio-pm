<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_can_invite_a_new_staff_member(): void
    {
        Notification::fake();
        $manager = User::factory()->create();

        $response = $this->actingAs($manager)->postJson('/api/users', [
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'role' => 'team_member',
        ]);

        $response->assertCreated();
        $user = User::where('email', 'jamie@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->invite_token);
        $this->assertTrue($user->has_pending_invite);
        $this->assertFalse($user->isManager());
        Notification::assertSentTo($user, StaffInvitation::class);
    }

    public function test_a_team_member_cannot_invite_staff(): void
    {
        $teamMember = User::factory()->teamMember()->create();

        $this->actingAs($teamMember)->postJson('/api/users', [
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'role' => 'team_member',
        ])->assertForbidden();
    }

    public function test_a_manager_cannot_change_their_own_role(): void
    {
        $manager = User::factory()->create();

        $this->actingAs($manager)
            ->patchJson("/api/users/{$manager->id}", ['role' => 'team_member'])
            ->assertUnprocessable();

        $this->assertTrue($manager->fresh()->isManager());
    }

    public function test_a_manager_cannot_deactivate_themselves(): void
    {
        $manager = User::factory()->create();

        $this->actingAs($manager)
            ->deleteJson("/api/users/{$manager->id}")
            ->assertForbidden();
    }

    public function test_deactivating_a_user_immediately_signs_them_out(): void
    {
        $manager = User::factory()->create();
        $teamMember = User::factory()->teamMember()->create();

        $this->actingAs($teamMember)->getJson('/api/time-entries')->assertOk();

        $this->actingAs($manager)->deleteJson("/api/users/{$teamMember->id}")->assertNoContent();
        $this->assertNotNull($teamMember->fresh()->deactivated_at);

        // A real subsequent request re-hydrates the user from the DB via the
        // session, so simulate that with a fresh fetch rather than reusing
        // the stale in-memory $teamMember from before deactivation.
        $this->actingAs($teamMember->fresh())->getJson('/api/time-entries')->assertForbidden();
    }

    public function test_a_manager_can_reactivate_a_deactivated_user(): void
    {
        $manager = User::factory()->create();
        $teamMember = User::factory()->teamMember()->create(['deactivated_at' => now()]);

        $this->actingAs($manager)
            ->postJson("/api/users/{$teamMember->id}/reactivate")
            ->assertOk();

        $this->assertNull($teamMember->fresh()->deactivated_at);
    }

    public function test_resending_an_invite_issues_a_fresh_token(): void
    {
        Notification::fake();
        $manager = User::factory()->create();
        $teamMember = User::factory()->teamMember()->create(['invite_token' => hash('sha256', 'old-token')]);

        $this->actingAs($manager)
            ->postJson("/api/users/{$teamMember->id}/resend-invite")
            ->assertOk();

        $this->assertNotEquals(hash('sha256', 'old-token'), $teamMember->fresh()->invite_token);
        Notification::assertSentTo($teamMember, StaffInvitation::class);
    }
}
