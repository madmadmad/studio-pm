<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Chat's channels and direct messages: making them, finding and joining
// channels, and the same people always sharing one direct message.
class ChatConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_staff_member_creates_a_channel_and_is_in_it(): void
    {
        $user = User::factory()->teamMember()->create();

        $this->actingAs($user)->postJson('/api/chat/channels', ['name' => 'Design Crit', 'description' => 'Show your work'])
            ->assertCreated()
            ->assertJsonPath('type', 'channel')
            ->assertJsonPath('name', 'design-crit')
            ->assertJsonPath('member_count', 1);

        $channel = Conversation::where('slug', 'design-crit')->firstOrFail();
        $this->assertTrue($channel->hasMember($user));
        $this->assertSame($user->id, $channel->created_by);
    }

    public function test_channel_names_are_unique_by_slug(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/chat/channels', ['name' => 'general'])->assertCreated();

        $this->actingAs($user)->postJson('/api/chat/channels', ['name' => 'General'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->actingAs($user)->postJson('/api/chat/channels', ['name' => '!!!'])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_any_staff_member_can_browse_and_join_a_channel(): void
    {
        $creator = User::factory()->create();
        $other = User::factory()->teamMember()->create();
        $this->actingAs($creator)->postJson('/api/chat/channels', ['name' => 'general']);
        $channel = Conversation::where('slug', 'general')->firstOrFail();

        $this->actingAs($other)->getJson('/api/chat/channels')
            ->assertOk()->assertJsonPath('0.name', 'general')->assertJsonPath('0.is_member', false);

        $this->actingAs($other)->postJson("/api/chat/channels/{$channel->id}/join")->assertOk()->assertJsonPath('member_count', 2);
        $this->assertTrue($channel->fresh()->hasMember($other));

        // Twice is fine.
        $this->actingAs($other)->postJson("/api/chat/channels/{$channel->id}/join")->assertOk()->assertJsonPath('member_count', 2);
    }

    public function test_joining_a_channel_starts_caught_up(): void
    {
        $creator = User::factory()->create();
        $joiner = User::factory()->teamMember()->create();
        $this->actingAs($creator)->postJson('/api/chat/channels', ['name' => 'general']);
        $channel = Conversation::where('slug', 'general')->firstOrFail();
        $this->actingAs($creator)->postJson("/api/chat/conversations/{$channel->id}/messages", ['body' => 'Before you got here']);

        $this->actingAs($joiner)->postJson("/api/chat/channels/{$channel->id}/join")->assertJsonPath('unread', 0);
        $this->actingAs($joiner)->getJson('/api/chat/unread')->assertJsonPath('total.unread', 0);
    }

    public function test_a_member_can_leave_a_channel(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/chat/channels', ['name' => 'general']);
        $channel = Conversation::where('slug', 'general')->firstOrFail();

        $this->actingAs($user)->postJson("/api/chat/channels/{$channel->id}/leave")->assertNoContent();
        $this->assertFalse($channel->fresh()->hasMember($user));
        $this->actingAs($user)->getJson("/api/chat/conversations/{$channel->id}/messages")->assertForbidden();
    }

    public function test_a_direct_message_includes_the_starter(): void
    {
        $me = User::factory()->create();
        $alex = User::factory()->teamMember()->create();

        $this->actingAs($me)->postJson('/api/chat/direct', ['user_ids' => [$alex->id]])
            ->assertCreated()->assertJsonPath('type', 'direct')->assertJsonPath('member_count', 2);

        $conversation = Conversation::where('type', 'direct')->sole();
        $this->assertEqualsCanonicalizing([$me->id, $alex->id], $conversation->members->pluck('id')->all());
    }

    public function test_the_same_people_reuse_their_direct_message(): void
    {
        $me = User::factory()->create();
        $alex = User::factory()->teamMember()->create();
        $sam = User::factory()->teamMember()->create();

        $first = $this->actingAs($me)->postJson('/api/chat/direct', ['user_ids' => [$alex->id, $sam->id]])->assertCreated()->json('id');

        // Any of them starting it, in any order, lands in the same place.
        $this->actingAs($sam)->postJson('/api/chat/direct', ['user_ids' => [$me->id, $alex->id]])->assertOk()->assertJsonPath('id', $first);
        $this->actingAs($me)->postJson('/api/chat/direct', ['user_ids' => [$sam->id, $alex->id, $me->id]])->assertOk()->assertJsonPath('id', $first);

        // A different set of people is a different conversation.
        $this->actingAs($me)->postJson('/api/chat/direct', ['user_ids' => [$alex->id]])->assertCreated();
        $this->assertSame(2, Conversation::where('type', 'direct')->count());
    }

    public function test_a_direct_message_cannot_be_joined(): void
    {
        $me = User::factory()->create();
        $alex = User::factory()->teamMember()->create();
        $outsider = User::factory()->teamMember()->create();
        $id = $this->actingAs($me)->postJson('/api/chat/direct', ['user_ids' => [$alex->id]])->json('id');

        $this->actingAs($outsider)->postJson("/api/chat/channels/{$id}/join")->assertForbidden();
        $this->actingAs($outsider)->getJson("/api/chat/conversations/{$id}/messages")->assertForbidden();
        // (The page reads the web guard by name; after an API call, a bare
        // actingAs() only swaps the sanctum guard's user.)
        $this->actingAs($outsider, 'web')->get("/chat/{$id}")->assertForbidden();
    }

    public function test_deactivated_people_cannot_be_messaged(): void
    {
        $me = User::factory()->create();
        $gone = User::factory()->teamMember()->create(['deactivated_at' => now()]);

        $this->actingAs($me)->postJson('/api/chat/direct', ['user_ids' => [$gone->id]])->assertStatus(422);
    }

    public function test_the_sidebar_lists_only_your_conversations(): void
    {
        $me = User::factory()->create();
        $alex = User::factory()->teamMember()->create();
        $sam = User::factory()->teamMember()->create();
        $this->actingAs($me)->postJson('/api/chat/channels', ['name' => 'general']);
        $this->actingAs($alex)->postJson('/api/chat/direct', ['user_ids' => [$sam->id]]);

        $this->actingAs($me)->getJson('/api/chat/conversations')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'general');
    }

    public function test_the_chat_page_renders_for_staff(): void
    {
        $me = User::factory()->teamMember()->create();
        $this->actingAs($me)->postJson('/api/chat/channels', ['name' => 'general']);
        $channel = Conversation::where('slug', 'general')->firstOrFail();

        $this->actingAs($me)->get("/chat/{$channel->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Chat/Index', false)
                ->where('conversationId', $channel->id)
                ->has('conversations', 1)
                ->has('staff', 1));
    }
}
