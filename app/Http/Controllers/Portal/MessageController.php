<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Project;
use App\Policies\Portal\MessagePolicy;
use App\Services\MessageThreadService;
use App\Services\UnreadMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MessageController extends Controller
{
    public function __construct(protected MessagePolicy $policy, protected MessageThreadService $threads) {}

    public function index(Request $request, Project $project)
    {
        abort_unless($this->policy->view($request->user(), $project), 403);

        return UnreadMessages::mark(
            $project->messages()->includingContact($request->user())->withTrashed()->with(Message::threadRelations())->get(),
            $request->user(),
        );
    }

    // Opening a thread marks it read (UnreadMessages) -- only threads this
    // contact is on. (A staff preview of the portal can't: it's read-only,
    // so a manager looking around doesn't mark the client's messages read.)
    public function read(Request $request, Message $message)
    {
        abort_if($message->parent_id, 404);
        abort_unless($message->isParticipant($request->user()), 404);

        UnreadMessages::markRead($message, $request->user());

        return response()->noContent();
    }

    public function store(Request $request, Project $project)
    {
        abort_unless($this->policy->create($request->user(), $project), 403);

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
        abort_if($message->parent_id, 404);

        abort_unless($this->policy->viewThread($request->user(), $message), 403);

        $data = $this->validateMessage($request);

        $reply = $this->threads->reply($message, $request->user(), $data['body'] ?? null, $request->file('attachments', []), $data['links'] ?? []);

        return $reply->load('senderUser', 'senderContact', 'attachments', 'links');
    }

    public function update(Request $request, Message $message)
    {
        abort_unless($this->policy->update($request->user(), $message), 403);

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

    public function destroy(Request $request, Message $message)
    {
        abort_unless($this->policy->delete($request->user(), $message), 403);

        $this->threads->deleteMessage($message);

        return response()->noContent();
    }

    // Toggles the client's reaction with an emoji, on a thread they're
    // included on (as for replying).
    public function react(Request $request, Message $message)
    {
        abort_unless($this->policy->viewThread($request->user(), $message), 403);

        $data = $request->validate(['emoji' => ['required', Rule::in(MessageReaction::EMOJI)]]);

        return $this->threads->toggleReaction($message, $request->user(), $data['emoji']);
    }

    // No join() -- a client only ever sees threads they were included on
    // (see MessagePolicy::viewThread), so there's nothing for them to join.

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
