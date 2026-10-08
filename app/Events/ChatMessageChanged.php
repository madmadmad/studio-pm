<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// A message already in the history, changed: edited, deleted, reacted to,
// or its image thumbnails ready. Carries the whole message as it now is, so
// the front end just puts it in place of the old one.
class ChatMessageChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    const EDITED = 'edited';

    const DELETED = 'deleted';

    const REACTIONS = 'reactions';

    const ATTACHMENTS = 'attachments';

    public function __construct(public ChatMessage $message, public string $change) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->message->conversation_id}")];
    }

    public function broadcastAs(): string
    {
        return 'message.changed';
    }

    public function broadcastWith(): array
    {
        return ['change' => $this->change, 'message' => $this->message->toChatArray()];
    }
}
