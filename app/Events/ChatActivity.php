<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// "Your unread counts may have changed", on each affected person's own
// channel -- a message posted, edited or deleted where they are, or they
// read a conversation in another tab. Carries no message content: the
// front end asks /api/chat/unread for the real numbers, so a missed signal
// costs nothing but a moment's delay.
class ChatActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    const MESSAGE = 'message';

    const READ = 'read';

    public function __construct(
        public array $userIds,
        public int $conversationId,
        public string $kind,
        public ?int $authorId = null,
        public array $mentionIds = [],
    ) {}

    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel("App.Models.User.{$id}"), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'chat.activity';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'kind' => $this->kind,
            'author_id' => $this->authorId,
            'mention_ids' => $this->mentionIds,
        ];
    }
}
