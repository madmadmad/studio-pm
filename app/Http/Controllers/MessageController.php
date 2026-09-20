<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Project;
use App\Services\MessageThreadService;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(protected MessageThreadService $threads) {}

    // Every thread on the project, visible to anyone with project access
    // regardless of whether they're a participant -- discoverability is the
    // point (see Project::messages()/Message::participants()): a project
    // member can browse in and join a thread they weren't originally
    // tagged on.
    public function index(Project $project)
    {
        $this->authorize('view', $project);

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
        $this->authorize('update', $project);

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
        abort_if($message->parent_id, 404); // replies only ever target a root thread

        $this->authorize('update', $message->project);

        $data = $request->validate(['body' => ['required', 'string']]);

        $reply = $this->threads->reply($message, $request->user(), $data['body']);

        return $reply->load('senderUser', 'senderContact');
    }

    public function join(Request $request, Message $message)
    {
        abort_if($message->parent_id, 404);

        $this->authorize('update', $message->project);

        $this->threads->join($message, $request->user());

        return $message->load('participants.user', 'participants.contact');
    }
}
