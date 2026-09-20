<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Notifications\ClientMagicLink;
use App\Services\MagicLinkBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MagicLinkAuthTest extends TestCase
{
    use RefreshDatabase;

    private function portalContact(): Contact
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);

        $contact->forceFill(['portal_invited_at' => now()])->save();

        return $contact;
    }

    private function signedLink(Contact $contact, string $rawToken): string
    {
        return URL::temporarySignedRoute('portal.verify', now()->addMinutes(MagicLinkBroker::TTL_MINUTES), [
            'contactId' => $contact->id,
            'token' => $rawToken,
        ]);
    }

    public function test_requesting_a_link_for_a_portal_contact_sends_one(): void
    {
        Notification::fake();
        $contact = $this->portalContact();

        $this->post('/portal/login', ['email' => $contact->email])->assertRedirect();

        Notification::assertSentTo($contact, ClientMagicLink::class);
    }

    public function test_requesting_a_link_for_a_non_portal_email_gives_no_hint_it_failed(): void
    {
        Notification::fake();

        $response = $this->post('/portal/login', ['email' => 'nobody@example.com']);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        Notification::assertNothingSent();
    }

    public function test_a_valid_link_logs_the_contact_in_and_consumes_the_token(): void
    {
        $contact = $this->portalContact();
        $rawToken = app(MagicLinkBroker::class)->issue($contact);

        $response = $this->get($this->signedLink($contact, $rawToken));

        $response->assertRedirect('/portal');
        $this->assertAuthenticatedAs($contact, 'client');
        $this->assertNotNull($contact->magicLinks()->first()->used_at);
    }

    public function test_a_used_link_cannot_be_used_again(): void
    {
        $contact = $this->portalContact();
        $rawToken = app(MagicLinkBroker::class)->issue($contact);
        $url = $this->signedLink($contact, $rawToken);

        $this->get($url)->assertRedirect('/portal');
        auth('client')->logout();

        $response = $this->get($url);

        $response->assertInertia(fn ($page) => $page->component('Portal/Auth/LinkInvalid')->where('reason', 'used'));
        $this->assertGuest('client');
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $contact = $this->portalContact();
        $rawToken = app(MagicLinkBroker::class)->issue($contact);
        $contact->magicLinks()->first()->update(['expires_at' => now()->subMinute()]);

        $response = $this->get($this->signedLink($contact, $rawToken));

        $response->assertInertia(fn ($page) => $page->component('Portal/Auth/LinkInvalid')->where('reason', 'expired'));
        $this->assertGuest('client');
    }

    public function test_a_tampered_url_fails_signature_verification(): void
    {
        $contact = $this->portalContact();
        $rawToken = app(MagicLinkBroker::class)->issue($contact);
        $url = $this->signedLink($contact, $rawToken);
        $tampered = str_replace((string) $contact->id, (string) ($contact->id + 999), $url);

        $response = $this->get($tampered);

        $response->assertInertia(fn ($page) => $page->component('Portal/Auth/LinkInvalid')->where('reason', 'invalid'));
        $this->assertGuest('client');
    }

    public function test_a_bogus_token_for_a_real_contact_is_rejected(): void
    {
        $contact = $this->portalContact();
        app(MagicLinkBroker::class)->issue($contact);

        $response = $this->get($this->signedLink($contact, 'not-the-real-token'));

        $response->assertInertia(fn ($page) => $page->component('Portal/Auth/LinkInvalid')->where('reason', 'invalid'));
        $this->assertGuest('client');
    }

    public function test_issuing_a_new_link_invalidates_the_previous_unused_one(): void
    {
        $contact = $this->portalContact();
        $broker = app(MagicLinkBroker::class);
        $firstToken = $broker->issue($contact);
        $broker->issue($contact);

        $response = $this->get($this->signedLink($contact, $firstToken));

        $response->assertInertia(fn ($page) => $page->component('Portal/Auth/LinkInvalid')->where('reason', 'invalid'));
    }

    public function test_magic_link_requests_are_rate_limited(): void
    {
        Notification::fake();
        $contact = $this->portalContact();

        $this->post('/portal/login', ['email' => $contact->email])->assertRedirect();
        $this->post('/portal/login', ['email' => $contact->email])->assertStatus(429);
    }
}
