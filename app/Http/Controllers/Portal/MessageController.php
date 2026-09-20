<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Notifications\ClientMessageReceived;
use App\Policies\Portal\MessagePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class MessageController extends Controller
{
    public function __construct(protected MessagePolicy $policy) {}

    public function index(Request $request, Project $project)
    {
        abort_unless($this->policy->view($request->user(), $project), 403);

        return $project->messages()->with(['replies.senderUser', 'replies.senderContact', 'senderUser', 'senderContact'])->get();
    }

    public function store(Request $request, Project $project)
    {
        abort_unless($this->policy->create($request->user(), $project), 403);

        $data = $request->validate([
            'parent_id' => ['nullable', Rule::exists('messages', 'id')->where('project_id', $project->id)->whereNull('parent_id')],
            'subject' => ['required_without:parent_id', 'nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        $contact = $request->user();

        $message = $project->messages()->create([
            'parent_id' => $data['parent_id'] ?? null,
            'direction' => 'inbound',
            'sender_contact_id' => $contact->id,
            'from_email' => $contact->email,
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
            'sent_at' => now(),
        ]);

        Notification::route('mail', config('mail.from.address'))
            ->notify(new ClientMessageReceived($message));

        return $message->load('senderUser', 'senderContact');
    }
}
