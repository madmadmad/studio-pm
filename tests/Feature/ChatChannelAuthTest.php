<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

// Chat's broadcast channels: a conversation's only for its members, the
// presence and per-person channels for staff, and none of them for a
// Client Hub contact.
class ChatChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The null broadcaster signs anything -- use Reverb's (Pusher's)
        // signing, which runs the channel callbacks without a server.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        // The channels were registered on the null broadcaster at boot.
        Broadcast::purge();
        require base_path('routes/channels.php');
    }

    private function channelWith(User ...$members): Conversation
    {
        $channel = Conversation::create(['type' => Conversation::TYPE_CHANNEL, 'name' => 'general', 'slug' => 'general']);
        foreach ($members as $member) {
            $channel->members()->attach($member->id, ['joined_at' => now()]);
        }

        return $channel;
    }

    private function contact(): Contact
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);
        $contact->forceFill(['portal_invited_at' => now()])->save();

        return $contact;
    }

    private function authorize(string $channel)
    {
        return $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
    }

    public function test_a_member_may_listen_to_their_conversation(): void
    {
        $member = User::factory()->teamMember()->create();
        $channel = $this->channelWith($member);

        $this->actingAs($member)->authorize("private-conversation.{$channel->id}")->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_a_staff_member_outside_the_conversation_may_not(): void
    {
        $channel = $this->channelWith(User::factory()->create());
        $outsider = User::factory()->teamMember()->create();

        $this->actingAs($outsider)->authorize("private-conversation.{$channel->id}")->assertForbidden();
    }

    public function test_presence_is_open_to_staff_and_says_who_they_are(): void
    {
        $user = User::factory()->teamMember()->create(['name' => 'Alex Rivera']);

        $response = $this->actingAs($user)->authorize('presence-chat.presence')->assertOk();

        $this->assertEquals(['user_id' => $user->id, 'user_info' => ['id' => $user->id, 'name' => 'Alex Rivera']], json_decode($response->json('channel_data'), true));
    }

    public function test_a_person_may_only_listen_to_their_own_user_channel(): void
    {
        $user = User::factory()->teamMember()->create();
        $other = User::factory()->teamMember()->create();

        $this->actingAs($user)->authorize("private-App.Models.User.{$user->id}")->assertOk();
        $this->actingAs($user)->authorize("private-App.Models.User.{$other->id}")->assertForbidden();
    }

    public function test_a_client_contact_is_refused_every_chat_channel(): void
    {
        $channel = $this->channelWith(User::factory()->create());
        $contact = $this->contact();

        foreach (["private-conversation.{$channel->id}", 'presence-chat.presence', "private-App.Models.User.{$contact->id}"] as $name) {
            $this->actingAs($contact, 'client')->authorize($name)->assertUnauthorized();
        }
    }

    public function test_a_deactivated_member_is_refused(): void
    {
        $member = User::factory()->teamMember()->create(['deactivated_at' => now()]);
        $channel = $this->channelWith($member);

        $this->actingAs($member)->authorize("private-conversation.{$channel->id}")->assertForbidden();
    }

    public function test_guests_are_refused(): void
    {
        $this->authorize('presence-chat.presence')->assertUnauthorized();
    }
}
