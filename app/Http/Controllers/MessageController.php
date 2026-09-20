<?php

namespace App\Http\Controllers;

use App\Mail\ProjectMessageMail;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class MessageController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('view', $project);

        return $project->messages()->with(['replies.senderUser', 'replies.senderContact', 'senderUser', 'senderContact'])->get();
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            // Only one level of nesting -- a reply must target a root message.
            'parent_id' => ['nullable', Rule::exists('messages', 'id')->where('project_id', $project->id)->whereNull('parent_id')],
            'subject' => ['required_without:parent_id', 'nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        $subject = $data['subject'] ?? null;
        if (! empty($data['parent_id']) && ! $subject) {
            $parentSubject = Message::find($data['parent_id'])->subject;
            $subject = $parentSubject ? "Re: {$parentSubject}" : null;
        }

        // Prefer the client's Primary contact; fall back to any contact
        // with an email on file. Companies no longer carry their own email
        // -- that lives on Contacts now.
        $contact = $project->company->contacts()->where('is_primary', true)->whereNotNull('email')->first()
            ?? $project->company->contacts()->whereNotNull('email')->first();
        $toEmail = $contact?->email;

        abort_if(! $toEmail, 422, 'This client has no contact with an email address on file to message.');

        $message = $project->messages()->create([
            'parent_id' => $data['parent_id'] ?? null,
            'direction' => 'outbound',
            'sender_user_id' => $request->user()->id,
            'to_email' => $toEmail,
            'from_email' => config('mail.from.address'),
            'subject' => $subject,
            'body' => $data['body'],
            'sent_at' => now(),
        ]);

        Mail::to($toEmail)->send(new ProjectMessageMail($message));

        return $message->load('senderUser', 'senderContact');
    }
}
