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
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

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
     * @param  string[]  $links
     */
    public function createThread(Project $project, User|Contact $sender, string $subject, ?string $body, Collection $recipients, array $attachments = [], array $links = []): Message
    {
        return DB::transaction(function () use ($project, $sender, $subject, $body, $recipients, $attachments, $links) {
            $thread = $project->messages()->create([
                ...$this->senderColumns($sender),
                'subject' => $subject,
                'body' => $body,
                'sent_at' => now(),
            ]);

            $this->storeAttachments($thread, $attachments);
            $this->storeLinks($thread, $links);

            $this->addParticipant($thread, $sender);
            $this->markAuthorRead($thread, $sender);
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
     * @param  string[]  $links
     */
    public function reply(Message $thread, User|Contact $author, ?string $body, array $attachments = [], array $links = []): Message
    {
        return DB::transaction(function () use ($thread, $author, $body, $attachments, $links) {
            $reply = $thread->replies()->create([
                'project_id' => $thread->project_id,
                ...$this->senderColumns($author),
                'body' => $body,
                'sent_at' => now(),
            ]);

            $this->storeAttachments($reply, $attachments);
            $this->storeLinks($reply, $links);

            $this->addParticipant($thread, $author);
            $this->markAuthorRead($thread, $author);

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

    // Adds the actor's reaction with this emoji, or takes it back off if
    // they've already reacted with it. Returns the message's reactions.
    public function toggleReaction(Message $message, User|Contact $actor, string $emoji)
    {
        $reactor = $actor instanceof User
            ? ['user_id' => $actor->id, 'contact_id' => null]
            : ['user_id' => null, 'contact_id' => $actor->id];

        $existing = $message->reactions()->where($reactor)->where('emoji', $emoji)->first();

        if ($existing) {
            $existing->delete();
        } else {
            $message->reactions()->create([...$reactor, 'emoji' => $emoji]);
        }

        return $message->reactions()->with('user:id,name', 'contact:id,name')->get();
    }

    // An author's edit: the new text, plus files and links taken off
    // (their stored files deleted) and new ones added. Shared by the staff
    // and portal message controllers, which validate it with
    // editValidator() first.
    /**
     * @param  UploadedFile[]  $newFiles
     * @param  string[]  $newLinks
     */
    public function updateMessage(Message $message, ?string $body, array $newFiles = [], array $newLinks = [], array $removeAttachmentIds = [], array $removeLinkIds = []): Message
    {
        return DB::transaction(function () use ($message, $body, $newFiles, $newLinks, $removeAttachmentIds, $removeLinkIds) {
            $message->update(['body' => $body]);

            foreach ($message->attachments()->whereIn('id', $removeAttachmentIds)->get() as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->storedPaths());
                $attachment->delete();
            }
            $message->links()->whereIn('id', $removeLinkIds)->delete();

            $this->storeAttachments($message, $newFiles);
            $this->storeLinks($message, array_diff($newLinks, $message->links()->pluck('url')->all()));

            return $message;
        });
    }

    // Validates an edit: what's added follows the usual file and link
    // rules, what's taken off must be this message's own, and something --
    // text, a file or a link -- has to be left.
    public function editValidator(Request $request, Message $message): ValidatorContract
    {
        $rules = [
            'body' => ['nullable', 'string'],
            'remove_attachment_ids' => ['nullable', 'array'],
            'remove_attachment_ids.*' => ['integer'],
            'remove_link_ids' => ['nullable', 'array'],
            'remove_link_ids.*' => ['integer'],
            ...$this->attachmentValidationRules(),
            ...$this->linkValidationRules(),
        ];

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request, $message) {
            $keptFiles = $message->attachments()->whereNotIn('id', $request->input('remove_attachment_ids', []))->count();
            $keptLinks = $message->links()->whereNotIn('id', $request->input('remove_link_ids', []))->count();
            $hasSomething = trim((string) $request->input('body')) !== ''
                || $keptFiles > 0 || $keptLinks > 0
                || ! empty($request->file('attachments', [])) || ! empty($request->input('links', []));

            if (! $hasSomething) {
                $validator->errors()->add('body', 'A message needs text, a file or a link.');
            }
        });

        return $validator;
    }

    // Soft-deletes the message (so it renders as "Message deleted" without
    // losing its place in the thread) but actually removes its attachment
    // files from storage -- those aren't kept around just because the
    // message row is.
    public function deleteMessage(Message $message): void
    {
        foreach ($message->attachments as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->storedPaths());
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

    // Links shared with the message (a Dropbox or Drive link), kept as
    // given -- validated as http(s) URLs by linkValidationRules().
    protected function storeLinks(Message $message, array $links): void
    {
        foreach (array_values(array_unique($links)) as $url) {
            $message->links()->create(['url' => $url]);
        }
    }

    // Shared by the staff and portal message controllers.
    public function linkValidationRules(): array
    {
        return [
            'links' => ['nullable', 'array', 'max:10'],
            'links.*' => ['required', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    protected function addParticipant(Message $thread, User|Contact $actor): MessageParticipant
    {
        $key = $actor instanceof User ? ['user_id' => $actor->id, 'contact_id' => null] : ['user_id' => null, 'contact_id' => $actor->id];

        return $thread->participants()->firstOrCreate($key, ['joined_at' => now()]);
    }

    // Writing to a thread is reading it: the author has seen everything
    // up to their own message (UnreadMessages).
    protected function markAuthorRead(Message $thread, User|Contact $author): void
    {
        UnreadMessages::markRead($thread, $author);
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
