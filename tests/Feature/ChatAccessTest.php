<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserIsStaff;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Chat is staff only: a Client Hub contact gets nowhere -- not the page,
// not the API, not a file -- even signed in to the portal, and even in a
// browser that's also in a portal preview. (The broadcast channels:
// ChatChannelAuthTest.)
class ChatAccessTest extends TestCase
{
    use RefreshDatabase;

    private function contact(): Contact
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $contact = $company->contacts()->create(['name' => 'Rosa Alder', 'email' => 'rosa@alderfinch.co']);
        $contact->forceFill(['portal_invited_at' => now()])->save();

        return $contact;
    }

    private function channelWithMessage(): array
    {
        $staff = User::factory()->create();
        $channel = Conversation::create(['type' => 'channel', 'name' => 'general', 'slug' => 'general']);
        $channel->members()->attach($staff->id, ['joined_at' => now()]);
        $message = $channel->messages()->create(['user_id' => $staff->id, 'body' => 'Internal only']);
        $attachment = $message->attachments()->create([
            'disk' => 'local', 'path' => 'chat/x/file.pdf', 'original_name' => 'file.pdf',
            'mime_type' => 'application/pdf', 'size' => 10, 'thumbnail_status' => 'not_applicable',
        ]);

        return [$channel, $message, $attachment];
    }

    public function test_a_client_contact_is_kept_out_of_every_chat_page(): void
    {
        [$channel, , $attachment] = $this->channelWithMessage();
        $contact = $this->contact();

        foreach (['/chat', "/chat/{$channel->id}", "/chat/attachments/{$attachment->id}"] as $url) {
            $this->actingAs($contact, 'client')->get($url)->assertRedirect('/login');
        }
    }

    public function test_a_client_contact_is_kept_out_of_every_chat_api(): void
    {
        [$channel, $message] = $this->channelWithMessage();
        $contact = $this->contact();

        $requests = [
            ['get', '/api/chat/conversations'],
            ['get', '/api/chat/unread'],
            ['get', '/api/chat/channels'],
            ['post', '/api/chat/channels'],
            ['post', "/api/chat/channels/{$channel->id}/join"],
            ['post', '/api/chat/direct'],
            ['get', "/api/chat/conversations/{$channel->id}/messages"],
            ['post', "/api/chat/conversations/{$channel->id}/messages"],
            ['post', "/api/chat/conversations/{$channel->id}/read"],
            ['patch', "/api/chat/messages/{$message->id}"],
            ['delete', "/api/chat/messages/{$message->id}"],
            ['post', "/api/chat/messages/{$message->id}/reactions"],
        ];

        foreach ($requests as [$method, $url]) {
            $this->actingAs($contact, 'client')->json($method, $url, ['body' => 'hi', 'name' => 'x', 'emoji' => '👍'])->assertUnauthorized();
        }
        $this->assertSame(1, ChatMessage::count());
    }

    public function test_the_policies_refuse_a_client_contact(): void
    {
        [$channel, $message] = $this->channelWithMessage();
        $contact = $this->contact();

        $this->assertFalse(Gate::forUser($contact)->allows('viewAny', Conversation::class));
        $this->assertFalse(Gate::forUser($contact)->allows('view', $channel));
        $this->assertFalse(Gate::forUser($contact)->allows('post', $channel));
        $this->assertFalse(Gate::forUser($contact)->allows('join', $channel));
        $this->assertFalse(Gate::forUser($contact)->allows('update', $message));
        $this->assertFalse(Gate::forUser($contact)->allows('react', $message));
    }

    public function test_the_staff_middleware_refuses_a_contact_on_the_default_guard(): void
    {
        // Belt and braces: even if a Chat route ever sat behind a guard a
        // contact could pass, `staff` turns them away.
        $contact = $this->contact();
        $request = request();
        $request->setUserResolver(fn () => $contact);

        $this->expectException(HttpException::class);
        (new EnsureUserIsStaff)->handle($request, fn () => response('ok'));
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/chat')->assertRedirect('/login');
        $this->getJson('/api/chat/conversations')->assertUnauthorized();
    }

    public function test_a_deactivated_staff_member_is_refused(): void
    {
        $user = User::factory()->teamMember()->create(['deactivated_at' => now()]);

        $this->actingAs($user)->getJson('/api/chat/conversations')->assertForbidden();
    }
}
