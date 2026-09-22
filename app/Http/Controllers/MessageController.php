<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Project;
use App\Services\MessageThreadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller
{
    public function __construct(protected MessageThreadService $threads) {}

    // Every thread on the project, visible to anyone with project access
    // regardless of whether they're a participant -- discoverability is the
    // point (see Project::messages()/Message::participants()): a project
    // member can browse in and join a thread they weren't originally
    // tagged on. withTrashed() keeps a soft-deleted message in place as a
    // "Message deleted" placeholder rather than removing it from the thread.
    public function index(Project $project)
    {
        $this->authorize('view', $project);

        return $project->messages()
            ->withTrashed()
            ->with([
                'senderUser', 'senderContact', 'attachments',
                'participants.user', 'participants.contact',
                'replies' => fn ($q) => $q->withTrashed(),
                'replies.senderUser', 'replies.senderContact', 'replies.attachments',
            ])
            ->get();
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
            $project, $request->user(), $data['subject'], $data['body'] ?? null, $recipients, $request->file('attachments', [])
        );

        return $thread->load('senderUser', 'senderContact', 'participants.user', 'participants.contact', 'attachments');
    }

    public function reply(Request $request, Message $message)
    {
        abort_if($message->parent_id, 404); // replies only ever target a root thread

        $this->authorize('update', $message->project);

        $data = $this->validateMessage($request);

        $reply = $this->threads->reply($message, $request->user(), $data['body'] ?? null, $request->file('attachments', []));

        return $reply->load('senderUser', 'senderContact', 'attachments');
    }

    public function update(Request $request, Message $message)
    {
        $this->authorize('update', $message);

        $data = $request->validate(['body' => ['required', 'string']]);

        $this->threads->updateBody($message, $data['body']);

        return $message->load('senderUser', 'senderContact', 'attachments');
    }

    public function destroy(Message $message)
    {
        $this->authorize('delete', $message);

        $this->threads->deleteMessage($message);

        return response()->noContent();
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
        ], $this->threads->attachmentValidationRules(), $extraRules);

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request) {
            if (! trim((string) $request->input('body')) && empty($request->file('attachments', []))) {
                $validator->errors()->add('body', 'Add a message or attach at least one file.');
            }
        });

        return $validator->validate();
    }
}
