<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Notifications\ClientMagicLink;
use App\Notifications\NewMessageReply;
use App\Services\MagicLinkBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PortalAccessManagementTest extends TestCase
{
    use RefreshDatabase;

    private function invitedContact(): Contact
    {
        $contact = Company::create(['name' => 'Alder & Finch Design'])->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);
        $contact->forceFill(['portal_invited_at' => now()])->save();

        return $contact;
    }

    public function test_an_invite_can_be_sent_again_with_a_fresh_link(): void
    {
        Notification::fake();
        $contact = $this->invitedContact();
        $old = app(MagicLinkBroker::class)->issue($contact, MagicLinkBroker::INVITE_TTL_MINUTES);

        $this->actingAs(User::factory()->create())->postJson("/api/contacts/{$contact->id}/portal-invite/resend")->assertOk();

        Notification::assertSentTo($contact, ClientMagicLink::class, fn (ClientMagicLink $n) => str_contains(implode(' ', $n->toMail($contact)->outroLines), 'expires in 7 days'));
        // The old unused link is replaced, so only the newest works.
        $this->assertSame(1, $contact->magicLinks()->whereNull('used_at')->count());
        $this->assertNull($contact->magicLinks()->where('token_hash', hash('sha256', $old))->first());
    }

    public function test_only_an_invited_contact_can_be_sent_an_invite_again(): void
    {
        $contact = Company::create(['name' => 'Alder & Finch Design'])->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);

        $this->actingAs(User::factory()->create())->postJson("/api/contacts/{$contact->id}/portal-invite/resend")->assertUnprocessable();
    }

    public function test_revoking_access_keeps_the_contact_and_can_be_undone_by_inviting_again(): void
    {
        Notification::fake();
        $contact = $this->invitedContact();
        $manager = User::factory()->create();

        $this->actingAs($manager)->deleteJson("/api/contacts/{$contact->id}/portal-invite")->assertOk()->assertJsonPath('has_portal_access', false);

        $this->assertNotNull($contact->fresh());
        $this->assertFalse($contact->fresh()->hasPortalAccess());

        $this->actingAs($manager)->postJson("/api/contacts/{$contact->id}/portal-invite")->assertOk();
        $this->assertTrue($contact->fresh()->hasPortalAccess());
    }

    public function test_a_revoked_contact_is_signed_out_of_an_open_portal_session(): void
    {
        $contact = $this->invitedContact();
        $this->actingAs($contact, 'client')->get('/portal')->assertOk();

        $contact->forceFill(['portal_invited_at' => null])->save();

        $this->followingRedirects()->get('/portal')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Portal/Auth/RequestLink')->where('status', fn ($s) => str_contains($s, 'portal access has ended')));
        $this->assertGuest('client');
    }

    public function test_a_revoked_contact_is_refused_by_the_portal_api(): void
    {
        $contact = $this->invitedContact();
        $contact->forceFill(['portal_invited_at' => null])->save();

        $this->actingAs($contact, 'client')->patchJson('/api/portal/profile/appearance', ['theme' => 'light'])->assertForbidden();
        $this->assertNull($contact->fresh()->theme);
    }

    public function test_an_old_sign_in_link_stops_working_once_access_is_revoked(): void
    {
        $contact = $this->invitedContact();
        // A message email's link, which revoking doesn't replace.
        $url = app(MagicLinkBroker::class)->issueSignedUrl($contact, minutes: MagicLinkBroker::MESSAGE_TTL_MINUTES, replace: false);

        $this->actingAs(User::factory()->create())->deleteJson("/api/contacts/{$contact->id}/portal-invite")->assertOk();
        auth()->guard('web')->logout();

        $this->get($url)->assertInertia(fn ($page) => $page->component('Portal/Auth/LinkInvalid'));
        $this->assertGuest('client');
    }

    public function test_a_revoked_contact_gets_no_more_reply_emails(): void
    {
        Notification::fake();
        $contact = $this->invitedContact();
        $project = $contact->company->projects()->create(['name' => 'Brand refresh']);
        $manager = User::factory()->create();

        $thread = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/messages", [
            'subject' => 'Logo options', 'body' => 'See attached', 'recipients' => ["contact:{$contact->id}"],
        ])->assertCreated()->json('id');

        $contact->forceFill(['portal_invited_at' => null])->save();
        $this->actingAs($manager)->postJson("/api/messages/{$thread}/replies", ['body' => 'Any thoughts?'])->assertCreated();

        Notification::assertNotSentTo($contact, NewMessageReply::class);
    }

    public function test_someone_without_the_clients_permission_cannot_manage_portal_access(): void
    {
        $contact = $this->invitedContact();
        $teammate = User::factory()->teamMember()->create();

        $this->actingAs($teammate)->postJson("/api/contacts/{$contact->id}/portal-invite/resend")->assertForbidden();
        $this->actingAs($teammate)->deleteJson("/api/contacts/{$contact->id}/portal-invite")->assertForbidden();
    }

    // The sign-in page's own notice, which the shared `status` prop carries.
    public function test_requesting_a_sign_in_link_shows_its_notice(): void
    {
        $this->from('/portal/login')->followingRedirects()->post('/portal/login', ['email' => 'nobody@example.com'])->assertOk()
            ->assertInertia(fn ($page) => $page->where('status', 'If that email has portal access, a sign-in link is on its way.'));
    }
}
