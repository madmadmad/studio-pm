<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\ClientMagicLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PortalInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_can_invite_a_contact_to_the_portal(): void
    {
        Notification::fake();
        $manager = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);

        $this->actingAs($manager)
            ->postJson("/api/contacts/{$contact->id}/portal-invite")
            ->assertOk();

        $this->assertTrue($contact->fresh()->hasPortalAccess());
        Notification::assertSentTo($contact->fresh(), ClientMagicLink::class);
    }

    // The studio's invitation lasts a week (it may sit in an inbox); a link
    // the client asks for still lasts 20 minutes.
    public function test_the_invitation_link_still_works_days_later(): void
    {
        Notification::fake();
        $manager = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);

        $this->actingAs($manager)->postJson("/api/contacts/{$contact->id}/portal-invite")->assertOk();

        $url = null;
        Notification::assertSentTo($contact->fresh(), ClientMagicLink::class, function (ClientMagicLink $n) use ($contact, &$url) {
            $mail = $n->toMail($contact);
            $url = $mail->actionUrl;

            return str_contains(implode(' ', $mail->outroLines), 'expires in 7 days');
        });

        auth()->guard('web')->logout();
        $this->travel(3)->days();
        $this->get($url)->assertRedirect('/portal');
        $this->assertAuthenticatedAs($contact->fresh(), 'client');
    }

    public function test_a_requested_sign_in_link_still_expires_in_20_minutes(): void
    {
        $contact = Company::create(['name' => 'Alder & Finch Design'])->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);
        app(\App\Services\MagicLinkBroker::class)->issue($contact);

        $this->assertEqualsWithDelta(now()->addMinutes(20)->timestamp, $contact->magicLinks()->first()->expires_at->timestamp, 5);
    }

    public function test_a_contact_without_an_email_cannot_be_invited(): void
    {
        $manager = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Rosa Alder']);

        $this->actingAs($manager)
            ->postJson("/api/contacts/{$contact->id}/portal-invite")
            ->assertStatus(422);
    }

    public function test_an_already_invited_contact_cannot_be_invited_again(): void
    {
        $manager = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);
        $contact->forceFill(['portal_invited_at' => now()])->save();

        $this->actingAs($manager)
            ->postJson("/api/contacts/{$contact->id}/portal-invite")
            ->assertStatus(422);
    }

    public function test_a_team_member_cannot_invite_a_contact_to_the_portal(): void
    {
        $teamMember = User::factory()->teamMember()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);

        $this->actingAs($teamMember)
            ->postJson("/api/contacts/{$contact->id}/portal-invite")
            ->assertForbidden();
    }
}
