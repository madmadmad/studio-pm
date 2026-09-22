<?php

namespace App\Services;

use App\Jobs\GenerateAttachmentThumbnail;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageParticipant;
use App\Models\Project;
use App\Models\User;
use App\Notifications\NewMessageReply;
use App\Notifications\NewMessageThread;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Shared between the staff (App\Http\Controllers\MessageController) and
// client (App\Http\Controllers\Portal\MessageController) message
// endpoints, since a thread works identically regardless of whether the
// person acting is a User or a Contact -- keeping this logic in one place
// is what stops the two controllers from drifting apart.
class MessageThreadService
{
    /**
     * Validation rules for the `attachments` array on a message create/reply
     * request -- shared so the staff and portal controllers can't drift on
     * limits. Mime types are checked against the file's real detected
     * content, not the client-supplied extension.
     */
    public function attachmentValidationRules(): array
    {
        $mimes = collect(config('message_attachments.allowed_mimes'))->flatten()->unique()->values()->all();

        return [
            'attachments' => ['array', 'max:'.config('message_attachments.max_files_per_message')],
            'attachments.*' => [
                'file',
                'max:'.config('message_attachments.max_file_size_kb'),
                'mimetypes:'.implode(',', $mimes),
            ],
        ];
    }

    /**
     * Resolves ["user:3", "contact:5", ...] tokens against who's actually
     * eligible on this project -- active team members and portal-access
     * contacts at the project's company (client access is company-wide, not
     * per-project, so that's the real pool). Anything that doesn't resolve
     * throws rather than being silently dropped, since an unresolvable
     * recipient is a client error worth surfacing, not swallowing.
     *
     * @return Collection<int, User|Contact>
     */
    public function resolveRecipients(Project $project, array $tokens): Collection
    {
        $userIds = [];
        $contactIds = [];

        foreach ($tokens as $token) {
            [$type, $id] = explode(':', $token, 2);
            if ($type === 'user') {
                $userIds[] = (int) $id;
            } else {
                $contactIds[] = (int) $id;
            }
        }

        $users = $userIds
            ? $project->activeUsers()->whereIn('users.id', $userIds)->get()
            : collect();

        $contacts = $contactIds
            ? $project->company->contacts()->whereIn('id', $contactIds)->whereNotNull('portal_invited_at')->get()
            : collect();

        $resolved = $users->concat($contacts);

        abort_unless($resolved->count() === count($tokens), 422, 'One or more recipients are not eligible for this project.');

        return $resolved;
    }

    /**
     * @param  Collection<int, User|Contact>  $recipients
     * @param  UploadedFile[]  $attachments
     */
    public function createThread(Project $project, User|Contact $sender, string $subject, ?string $body, Collection $recipients, array $attachments = []): Message
    {
        return DB::transaction(function () use ($project, $sender, $subject, $body, $recipients, $attachments) {
            $thread = $project->messages()->create([
                ...$this->senderColumns($sender),
                'subject' => $subject,
                'body' => $body,
                'sent_at' => now(),
            ]);

            $this->storeAttachments($thread, $attachments);

            $this->addParticipant($thread, $sender);
            foreach ($recipients as $recipient) {
                $this->addParticipant($thread, $recipient);
            }

            // The sender never needs their own new-thread notification.
            $notifyList = $recipients->reject(fn ($r) => $this->sameActor($r, $sender));
            $this->notify($thread, $notifyList, new NewMessageThread($thread));

            return $thread;
        });
    }

    // Replying implicitly joins the thread if the author wasn't already a
    // participant -- see class docblock on Message for why "reply" and
    // "join" both just mean "add a participant row."
    /**
     * @param  UploadedFile[]  $attachments
     */
    public function reply(Message $thread, User|Contact $author, ?string $body, array $attachments = []): Message
    {
        return DB::transaction(function () use ($thread, $author, $body, $attachments) {
            $reply = $thread->replies()->create([
                'project_id' => $thread->project_id,
                ...$this->senderColumns($author),
                'body' => $body,
                'sent_at' => now(),
            ]);

            $this->storeAttachments($reply, $attachments);

            $this->addParticipant($thread, $author);

            $thread->load('participants.user', 'participants.contact');
            $recipients = $thread->participants
                ->map(fn (MessageParticipant $p) => $p->actor())
                ->filter()
                ->reject(fn ($actor) => $this->sameActor($actor, $author));

            $this->notify($thread, $recipients, new NewMessageReply($reply));

            return $reply;
        });
    }

    public function join(Message $thread, User|Contact $actor): void
    {
        $this->addParticipant($thread, $actor);
    }

    public function updateBody(Message $message, string $body): Message
    {
        $message->update(['body' => $body]);

        return $message;
    }

    // Soft-deletes the message (so it renders as "Message deleted" without
    // losing its place in the thread) but actually removes its attachment
    // files from storage -- those aren't kept around just because the
    // message row is.
    public function deleteMessage(Message $message): void
    {
        foreach ($message->attachments as $attachment) {
            Storage::disk($attachment->disk)->delete(array_filter([$attachment->path, $attachment->thumbnail_path]));
        }
        $message->attachments()->delete();
        $message->delete();
    }

    /**
     * @param  UploadedFile[]  $files
     */
    protected function storeAttachments(Message $message, array $files): void
    {
        $disk = config('filesystems.private_disk');
        $imageExtensions = config('message_attachments.image_extensions');

        foreach ($files as $file) {
            $path = $file->store('message-attachments', $disk);
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
                GenerateAttachmentThumbnail::dispatch($attachment);
            }
        }
    }

    protected function addParticipant(Message $thread, User|Contact $actor): MessageParticipant
    {
        $key = $actor instanceof User ? ['user_id' => $actor->id, 'contact_id' => null] : ['user_id' => null, 'contact_id' => $actor->id];

        return $thread->participants()->firstOrCreate($key, ['joined_at' => now()]);
    }

    /**
     * @param  iterable<User|Contact>  $recipients
     */
    protected function notify(Message $thread, iterable $recipients, $notification): void
    {
        foreach ($recipients as $recipient) {
            if (! $recipient) {
                continue;
            }

            $recipient->notify($notification);

            $key = $recipient instanceof User ? ['user_id' => $recipient->id] : ['contact_id' => $recipient->id];
            $thread->participants()->where($key)->update(['notified_at' => now()]);
        }
    }

    protected function senderColumns(User|Contact $actor): array
    {
        return $actor instanceof User
            ? ['sender_user_id' => $actor->id, 'sender_contact_id' => null]
            : ['sender_user_id' => null, 'sender_contact_id' => $actor->id];
    }

    protected function sameActor(User|Contact|null $a, User|Contact|null $b): bool
    {
        if (! $a || ! $b) {
            return false;
        }

        return get_class($a) === get_class($b) && $a->id === $b->id;
    }
}
