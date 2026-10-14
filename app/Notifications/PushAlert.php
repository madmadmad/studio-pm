<?php

namespace App\Notifications;

use App\Support\AppBadge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

// A notification on the person's devices (web push to the installed app
// or a browser they turned it on in): a title, a line, the page it opens,
// and a tag -- a newer one with the same tag replaces it (one per chat
// conversation, proposal, invoice). Carries the icon's badge count, worked
// out when it's sent. Queued, so posting a chat message never waits on
// Apple's or Google's push servers. Who gets which is PushAlerts.
class PushAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public string $url,
        public ?string $tag = null,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        $message = (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/icons/icon-192.png')
            ->data(['url' => $this->url, 'count' => AppBadge::count($notifiable)])
            // Kept for a day if the device is off; delivered promptly.
            ->options(['TTL' => 86400, 'urgency' => 'high']);

        return $this->tag ? $message->tag($this->tag) : $message;
    }
}
