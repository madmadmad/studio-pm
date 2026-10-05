<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Project;
use App\Services\MessageThreadService;
use App\Services\MessageVersion;
use App\Services\UnreadMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MessageController extends Controller
{
    public function __construct(protected MessageThreadService $threads) {}

    // Every thread on the project, visible to anyone with project access
    // regardless of whether they're a participant -- discoverability is the
    // point (see Project::messages()/Message::participants()): a project
    // member can browse in and join a thread they weren't originally
    // tagged on. withTrashed() keeps a soft-deleted message in place as a
    // "Message deleted" placeholder rather than removing it from the thread.
    // Whether anything's changed since the page loaded -- polled by the
    // Messages tab (lib/useMessagePolling.js).
    public function version(Project $project)
    {
        $this->authorize('view', $project);

        return ['version' => MessageVersion::of($project->messages())];
    }

    public function index(Project $project)
    {
        $this->authorize('view', $project);

        return UnreadMessages::mark(
            $project->messages()->withTrashed()->with(Message::threadRelations())->get(),
            request()->user(),
        );
    }

    // Opening a thread marks it read (UnreadMessages).
    public function read(Request $request, Message $message)
    {
        abort_if($message->parent_id, 404);

        $this->authorize('view', $message->project);

        UnreadMessages::markRead($message, $request->user());

        return response()->noContent();
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $this->validateMessage($request, [
            'subject' => ['required', 'string', 'max:255'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['required', 'string', 'regex:/^(user|contact):\d+$/'],
        ]);

        $recipients = $this->threads->resolveRecipients($project, $data['recipients']);

        $thread = $this->threads->createThread(
            $project, $request->user(), $data['subject'], $data['body'] ?? null, $recipients, $request->file('attachments', []), $data['links'] ?? []
        );

        return $thread->load('senderUser', 'senderContact', 'participants.user', 'participants.contact', 'attachments', 'links');
    }

    public function reply(Request $request, Message $message)
    {
        abort_if($message->parent_id, 404); // replies only ever target a root thread

        $this->authorize('update', $message->project);

        $data = $this->validateMessage($request);

        $reply = $this->threads->reply($message, $request->user(), $data['body'] ?? null, $request->file('attachments', []), $data['links'] ?? []);

        return $reply->load('senderUser', 'senderContact', 'attachments', 'links');
    }

    public function update(Request $request, Message $message)
    {
        $this->authorize('update', $message);

        // Text, plus files and links taken off or added (a multipart
        // POST with _method=PATCH when files come along).
        $data = $this->threads->editValidator($request, $message)->validate();

        $this->threads->updateMessage(
            $message,
            $data['body'] ?? null,
            $request->file('attachments', []),
            $data['links'] ?? [],
            $data['remove_attachment_ids'] ?? [],
            $data['remove_link_ids'] ?? [],
        );

        return $message->fresh()->load('senderUser', 'senderContact', 'attachments', 'links');
    }

    public function destroy(Message $message)
    {
        $this->authorize('delete', $message);

        $this->threads->deleteMessage($message);

        return response()->noContent();
    }

    // Toggles the user's reaction with an emoji. Anyone who can reply to
    // the thread can react to its messages; a deleted message can't be
    // reacted to (route binding skips it, so it's a 404).
    public function react(Request $request, Message $message)
    {
        $this->authorize('update', $message->thread()->project);

        $data = $request->validate(['emoji' => ['required', Rule::in(MessageReaction::EMOJI)]]);

        return $this->threads->toggleReaction($message, $request->user(), $data['emoji']);
    }

    public function join(Request $request, Message $message)
    {
        abort_if($message->parent_id, 404);

        $this->authorize('update', $message->project);

        $this->threads->join($message, $request->user());

        return $message->load('participants.user', 'participants.contact');
    }

    // A message needs a body, attachments, or both -- never neither. Shared
    // shape between store() and reply() since both accept the same fields.
    protected function validateMessage(Request $request, array $extraRules = []): array
    {
        $rules = array_merge([
            'body' => ['nullable', 'string'],
        ], $this->threads->attachmentValidationRules(), $this->threads->linkValidationRules(), $extraRules);

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request) {
            if (! trim((string) $request->input('body')) && empty($request->file('attachments', [])) && empty($request->input('links', []))) {
                $validator->errors()->add('body', 'Add a message, a file or a link.');
            }
        });

        return $validator->validate();
    }
}
