<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Invoice;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\PushAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

// Who gets which notification on their devices (PushAlert). Only people
// who've turned notifications on for a device (a push subscription) and
// left that kind switched on in Profile (push_chat, push_proposals,
// push_invoices). Never the person who did the thing.
class PushAlerts
{
    // A chat message: in a direct message, to the others in it; in a
    // channel, only to those it mentions. Not to anyone who muted it.
    public static function chatMessage(ChatMessage $message): void
    {
        $conversation = $message->conversation;
        $mentioned = $message->mentions->pluck('id');
        $members = $conversation->members()
            ->wherePivot('muted', false)
            ->where('users.id', '!=', $message->user_id)
            ->get()
            ->filter(fn (User $user) => $conversation->type === 'direct' || $mentioned->contains($user->id));

        $author = $message->user?->name ?? 'Someone';
        $title = $conversation->type === 'direct' ? $author : "{$author} in #{$conversation->name}";

        self::send($members, 'push_chat', new PushAlert(
            $title,
            self::chatPreview($message),
            "/chat/{$conversation->id}",
            "chat-{$conversation->id}",
        ));
    }

    // A client accepted a proposal: everyone who handles proposals.
    public static function proposalAccepted(Proposal $proposal): void
    {
        $client = $proposal->company?->name ?? 'A client';
        $amount = $proposal->estimate_amount ? ' ($'.number_format((float) $proposal->estimate_amount, 2).')' : '';

        self::send(User::withPermission('proposals'), 'push_proposals', new PushAlert(
            'Proposal accepted',
            "{$client} accepted “{$proposal->title}”{$amount}.",
            "/proposals/{$proposal->id}/edit",
            "proposal-{$proposal->id}",
        ));
    }

    // An invoice was paid (by card or ACH through Stripe, or marked paid):
    // everyone who handles invoices, less whoever marked it.
    public static function invoicePaid(Invoice $invoice, float $amount, ?User $recordedBy = null): void
    {
        $client = $invoice->company?->name ?? 'A client';
        $recipients = User::withPermission('invoices')->reject(fn (User $user) => $user->id === $recordedBy?->id);

        self::send($recipients, 'push_invoices', new PushAlert(
            'Invoice paid',
            "{$client} paid invoice #{$invoice->invoice_number}: $".number_format($amount, 2).'.',
            "/invoices/{$invoice->id}",
            "invoice-{$invoice->id}",
        ));
    }

    // To each person with that kind on and a device to send to.
    private static function send(Collection $users, string $preference, PushAlert $alert): void
    {
        $users->filter(fn (User $user) => $user->{$preference} !== false && $user->pushSubscriptions()->exists())
            ->each(fn (User $user) => rescue(fn () => $user->notify($alert), report: true));
    }

    // The message as a line of text: mentions as @Name, an attachment or
    // GIF said in words when there's no text.
    private static function chatPreview(ChatMessage $message): string
    {
        $names = User::whereKey(ChatMessage::mentionedIds($message->body))->pluck('name', 'id');
        $text = preg_replace_callback(ChatMessage::MENTION_PATTERN, fn ($m) => '@'.($names[(int) $m[1]] ?? 'someone'), (string) $message->body);
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)));

        return match (true) {
            $text !== '' => Str::limit($text, 140),
            (bool) $message->gif => 'Sent a GIF',
            default => 'Sent an attachment',
        };
    }
}
