<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// GIFs in Chat: searched through the server (the GIPHY key stays there),
// and sent by id -- the URL saved is GIPHY's, never the browser's.
class ChatGifTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    private Conversation $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.giphy.key' => 'test-giphy-key', 'services.giphy.rating' => 'pg']);
        $this->alex = User::factory()->teamMember()->create();
        $this->channel = Conversation::create(['type' => 'channel', 'name' => 'general', 'slug' => 'general']);
        $this->channel->members()->attach($this->alex->id, ['joined_at' => now()]);
    }

    private function gif(string $id, string $rating = 'g'): array
    {
        return [
            'id' => $id,
            'title' => "Party {$id}",
            'rating' => $rating,
            'images' => [
                'fixed_width' => ['url' => "https://media.giphy.com/media/{$id}/200w.gif", 'width' => '200', 'height' => '150'],
                'downsized_medium' => ['url' => "https://media.giphy.com/media/{$id}/giphy.gif", 'width' => '480', 'height' => '360'],
            ],
        ];
    }

    public function test_search_goes_through_the_server_with_the_key_and_rating(): void
    {
        Http::fake(['api.giphy.com/v1/gifs/search*' => Http::response([
            'data' => [$this->gif('abc123')],
            'pagination' => ['offset' => 0, 'count' => 1, 'total_count' => 40],
        ])]);

        $this->actingAs($this->alex)->getJson('/api/chat/gifs?q=party')
            ->assertOk()
            ->assertJsonPath('results.0.id', 'abc123')
            ->assertJsonPath('results.0.preview_url', 'https://media.giphy.com/media/abc123/200w.gif')
            ->assertJsonPath('has_more', true)
            ->assertJsonMissingPath('results.0.api_key');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'gifs/search')
            && $request['api_key'] === 'test-giphy-key' && $request['rating'] === 'pg' && $request['q'] === 'party');
    }

    public function test_no_query_is_trending(): void
    {
        Http::fake(['api.giphy.com/v1/gifs/trending*' => Http::response(['data' => [$this->gif('hot1')], 'pagination' => ['offset' => 0, 'count' => 1, 'total_count' => 1]])]);

        $this->actingAs($this->alex)->getJson('/api/chat/gifs')->assertOk()->assertJsonPath('results.0.id', 'hot1')->assertJsonPath('has_more', false);
    }

    public function test_without_a_key_there_are_no_gifs(): void
    {
        config(['services.giphy.key' => null]);

        $this->actingAs($this->alex)->getJson('/api/chat/gifs')->assertNotFound();
        $this->actingAs($this->alex)->postJson("/api/chat/conversations/{$this->channel->id}/messages", ['gif_id' => 'abc123'])->assertNotFound();
    }

    public function test_giphy_being_down_is_a_clear_error(): void
    {
        Http::fake(['api.giphy.com/*' => Http::response('oops', 500)]);

        $this->actingAs($this->alex)->getJson('/api/chat/gifs?q=party')->assertStatus(502);
    }

    public function test_a_gif_is_sent_by_id_and_saved_with_giphys_url(): void
    {
        Http::fake(['api.giphy.com/v1/gifs/abc123*' => Http::response(['data' => $this->gif('abc123')])]);

        $this->actingAs($this->alex)->postJson("/api/chat/conversations/{$this->channel->id}/messages", [
            'gif_id' => 'abc123',
            // Ignored: only the id is taken from the browser.
            'gif' => ['url' => 'https://evil.example/tracker.gif'],
        ])->assertCreated()
            ->assertJsonPath('body', null)
            ->assertJsonPath('gif.id', 'abc123')
            ->assertJsonPath('gif.url', 'https://media.giphy.com/media/abc123/giphy.gif')
            ->assertJsonPath('gif.width', 480);
    }

    public function test_an_unknown_or_too_racy_gif_is_refused(): void
    {
        Http::fake([
            'api.giphy.com/v1/gifs/spicy1*' => Http::response(['data' => $this->gif('spicy1', 'r')]),
            'api.giphy.com/v1/gifs/missing*' => Http::response(['data' => []], 404),
        ]);

        foreach (['spicy1', 'missing', 'not-an-id!'] as $id) {
            $this->actingAs($this->alex)->postJson("/api/chat/conversations/{$this->channel->id}/messages", ['gif_id' => $id])
                ->assertStatus(422)->assertJsonValidationErrors('gif_id');
        }
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_a_gif_goes_on_its_own_not_with_files(): void
    {
        Storage::fake('local');
        Http::fake(['api.giphy.com/*' => Http::response(['data' => $this->gif('abc123')])]);

        $this->actingAs($this->alex)->postJson("/api/chat/conversations/{$this->channel->id}/messages", [
            'gif_id' => 'abc123',
            'attachments' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')],
        ])->assertStatus(422)->assertJsonValidationErrors('gif_id');
    }

    public function test_a_gif_message_can_lose_its_words_and_deleting_clears_the_gif(): void
    {
        Http::fake(['api.giphy.com/*' => Http::response(['data' => $this->gif('abc123')])]);
        $id = $this->actingAs($this->alex)->postJson("/api/chat/conversations/{$this->channel->id}/messages", ['gif_id' => 'abc123', 'body' => 'Friday!'])->json('id');

        $this->actingAs($this->alex)->patchJson("/api/chat/messages/{$id}", ['body' => ''])->assertOk()->assertJsonPath('gif.id', 'abc123');

        $this->actingAs($this->alex)->deleteJson("/api/chat/messages/{$id}")->assertJsonPath('gif', null);
        $this->assertNull(ChatMessage::withTrashed()->find($id)->gif);
    }

    public function test_a_client_contact_cannot_search(): void
    {
        $contact = Company::create(['name' => 'Alder & Finch'])->contacts()->create(['name' => 'Rosa', 'email' => 'rosa@example.com']);
        $contact->forceFill(['portal_invited_at' => now()])->save();

        $this->actingAs($contact, 'client')->getJson('/api/chat/gifs?q=x')->assertUnauthorized();
    }
}
