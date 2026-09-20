<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Project;
use App\Policies\Portal\MessagePolicy;
use App\Services\MessageThreadService;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(protected MessagePolicy $policy, protected MessageThreadService $threads) {}

    public function index(Request $request, Project $project)
    {
        abort_unless($this->policy->view($request->user(), $project), 403);

        return $project->messages()
            ->with([
                'senderUser', 'senderContact',
                'participants.user', 'participants.contact',
                'replies.senderUser', 'replies.senderContact',
            ])
            ->get();
    }

    public function store(Request $request, Project $project)
    {
        abort_unless($this->policy->create($request->user(), $project), 403);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['required', 'string', 'regex:/^(user|contact):\d+$/'],
        ]);

        $recipients = $this->threads->resolveRecipients($project, $data['recipients']);

        $thread = $this->threads->createThread($project, $request->user(), $data['subject'], $data['body'], $recipients);

        return $thread->load('senderUser', 'senderContact', 'participants.user', 'participants.contact');
    }

    public function reply(Request $request, Message $message)
    {
        abort_if($message->parent_id, 404);

        abort_unless($this->policy->create($request->user(), $message->project), 403);

        $data = $request->validate(['body' => ['required', 'string']]);

        $reply = $this->threads->reply($message, $request->user(), $data['body']);

        return $reply->load('senderUser', 'senderContact');
    }

    public function join(Request $request, Message $message)
    {
        abort_if($message->parent_id, 404);

        abort_unless($this->policy->create($request->user(), $message->project), 403);

        $this->threads->join($message, $request->user());

        return $message->load('participants.user', 'participants.contact');
    }
}
