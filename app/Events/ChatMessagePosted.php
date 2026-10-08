<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// A new message, to everyone with the conversation open -- the author too,
// whose tab swaps its optimistic copy (matched by client_id) for this one.
// Sent during the request, not queued: live delivery doesn't wait on a
// queue worker.
class ChatMessagePosted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public ChatMessage $message, public ?string $clientId = null) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->message->conversation_id}")];
    }

    public function broadcastAs(): string
    {
        return 'message.posted';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message->toChatArray($this->clientId)];
    }
}
