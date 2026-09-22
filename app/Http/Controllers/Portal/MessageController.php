<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Project;
use App\Policies\Portal\MessagePolicy;
use App\Services\MessageThreadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller
{
    public function __construct(protected MessagePolicy $policy, protected MessageThreadService $threads) {}

    public function index(Request $request, Project $project)
    {
        abort_unless($this->policy->view($request->user(), $project), 403);

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
        abort_unless($this->policy->create($request->user(), $project), 403);

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
        abort_if($message->parent_id, 404);

        abort_unless($this->policy->create($request->user(), $message->project), 403);

        $data = $this->validateMessage($request);

        $reply = $this->threads->reply($message, $request->user(), $data['body'] ?? null, $request->file('attachments', []));

        return $reply->load('senderUser', 'senderContact', 'attachments');
    }

    public function update(Request $request, Message $message)
    {
        abort_unless($this->policy->update($request->user(), $message), 403);

        $data = $request->validate(['body' => ['required', 'string']]);

        $this->threads->updateBody($message, $data['body']);

        return $message->load('senderUser', 'senderContact', 'attachments');
    }

    public function destroy(Request $request, Message $message)
    {
        abort_unless($this->policy->delete($request->user(), $message), 403);

        $this->threads->deleteMessage($message);

        return response()->noContent();
    }

    public function join(Request $request, Message $message)
    {
        abort_if($message->parent_id, 404);

        abort_unless($this->policy->create($request->user(), $message->project), 403);

        $this->threads->join($message, $request->user());

        return $message->load('participants.user', 'participants.contact');
    }

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
