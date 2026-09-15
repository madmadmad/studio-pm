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

        $toEmail = $project->company->email;

        abort_if(! $toEmail, 422, 'This client has no email address on file to message.');

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
