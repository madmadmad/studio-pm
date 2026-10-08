<?php

namespace Tests\Feature;

use App\Events\ChatActivity;
use App\Events\ChatMessageChanged;
use App\Events\ChatMessagePosted;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Chat messages: posting, history, mentions, unread counts, editing and
// deleting your own, reactions, attachments -- and what's broadcast.
class ChatMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    private User $sam;

    private Conversation $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alex = User::factory()->teamMember()->create(['name' => 'Alex Rivera']);
        $this->sam = User::factory()->teamMember()->create(['name' => 'Sam Lee']);
        $this->channel = Conversation::create(['type' => 'channel', 'name' => 'general', 'slug' => 'general']);
        $this->channel->members()->attach([$this->alex->id => ['joined_at' => now()], $this->sam->id => ['joined_at' => now()]]);
    }

    private function send(User $as, array $data)
    {
        return $this->actingAs($as)->postJson("/api/chat/conversations/{$this->channel->id}/messages", $data);
    }

    public function test_a_member_posts_a_message(): void
    {
        $this->send($this->alex, ['body' => "  Morning all\nCoffee?  ", 'client_id' => 'tmp-1'])
            ->assertCreated()
            ->assertJsonPath('body', "Morning all\nCoffee?")
            ->assertJsonPath('client_id', 'tmp-1')
            ->assertJsonPath('user.id', $this->alex->id)
            ->assertJsonPath('deleted', false);

        $this->assertDatabaseHas('chat_messages', ['conversation_id' => $this->channel->id, 'user_id' => $this->alex->id]);
    }

    public function test_html_is_kept_as_plain_text(): void
    {
        // Stored as typed; the front end escapes it (React never renders it as HTML).
        $this->send($this->alex, ['body' => '<script>alert(1)</script>'])->assertCreated()->assertJsonPath('body', '<script>alert(1)</script>');
    }

    public function test_an_empty_message_is_refused(): void
    {
        $this->send($this->alex, ['body' => ''])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_someone_outside_the_conversation_cannot_post_or_read(): void
    {
        $outsider = User::factory()->teamMember()->create();

        $this->send($outsider, ['body' => 'Hi'])->assertForbidden();
        $this->actingAs($outsider)->getJson("/api/chat/conversations/{$this->channel->id}/messages")->assertForbidden();
    }

    public function test_posting_is_saved_then_broadcast(): void
    {
        Event::fake([ChatMessagePosted::class, ChatActivity::class]);

        $id = $this->send($this->alex, ['body' => 'Hey <@'.$this->sam->id.'>', 'client_id' => 'tmp-7'])->json('id');

        Event::assertDispatched(ChatMessagePosted::class, function (ChatMessagePosted $e) use ($id) {
            $payload = $e->broadcastWith()['message'];

            return $e->message->id === $id
                && $e->broadcastOn()[0]->name === "private-conversation.{$this->channel->id}"
                && $payload['client_id'] === 'tmp-7'
                && $payload['mention_ids'] === [$this->sam->id];
        });
        Event::assertDispatched(ChatActivity::class, fn (ChatActivity $e) => $e->kind === ChatActivity::MESSAGE
            && collect($e->broadcastOn())->pluck('name')->sort()->values()->all() === collect([$this->alex->id, $this->sam->id])->sort()->map(fn ($id) => "private-App.Models.User.{$id}")->values()->all()
            && $e->mentionIds === [$this->sam->id]);
    }

    public function test_a_failed_broadcast_does_not_lose_the_message(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'k',
            'broadcasting.connections.reverb.secret' => 's',
            'broadcasting.connections.reverb.app_id' => 'a',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1, // nothing listening
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        $this->send($this->alex, ['body' => 'Still saved'])->assertCreated();
        $this->assertDatabaseHas('chat_messages', ['body' => 'Still saved']);
    }

    public function test_mentions_are_parsed_and_only_for_members(): void
    {
        $outsider = User::factory()->teamMember()->create();

        $id = $this->send($this->alex, ['body' => "<@{$this->sam->id}> and <@{$this->sam->id}> and <@{$outsider->id}> and <@999>"])->json('id');

        $this->assertSame([$this->sam->id], ChatMessage::find($id)->mentions->pluck('id')->all());
        $this->assertSame([$this->sam->id, $outsider->id, 999], ChatMessage::mentionedIds("<@{$this->sam->id}> <@{$this->sam->id}> <@{$outsider->id}> <@999> @nobody <@x>"));
    }

    public function test_unread_counts_others_messages_after_the_last_read_and_mentions_separately(): void
    {
        $this->send($this->alex, ['body' => 'One']);
        $this->send($this->alex, ['body' => "Two <@{$this->sam->id}>"]);
        $three = $this->send($this->alex, ['body' => 'Three'])->json('id');

        $this->actingAs($this->sam)->getJson('/api/chat/unread')
            ->assertJsonPath("conversations.{$this->channel->id}.unread", 3)
            ->assertJsonPath("conversations.{$this->channel->id}.mentions", 1)
            ->assertJsonPath('total', ['unread' => 3, 'mentions' => 1]);

        // Your own messages never count.
        $this->actingAs($this->alex)->getJson('/api/chat/unread')->assertJsonPath('total', ['unread' => 0, 'mentions' => 0]);

        // Deleted ones don't either.
        $this->actingAs($this->alex)->deleteJson("/api/chat/messages/{$three}");
        $this->actingAs($this->sam)->getJson('/api/chat/unread')->assertJsonPath('total.unread', 2);
    }

    public function test_reading_clears_unread_and_never_moves_backwards(): void
    {
        Event::fake([ChatActivity::class]);
        $first = $this->send($this->alex, ['body' => 'One'])->json('id');
        $second = $this->send($this->alex, ['body' => "Two <@{$this->sam->id}>"])->json('id');

        $this->actingAs($this->sam)->postJson("/api/chat/conversations/{$this->channel->id}/read", ['message_id' => $second])
            ->assertOk()->assertJson(['unread' => 0, 'mentions' => 0]);
        Event::assertDispatched(ChatActivity::class, fn (ChatActivity $e) => $e->kind === ChatActivity::READ && $e->userIds === [$this->sam->id]);

        // An older tab reporting late doesn't bring them back.
        $this->actingAs($this->sam)->postJson("/api/chat/conversations/{$this->channel->id}/read", ['message_id' => $first])->assertJson(['unread' => 0]);
        $this->assertSame($second, $this->channel->members()->whereKey($this->sam->id)->first()->pivot->last_read_message_id);
    }

    public function test_read_must_name_a_message_in_the_conversation(): void
    {
        $other = Conversation::create(['type' => 'channel', 'name' => 'random', 'slug' => 'random']);
        $other->members()->attach($this->alex->id, ['joined_at' => now()]);
        $elsewhere = $other->messages()->create(['user_id' => $this->alex->id, 'body' => 'x']);

        $this->actingAs($this->sam)->postJson("/api/chat/conversations/{$this->channel->id}/read", ['message_id' => $elsewhere->id])->assertStatus(422);
    }

    public function test_history_pages_backwards_and_catches_up_forwards(): void
    {
        config(['chat.page_size' => 3]);
        $ids = collect(range(1, 7))->map(fn ($n) => $this->send($this->alex, ['body' => "Message {$n}"])->json('id'));

        $latest = $this->actingAs($this->sam)->getJson("/api/chat/conversations/{$this->channel->id}/messages")->assertOk();
        $this->assertSame($ids->slice(4)->values()->all(), collect($latest->json('messages'))->pluck('id')->all());
        $latest->assertJsonPath('has_more', true);

        $older = $this->actingAs($this->sam)->getJson("/api/chat/conversations/{$this->channel->id}/messages?before={$ids[4]}");
        $this->assertSame($ids->slice(1, 3)->values()->all(), collect($older->json('messages'))->pluck('id')->all());

        $oldest = $this->actingAs($this->sam)->getJson("/api/chat/conversations/{$this->channel->id}/messages?before={$ids[1]}");
        $this->assertSame([$ids[0]], collect($oldest->json('messages'))->pluck('id')->all());
        $oldest->assertJsonPath('has_more', false);

        $newer = $this->actingAs($this->sam)->getJson("/api/chat/conversations/{$this->channel->id}/messages?after={$ids[4]}");
        $this->assertSame([$ids[5], $ids[6]], collect($newer->json('messages'))->pluck('id')->all());
    }

    public function test_a_catch_up_includes_older_messages_changed_since(): void
    {
        $old = $this->send($this->alex, ['body' => 'Old'])->json('id');
        $last = $this->send($this->alex, ['body' => 'Latest'])->json('id');
        $this->travel(5)->seconds();
        $syncedAt = $this->actingAs($this->sam)->getJson("/api/chat/conversations/{$this->channel->id}/messages")->json('synced_at');

        $this->travel(5)->seconds();
        $this->actingAs($this->alex)->patchJson("/api/chat/messages/{$old}", ['body' => 'Old, edited']);

        $catchUp = $this->actingAs($this->sam)->getJson("/api/chat/conversations/{$this->channel->id}/messages?after={$last}&since=".urlencode($syncedAt));
        $catchUp->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.id', $old)->assertJsonPath('messages.0.body', 'Old, edited');
    }

    public function test_an_author_edits_their_own_message(): void
    {
        Event::fake([ChatMessageChanged::class, ChatActivity::class]);
        $id = $this->send($this->alex, ['body' => 'Teh plan'])->json('id');

        $this->actingAs($this->alex)->patchJson("/api/chat/messages/{$id}", ['body' => "The plan, <@{$this->sam->id}>"])
            ->assertOk()->assertJsonPath('body', "The plan, <@{$this->sam->id}>")->assertJsonPath('mention_ids', [$this->sam->id]);

        $this->assertNotNull(ChatMessage::find($id)->edited_at);
        Event::assertDispatched(ChatMessageChanged::class, fn (ChatMessageChanged $e) => $e->change === ChatMessageChanged::EDITED && $e->message->id === $id);
    }

    public function test_nobody_else_edits_or_deletes_it_not_even_a_super_admin(): void
    {
        $admin = User::factory()->create();
        $this->channel->members()->attach($admin->id, ['joined_at' => now()]);
        $id = $this->send($this->alex, ['body' => 'Mine'])->json('id');

        foreach ([$this->sam, $admin] as $other) {
            $this->actingAs($other)->patchJson("/api/chat/messages/{$id}", ['body' => 'Yours now'])->assertForbidden();
            $this->actingAs($other)->deleteJson("/api/chat/messages/{$id}")->assertForbidden();
        }
        $this->assertSame('Mine', ChatMessage::find($id)->body);
    }

    public function test_an_author_deletes_their_own_message_and_it_shows_as_deleted(): void
    {
        Event::fake([ChatMessageChanged::class, ChatActivity::class]);
        Storage::fake('local');
        config(['chat.attachments_disk' => 'local']);
        $id = $this->send($this->alex, ['body' => "Secret <@{$this->sam->id}>", 'attachments' => [UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf')]])->json('id');
        $path = ChatMessage::find($id)->attachments->first()->path;
        $this->actingAs($this->sam)->postJson("/api/chat/messages/{$id}/reactions", ['emoji' => '👍']);

        $this->actingAs($this->alex)->deleteJson("/api/chat/messages/{$id}")
            ->assertOk()->assertJsonPath('deleted', true)->assertJsonPath('body', null)
            ->assertJsonPath('attachments', [])->assertJsonPath('reactions', [])->assertJsonPath('mention_ids', []);

        $this->assertSoftDeleted('chat_messages', ['id' => $id]);
        Storage::disk('local')->assertMissing($path);
        Event::assertDispatched(ChatMessageChanged::class, fn (ChatMessageChanged $e) => $e->change === ChatMessageChanged::DELETED);

        // It keeps its place in the history, saying nothing.
        $this->actingAs($this->sam)->getJson("/api/chat/conversations/{$this->channel->id}/messages")
            ->assertJsonPath('messages.0.id', $id)->assertJsonPath('messages.0.deleted', true)->assertJsonPath('messages.0.body', null);

        // And can't be edited back.
        $this->actingAs($this->alex)->patchJson("/api/chat/messages/{$id}", ['body' => 'Undo'])->assertNotFound();
    }

    public function test_reactions_toggle_group_and_broadcast(): void
    {
        Event::fake([ChatMessageChanged::class]);
        $id = $this->send($this->alex, ['body' => 'Ship it?'])->json('id');

        $this->actingAs($this->alex)->postJson("/api/chat/messages/{$id}/reactions", ['emoji' => '🚀']);
        $this->actingAs($this->sam)->postJson("/api/chat/messages/{$id}/reactions", ['emoji' => '🚀'])
            ->assertOk()
            ->assertJsonPath('reactions.0.emoji', '🚀')
            ->assertJsonPath('reactions.0.count', 2)
            ->assertJsonPath('reactions.0.users.1.name', 'Sam Lee');

        $this->actingAs($this->sam)->postJson("/api/chat/messages/{$id}/reactions", ['emoji' => '🚀'])->assertJsonPath('reactions.0.count', 1);
        Event::assertDispatched(ChatMessageChanged::class, fn (ChatMessageChanged $e) => $e->change === ChatMessageChanged::REACTIONS);
    }

    public function test_reactions_are_limited_to_the_picker_and_members(): void
    {
        $outsider = User::factory()->teamMember()->create();
        $id = $this->send($this->alex, ['body' => 'Hi'])->json('id');

        $this->actingAs($this->sam)->postJson("/api/chat/messages/{$id}/reactions", ['emoji' => '🦄'])->assertStatus(422);
        $this->actingAs($outsider)->postJson("/api/chat/messages/{$id}/reactions", ['emoji' => '👍'])->assertForbidden();
    }

    public function test_attachments_upload_to_the_chat_disk_under_a_random_path(): void
    {
        Storage::fake('r2');
        config(['chat.attachments_disk' => 'r2']);

        $response = $this->send($this->alex, [
            'body' => '',
            'attachments' => [UploadedFile::fake()->image('mood board.png', 800, 600), UploadedFile::fake()->create('brief.pdf', 120, 'application/pdf')],
        ])->assertCreated()
            ->assertJsonPath('body', null)
            ->assertJsonPath('attachments.0.name', 'mood board.png')
            ->assertJsonPath('attachments.0.is_image', true)
            ->assertJsonPath('attachments.0.width', 800)
            ->assertJsonPath('attachments.1.is_image', false);

        $attachment = ChatMessage::find($response->json('id'))->attachments->first();
        $this->assertSame('r2', $attachment->disk);
        $this->assertMatchesRegularExpression('#^chat/[0-9a-f-]{36}/[A-Za-z0-9]{40}\.png$#', $attachment->path);
        Storage::disk('r2')->assertExists($attachment->path);
    }

    public function test_a_finished_thumbnail_is_announced(): void
    {
        Storage::fake('local');
        config(['chat.attachments_disk' => 'local']);
        Event::fake([ChatMessageChanged::class]);

        // The queue runs in-line in tests.
        $this->send($this->alex, ['attachments' => [UploadedFile::fake()->image('photo.jpg', 900, 900)]])
            ->assertCreated();

        Event::assertDispatched(ChatMessageChanged::class, fn (ChatMessageChanged $e) => $e->change === ChatMessageChanged::ATTACHMENTS
            && $e->broadcastWith()['message']['attachments'][0]['thumbnail_url'] !== null);
    }

    public function test_attachments_are_checked_by_type_and_size(): void
    {
        Storage::fake('local');
        config(['chat.attachments_disk' => 'local', 'chat.max_file_size_kb' => 100]);

        $this->send($this->alex, ['attachments' => [UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload')]])
            ->assertStatus(422)->assertJsonValidationErrors('attachments.0');
        $this->send($this->alex, ['attachments' => [UploadedFile::fake()->create('huge.pdf', 101, 'application/pdf')]])
            ->assertStatus(422)->assertJsonValidationErrors('attachments.0');
    }

    public function test_only_people_in_the_conversation_can_download_its_files(): void
    {
        Storage::fake('local');
        config(['chat.attachments_disk' => 'local']);
        $outsider = User::factory()->teamMember()->create();
        $id = $this->send($this->alex, ['attachments' => [UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf')]])->json('attachments.0.id');

        $this->actingAs($this->sam, 'web')->get("/chat/attachments/{$id}")->assertOk();
        $this->actingAs($outsider, 'web')->get("/chat/attachments/{$id}")->assertForbidden();
    }
}
