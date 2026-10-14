<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\PushAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private int $devices = 0;

    // A person with notifications turned on for a device.
    private function subscribed(User $user): User
    {
        $this->devices++;
        $user->updatePushSubscription("https://push.example.test/{$user->id}-{$this->devices}", 'BPublicKey', 'authSecret');

        return $user;
    }

    private function teamMember(array $permissions = [], array $attributes = []): User
    {
        $user = User::factory()->teamMember()->create($attributes);
        $user->forceFill(['permissions' => $permissions])->save();

        return $user;
    }

    public function test_a_direct_message_reaches_the_other_person_not_the_author(): void
    {
        Notification::fake();
        $alex = $this->subscribed($this->teamMember([], ['name' => 'Alex Rivera']));
        $sam = $this->subscribed($this->teamMember([], ['name' => 'Sam Lee']));
        $dm = Conversation::create(['type' => 'direct', 'direct_key' => "{$alex->id}-{$sam->id}"]);
        $dm->members()->attach([$alex->id => ['joined_at' => now()], $sam->id => ['joined_at' => now()]]);

        $this->actingAs($alex)->postJson("/api/chat/conversations/{$dm->id}/messages", ['body' => 'Proofs are in'])->assertCreated();

        Notification::assertSentTo($sam, PushAlert::class, fn (PushAlert $alert) => $alert->title === 'Alex Rivera'
            && $alert->body === 'Proofs are in'
            && $alert->url === "/chat/{$dm->id}"
            && $alert->tag === "chat-{$dm->id}");
        Notification::assertNotSentTo($alex, PushAlert::class);
    }

    public function test_in_a_channel_only_mentions_notify_and_never_the_muted_or_switched_off(): void
    {
        Notification::fake();
        $alex = $this->subscribed($this->teamMember([], ['name' => 'Alex Rivera']));
        $sam = $this->subscribed($this->teamMember([], ['name' => 'Sam Lee']));
        $jo = $this->subscribed($this->teamMember([], ['name' => 'Jo Park']));
        $muted = $this->subscribed($this->teamMember([], ['name' => 'Muted Person']));
        $off = $this->subscribed($this->teamMember([], ['name' => 'Chat Off']));
        $off->forceFill(['push_chat' => false])->save();
        $noDevice = $this->teamMember([], ['name' => 'No Device']);
        $general = Conversation::create(['type' => 'channel', 'name' => 'general', 'slug' => 'general']);
        foreach ([$alex, $sam, $jo, $off, $noDevice] as $member) {
            $general->members()->attach($member->id, ['joined_at' => now()]);
        }
        $general->members()->attach($muted->id, ['joined_at' => now(), 'muted' => true]);

        $this->actingAs($alex)->postJson("/api/chat/conversations/{$general->id}/messages", [
            'body' => "<@{$sam->id}> <@{$muted->id}> <@{$off->id}> <@{$noDevice->id}> can you look?",
        ])->assertCreated();

        Notification::assertSentTo($sam, PushAlert::class, fn (PushAlert $alert) => $alert->title === 'Alex Rivera in #general'
            && str_starts_with($alert->body, '@Sam Lee @Muted Person'));
        Notification::assertNotSentTo($jo, PushAlert::class); // not mentioned
        Notification::assertNotSentTo($muted, PushAlert::class);
        Notification::assertNotSentTo($off, PushAlert::class);
        Notification::assertNotSentTo($noDevice, PushAlert::class);
    }

    public function test_an_accepted_proposal_reaches_whoever_handles_proposals(): void
    {
        Notification::fake();
        $owner = $this->subscribed(User::factory()->create(['role' => 'super_admin']));
        $seller = $this->subscribed($this->teamMember(['proposals']));
        $designer = $this->subscribed($this->teamMember());
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $proposal = $company->proposals()->create([
            'title' => 'Brand refresh', 'body' => '<p>Scope</p>', 'estimate_amount' => 12000, 'status' => 'sent', 'sent_at' => now(),
        ]);

        $this->postJson("/api/proposals/{$proposal->accept_token}/accept")->assertOk();

        foreach ([$owner, $seller] as $user) {
            Notification::assertSentTo($user, PushAlert::class, fn (PushAlert $alert) => $alert->title === 'Proposal accepted'
                && $alert->body === 'Alder & Finch Design accepted “Brand refresh” ($12,000.00).'
                && $alert->url === "/proposals/{$proposal->id}/edit");
        }
        Notification::assertNotSentTo($designer, PushAlert::class);

        // Accepting again isn't news.
        $this->postJson("/api/proposals/{$proposal->accept_token}/accept")->assertOk();
        Notification::assertSentToTimes($owner, PushAlert::class, 1);
    }

    public function test_a_paid_invoice_reaches_whoever_handles_invoices_except_who_marked_it(): void
    {
        Notification::fake();
        $owner = $this->subscribed(User::factory()->create(['role' => 'super_admin']));
        $bookkeeper = $this->subscribed($this->teamMember(['invoices']));
        $designer = $this->subscribed($this->teamMember());
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $invoice = $company->invoices()->create(['status' => 'sent', 'issued_on' => now(), 'due_on' => now()->addDays(14)]);
        $invoice->items()->create(['description' => 'Brand refresh', 'amount' => 507.50]);

        $this->actingAs($owner)->postJson("/api/invoices/{$invoice->id}/mark-paid", ['method' => 'check'])->assertOk();

        Notification::assertSentTo($bookkeeper, PushAlert::class, fn (PushAlert $alert) => $alert->title === 'Invoice paid'
            && $alert->body === "Alder & Finch Design paid invoice #{$invoice->invoice_number}: $507.50."
            && $alert->url === "/invoices/{$invoice->id}");
        Notification::assertNotSentTo($owner, PushAlert::class);
        Notification::assertNotSentTo($designer, PushAlert::class);
    }

    public function test_a_payment_through_stripe_reaches_everyone_who_handles_invoices(): void
    {
        Notification::fake();
        $owner = $this->subscribed(User::factory()->create(['role' => 'super_admin']));
        $invoice = Company::create(['name' => 'Alder & Finch Design'])->invoices()->create(['status' => 'sent', 'issued_on' => now()]);

        $invoice->recordPayment('card', 250.00, 7.50, 'pi_test');

        Notification::assertSentTo($owner, PushAlert::class, fn (PushAlert $alert) => str_contains($alert->body, '$250.00'));
    }

    public function test_a_device_is_turned_on_tested_and_turned_off(): void
    {
        Notification::fake();
        $me = $this->teamMember();

        $this->actingAs($me)->postJson('/api/push-subscriptions/test')->assertUnprocessable();

        $this->actingAs($me)->postJson('/api/push-subscriptions', [
            'endpoint' => 'https://web.push.apple.com/abc123',
            'keys' => ['p256dh' => 'BPublicKey', 'auth' => 'authSecret'],
            'content_encoding' => 'aes128gcm',
        ])->assertOk();
        $this->assertSame(1, $me->pushSubscriptions()->count());

        $this->actingAs($me)->postJson('/api/push-subscriptions/test')->assertNoContent();
        Notification::assertSentTo($me, PushAlert::class, fn (PushAlert $alert) => $alert->tag === 'test');

        $this->actingAs($me)->deleteJson('/api/push-subscriptions', ['endpoint' => 'https://web.push.apple.com/abc123'])->assertOk();
        $this->assertSame(0, $me->pushSubscriptions()->count());
    }

    public function test_which_kinds_reach_my_devices_is_mine_to_set(): void
    {
        $me = $this->teamMember();

        $this->actingAs($me)->patchJson('/api/profile/alerts', ['push_invoices' => false])->assertOk();

        $me->refresh();
        $this->assertFalse($me->push_invoices);
        $this->assertTrue($me->push_chat);
        $this->assertTrue($me->notify_on_document_open);
    }

    public function test_the_push_carries_the_page_and_the_badge_count(): void
    {
        $me = $this->teamMember();
        $alert = new PushAlert('Invoice paid', 'Paid.', '/invoices/7', 'invoice-7');

        $payload = $alert->toWebPush($me, $alert)->toArray();

        $this->assertSame('Invoice paid', $payload['title']);
        $this->assertSame('invoice-7', $payload['tag']);
        $this->assertSame('/invoices/7', $payload['data']['url']);
        $this->assertSame(0, $payload['data']['count']);
    }

    public function test_the_installable_app_has_a_name_icon_and_start_page(): void
    {
        $this->get('/manifest.webmanifest')->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('start_url', '/my-work')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('icons.1.sizes', '512x512');

        $this->actingAs($this->teamMember())->get('/my-work')->assertSee('rel="manifest"', false);
    }
}
