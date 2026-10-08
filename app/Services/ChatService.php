<?php

namespace App\Services;

use App\Events\ChatActivity;
use App\Events\ChatMessageChanged;
use App\Events\ChatMessagePosted;
use App\Jobs\GenerateAttachmentThumbnail;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Everything that changes Chat. The database is the record: each change is
// saved first and only then announced over the socket, and an announcement
// that fails (Reverb down) is reported but never fails the request --
// clients catch up from the API when they reconnect or come back to the tab.
class ChatService
{
    public function createChannel(User $creator, string $name, ?string $description): Conversation
    {
        $channel = Conversation::create([
            'type' => Conversation::TYPE_CHANNEL,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $description,
            'created_by' => $creator->id,
        ]);
        $this->join($channel, $creator);

        return $channel;
    }

    // The conversation between exactly these people (the starter always
    // included) -- the existing one when they've talked before.
    public function findOrCreateDirect(User $starter, array $userIds): Conversation
    {
        $ids = array_values(array_unique([$starter->id, ...array_map('intval', $userIds)]));
        $key = Conversation::directKey($ids);

        $conversation = Conversation::createOrFirst(['direct_key' => $key], [
            'type' => Conversation::TYPE_DIRECT,
            'created_by' => $starter->id,
        ]);

        if ($conversation->wasRecentlyCreated) {
            $conversation->members()->attach(collect($ids)->mapWithKeys(fn (int $id) => [$id => ['joined_at' => now()]])->all());
        }

        return $conversation;
    }

    // Joining starts you caught up: what was said before isn't unread.
    public function join(Conversation $conversation, User $user): void
    {
        if ($conversation->hasMember($user)) {
            return;
        }

        try {
            $conversation->members()->attach($user->id, [
                'joined_at' => now(),
                'last_read_message_id' => $conversation->messages()->withTrashed()->max('id'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Joined in another tab a moment ago.
        }
        $conversation->unsetRelation('members');
    }

    public function leave(Conversation $conversation, User $user): void
    {
        $conversation->members()->detach($user->id);
        $conversation->unsetRelation('members');
    }

    /**
     * @param  UploadedFile[]  $files
     */
    public function post(Conversation $conversation, User $author, ?string $body, array $files = [], ?string $clientId = null): ChatMessage
    {
        $message = DB::transaction(function () use ($conversation, $author, $body, $files) {
            $message = $conversation->messages()->create(['user_id' => $author->id, 'body' => $this->cleanBody($body)]);
            $this->syncMentions($message, $conversation);
            $this->storeAttachments($message, $files);
            $conversation->touch();

            return $message;
        });

        // Writing is reading.
        $this->markRead($conversation, $author, $message->id, announce: false);

        $message->load(ChatMessage::displayRelations());
        $this->announce(new ChatMessagePosted($message, $clientId));
        $this->announceActivity($conversation, $message);

        return $message;
    }

    public function edit(ChatMessage $message, ?string $body): ChatMessage
    {
        DB::transaction(function () use ($message, $body) {
            $message->update(['body' => $this->cleanBody($body), 'edited_at' => now()]);
            $this->syncMentions($message, $message->conversation);
        });

        $message->load(ChatMessage::displayRelations());
        $this->announce(new ChatMessageChanged($message, ChatMessageChanged::EDITED));
        $this->announceActivity($message->conversation, $message);

        return $message;
    }

    // Kept in the history as "deleted", with nothing left of what it said:
    // its files, reactions and mentions go with it.
    public function delete(ChatMessage $message): ChatMessage
    {
        DB::transaction(function () use ($message) {
            foreach ($message->attachments as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->storedPaths());
            }
            $message->attachments()->delete();
            $message->reactions()->delete();
            $message->mentions()->detach();
            $message->delete();
        });

        $message->load(ChatMessage::displayRelations());
        $this->announce(new ChatMessageChanged($message, ChatMessageChanged::DELETED));
        $this->announceActivity($message->conversation, $message);

        return $message;
    }

    // On if this person hasn't reacted with this emoji yet, off if they have.
    public function toggleReaction(ChatMessage $message, User $user, string $emoji): ChatMessage
    {
        $existing = $message->reactions()->where('user_id', $user->id)->where('emoji', $emoji)->first();

        if ($existing) {
            $existing->delete();
        } else {
            try {
                $message->reactions()->create(['user_id' => $user->id, 'emoji' => $emoji]);
            } catch (UniqueConstraintViolationException) {
                // A double click: it's on, as asked.
            }
        }
        // So a catch-up (changes since a time) picks it up.
        $message->touch();

        $message->load(ChatMessage::displayRelations());
        $this->announce(new ChatMessageChanged($message, ChatMessageChanged::REACTIONS));

        return $message;
    }

    // Read up to this message. Never moves backwards (an older tab catching
    // up late doesn't un-read what a newer one saw).
    public function markRead(Conversation $conversation, User $user, int $messageId, bool $announce = true): void
    {
        $updated = DB::table('conversation_user')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', $messageId))
            ->update(['last_read_message_id' => $messageId, 'updated_at' => now()]);

        if ($updated && $announce) {
            $this->announce(new ChatActivity([$user->id], $conversation->id, ChatActivity::READ));
        }
    }

    // Everyone in the conversation re-counts their unreads.
    protected function announceActivity(Conversation $conversation, ChatMessage $message): void
    {
        $this->announce(new ChatActivity(
            $conversation->members()->pluck('users.id')->all(),
            $conversation->id,
            ChatActivity::MESSAGE,
            $message->user_id,
            $message->trashed() ? [] : $message->mentions->pluck('id')->all(),
        ));
    }

    // event(), not broadcast(): broadcast() sends from its destructor, after
    // rescue() has already let go.
    protected function announce(object $event): void
    {
        rescue(fn () => event($event));
    }

    // Plain text, trimmed; blank is nothing.
    protected function cleanBody(?string $body): ?string
    {
        $body = trim(str_replace("\r\n", "\n", (string) $body));

        return $body === '' ? null : $body;
    }

    // Only people in the conversation can be mentioned in it.
    protected function syncMentions(ChatMessage $message, Conversation $conversation): void
    {
        $ids = ChatMessage::mentionedIds($message->body);
        $members = $ids ? $conversation->members()->whereIn('users.id', $ids)->pluck('users.id')->all() : [];

        $message->mentions()->sync($members);
    }

    /**
     * @param  UploadedFile[]  $files
     */
    protected function storeAttachments(ChatMessage $message, array $files): void
    {
        $disk = config('chat.attachments_disk');
        $imageExtensions = config('message_attachments.image_extensions');

        foreach ($files as $file) {
            // A random folder and a random file name: nothing to guess.
            $path = $file->store('chat/'.Str::uuid(), $disk);
            $isImage = in_array(strtolower($file->getClientOriginalExtension()), $imageExtensions, true);
            [$width, $height] = $isImage ? (@getimagesize($file->getRealPath()) ?: [null, null]) : [null, null];

            $attachment = $message->attachments()->create([
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'width' => $width,
                'height' => $height,
                'thumbnail_status' => $isImage ? 'pending' : 'not_applicable',
            ]);

            if ($isImage) {
                GenerateAttachmentThumbnail::dispatch($attachment)->afterCommit();
            }
        }
    }
}
