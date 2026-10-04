<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\StudioProfile;
use App\Models\User;
use App\Notifications\ClientMagicLink;
use App\Notifications\StaffInvitation;
use App\Services\MagicLinkBroker;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private function save(User $manager, array $templates): void
    {
        $this->actingAs($manager)->patchJson('/api/studio-profile', ['name' => 'Madhouse Studio', 'email_templates' => $templates])->assertSuccessful();
    }

    public function test_an_edited_email_is_sent_with_its_placeholders_filled_in(): void
    {
        $manager = User::factory()->create(['name' => 'Rosa Alder']);
        $this->save($manager, ['staff_invite' => [
            'subject' => 'Join :studio',
            'heading' => 'Welcome, :first_name!',
            'message' => "First paragraph.\n\nSecond paragraph.",
            'button' => 'Get started',
            'note' => '',
        ]]);

        $mail = (new StaffInvitation('token'))->toMail($manager);

        $this->assertSame('Join Madhouse Studio', $mail->subject);
        $this->assertSame('Welcome, Rosa!', $mail->greeting);
        $this->assertSame(['First paragraph.', 'Second paragraph.'], $mail->introLines);
        $this->assertSame('Get started', $mail->actionText);
        $this->assertSame([], $mail->outroLines);
    }

    public function test_only_changes_are_stored_so_untouched_emails_keep_the_defaults(): void
    {
        $manager = User::factory()->create();
        $defaults = config('email_templates.client_invite.defaults');
        $this->save($manager, [
            'client_invite' => $defaults,
            'client_sign_in' => [...config('email_templates.client_sign_in.defaults'), 'button' => 'Open the hub'],
            'not_a_template' => ['subject' => 'Ignored'],
        ]);

        $this->assertSame(['client_sign_in' => ['button' => 'Open the hub']], StudioProfile::current()->email_templates);
    }

    public function test_the_client_emails_and_password_reset_use_their_templates(): void
    {
        $contact = Company::create(['name' => 'Alder & Finch Design'])->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);

        $invite = (new ClientMagicLink('https://x', firstInvite: true, expiresInMinutes: MagicLinkBroker::INVITE_TTL_MINUTES))->toMail($contact);
        $this->assertSame('Welcome to your Madhouse Studio client hub', $invite->subject);
        $this->assertStringContainsString('expires in 7 days', $invite->outroLines[0]);

        $signIn = (new ClientMagicLink('https://x'))->toMail($contact);
        $this->assertSame('Hi Rosa,', $signIn->greeting);
        $this->assertStringContainsString('expires in 20 minutes', $signIn->outroLines[0]);

        $reset = (new ResetPassword('token'))->toMail(User::factory()->create(['name' => 'Don Draper']));
        $this->assertSame('Reset your Madhouse Studio password', $reset->subject);
        $this->assertSame('Hi Don,', $reset->greeting);
        $this->assertStringContainsString('expires in 1 hour', $reset->outroLines[0]);
    }

    // A message email's link lasts three days and doesn't cancel the link
    // in an earlier one.
    public function test_message_links_last_three_days_and_dont_replace_each_other(): void
    {
        $contact = Company::create(['name' => 'Alder & Finch Design'])->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);
        $broker = app(MagicLinkBroker::class);

        $broker->issue($contact, MagicLinkBroker::MESSAGE_TTL_MINUTES, replace: false);
        $broker->issue($contact, MagicLinkBroker::MESSAGE_TTL_MINUTES, replace: false);

        $this->assertSame(2, $contact->magicLinks()->whereNull('used_at')->count());
        $this->assertEqualsWithDelta(now()->addDays(3)->timestamp, $contact->magicLinks()->first()->expires_at->timestamp, 5);
    }
}
