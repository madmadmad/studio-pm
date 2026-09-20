<?php

namespace App\Http\Controllers;

use App\Mail\ProjectMessageMail;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class MessageController extends Controller
{
    public function index(Project $project)
    {
        return $project->messages;
    }

    public function store(Request $request, Project $project)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        // Prefer the client's Primary contact; fall back to any contact
        // with an email on file. Companies no longer carry their own email
        // -- that lives on Contacts now.
        $contact = $project->company->contacts()->where('is_primary', true)->whereNotNull('email')->first()
            ?? $project->company->contacts()->whereNotNull('email')->first();
        $toEmail = $contact?->email;

        abort_if(! $toEmail, 422, 'This client has no contact with an email address on file to message.');

        $message = $project->messages()->create([
            'direction' => 'outbound',
            'to_email' => $toEmail,
            'from_email' => config('mail.from.address'),
            'subject' => $data['subject'],
            'body' => $data['body'],
            'sent_at' => now(),
        ]);

        Mail::to($toEmail)->send(new ProjectMessageMail($message));

        return $message;
    }
}
