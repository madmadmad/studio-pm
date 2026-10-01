<?php

namespace App\Http\Controllers;

use App\Mail\ProposalEmail;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\StudioProfile;
use App\Notifications\ProposalAccepted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class ProposalController extends Controller
{
    public function index(Company $company)
    {
        return $company->proposals()->latest()->get();
    }

    protected function itemRules(): array
    {
        return [
            'items' => ['nullable', 'array'],
            'items.*.description' => ['required_with:items', 'string'],
            'items.*.details' => ['nullable', 'string'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.01'],
            'items.*.rate' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.service_id' => ['nullable', 'exists:services,id'],
        ];
    }

    // A proposal's estimate: its services' total, or empty with none.
    private function estimateFor(Proposal $proposal): ?float
    {
        $proposal->load('items');

        return $proposal->items->isEmpty() ? null : $proposal->itemsTotal();
    }

    public function store(Request $request, Company $company)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'], // rich text HTML from the editor
            'disclaimer' => ['nullable', 'string'],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('company_id', $company->id)],
            'project_id' => ['required_without:new_project_name', 'nullable', Rule::exists('projects', 'id')->where('company_id', $company->id)],
            'new_project_name' => ['required_without:project_id', 'nullable', 'string', 'max:255'],
            ...$this->itemRules(),
        ]);

        $proposal = DB::transaction(function () use ($data, $company) {
            $projectId = $data['project_id'] ?? null;

            if (! $projectId && ! empty($data['new_project_name'])) {
                $projectId = $company->projects()->create([
                    'name' => $data['new_project_name'],
                    'contact_id' => $data['contact_id'] ?? null,
                ])->id;
            }

            $proposal = $company->proposals()->create([
                'project_id' => $projectId,
                'contact_id' => $data['contact_id'] ?? null,
                'title' => $data['title'],
                'body' => $data['body'],
                // A new proposal starts from the default unless one's sent.
                'disclaimer' => array_key_exists('disclaimer', $data) ? $data['disclaimer'] : config('proposals.default_disclaimer'),
                'status' => 'draft',
            ]);

            // The estimate is always what the services add up to -- never
            // entered by hand -- and empty until there are any.
            if (! empty($data['items'])) {
                $proposal->items()->createMany($data['items']);
            }
            $proposal->update(['estimate_amount' => $this->estimateFor($proposal)]);

            return $proposal;
        });

        return $proposal->load('items', 'project');
    }

    public function update(Request $request, Proposal $proposal)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'disclaimer' => ['nullable', 'string'],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('company_id', $proposal->company_id)],
            ...$this->itemRules(),
        ]);

        // An accepted proposal's services -- and so its estimate -- are
        // locked: accepting added that estimate to the project's budget, and
        // unaccepting takes the same amount back off, so it can't drift in
        // between. Its title, scope and contact can still change.
        $servicesLocked = $proposal->status === 'accepted';
        abort_if($servicesLocked && array_key_exists('items', $data), 422, 'This proposal is accepted, so its services are locked. Unaccept it to change them.');

        DB::transaction(function () use ($data, $proposal, $servicesLocked) {
            $proposal->update([
                'contact_id' => $data['contact_id'] ?? null,
                'title' => $data['title'],
                'body' => $data['body'],
                ...(array_key_exists('disclaimer', $data) ? ['disclaimer' => $data['disclaimer']] : []),
            ]);

            if ($servicesLocked) {
                return;
            }

            // Replace the line items wholesale rather than diffing -- simpler,
            // and proposal items don't carry any state worth preserving by ID.
            $proposal->items()->delete();

            if (! empty($data['items'])) {
                $proposal->items()->createMany($data['items']);
            }
            $proposal->update(['estimate_amount' => $this->estimateFor($proposal)]);
        });

        return $proposal->fresh()->load('items', 'project');
    }

    // What the Send Proposal dialog opens with: who it goes to, and the
    // subject and message filled in from the templates (config/proposals).
    public function sendContext(Proposal $proposal)
    {
        $proposal->loadMissing('company.contacts', 'contact');
        $recipient = $proposal->recipientContact();

        return [
            'to' => $recipient?->email,
            'to_name' => $recipient?->name,
            'subject' => $this->fillTemplate(config('proposals.email_subject_template'), $proposal, $recipient),
            'message' => $this->fillTemplate(config('proposals.email_template'), $proposal, $recipient),
            'public_url' => url('/p/'.$proposal->accept_token),
        ];
    }

    // The exact HTML the client would receive, for the dialog's preview --
    // subject and message straight from its unsaved fields.
    public function emailPreview(Request $request, Proposal $proposal)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
        ]);

        $proposal->loadMissing('company', 'project');

        return ['html' => (new ProposalEmail($proposal, $data['subject'], $data['message']))->render()];
    }

    // Backs the Send Proposal dialog: email it to the client now, or share
    // its link yourself (optionally marking it sent). Either way a draft
    // becomes sent and its project moves to estimated.
    public function send(Request $request, Proposal $proposal)
    {
        $data = $request->validate([
            'method' => ['required', 'in:email,link'],
            'subject' => ['required_if:method,email', 'nullable', 'string', 'max:255'],
            'message' => ['required_if:method,email', 'nullable', 'string'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['email'],
            'send_copy_to_self' => ['boolean'],
            'mark_as_sent' => ['boolean'],
        ]);

        if ($data['method'] === 'email') {
            $proposal->loadMissing('company.contacts', 'contact');
            $recipient = $proposal->recipientContact();
            abort_unless(filled($recipient?->email), 422, "This proposal's client has no contact with an email address. Add one, or send it via its link.");

            $cc = collect($data['cc'] ?? [])->filter()->values()->all();
            if ($data['send_copy_to_self'] ?? false) {
                $cc[] = $request->user()->email;
            }

            // Queued (ProposalEmail is ShouldQueue), like invoice emails.
            Mail::to($recipient->email)->send(new ProposalEmail($proposal, $data['subject'], $data['message'], array_values(array_unique($cc))));
            $this->markSent($proposal);
        } elseif ($data['mark_as_sent'] ?? false) {
            $this->markSent($proposal);
        }

        return $proposal->fresh()->load('items', 'project');
    }

    // A draft (or re-sent proposal) becomes sent and its project estimated.
    // An accepted one is left as it is -- sharing its link again changes
    // nothing, and its project is already active.
    private function markSent(Proposal $proposal): void
    {
        if ($proposal->status === 'accepted') {
            return;
        }

        $proposal->update(['status' => 'sent', 'sent_at' => $proposal->sent_at ?? now()]);

        if ($proposal->project_id) {
            $this->moveProjectToStatus($proposal->project, 'estimated');
        }
    }

    private function fillTemplate(string $template, Proposal $proposal, ?Contact $contact): string
    {
        return strtr($template, [
            ':firm_name' => StudioProfile::current()->name,
            ':proposal_title' => $proposal->title,
            ':contact_first_name' => $contact?->name ? explode(' ', trim($contact->name))[0] : 'there',
        ]);
    }

    // Completed/archived are deliberate, manually-chosen end states --
    // sending or accepting a proposal (possibly a follow-up one, on a
    // project that's already well underway) shouldn't silently undo them.
    protected function moveProjectToStatus(Project $project, string $status): void
    {
        if (in_array($project->status, ['completed', 'archived'], true)) {
            return;
        }

        $project->update(['status' => $status]);
    }

    // The linked project is never touched here -- proposals.project_id
    // points *at* the project, so deleting a proposal can't cascade onto
    // it either way. Accepted proposals are protected instead, same as
    // paid invoices: it's the record of what was actually sold.
    public function destroy(Proposal $proposal)
    {
        abort_if($proposal->status === 'accepted', 422, 'Accepted proposals cannot be deleted.');

        $proposal->delete();

        return response()->noContent();
    }

    // Authenticated only -- reverts a mistaken or premature acceptance back
    // to sent, so the client's link still works and they can accept again.
    public function unaccept(Proposal $proposal)
    {
        DB::transaction(function () use ($proposal) {
            if ($proposal->status === 'accepted' && $proposal->project_id) {
                $proposal->project()->decrement('budget', $proposal->estimate_amount ?? 0);
            }

            $proposal->update(['status' => 'sent', 'accepted_at' => null]);
        });

        return $proposal;
    }

    // Public, unauthenticated -- the client opens this link to read the proposal.
    public function showPublic(string $token)
    {
        return Proposal::with('items.service')->where('accept_token', $token)->firstOrFail();
    }

    // Public, unauthenticated -- the client clicks Accept here.
    public function accept(string $token)
    {
        $proposal = Proposal::where('accept_token', $token)->firstOrFail();

        if ($proposal->status !== 'accepted') {
            DB::transaction(function () use ($proposal) {
                $proposal->update(['status' => 'accepted', 'accepted_at' => now()]);

                // Adds to (not replaces) the project's budget -- a project can
                // be built from multiple accepted proposals (e.g. phased work).
                if ($proposal->project_id) {
                    $proposal->project()->increment('budget', $proposal->estimate_amount ?? 0);
                    $this->moveProjectToStatus($proposal->project()->first(), 'active');
                }
            });

            // Notifies you -- swap the destination for wherever you want to be reached.
            Notification::route('mail', config('mail.from.address'))
                ->notify(new ProposalAccepted($proposal));
        }

        return $proposal;
    }
}
