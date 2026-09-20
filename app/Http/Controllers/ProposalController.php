<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Project;
use App\Models\Proposal;
use App\Notifications\ProposalAccepted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function store(Request $request, Company $company)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'], // rich text HTML from the editor
            'estimate_amount' => ['nullable', 'numeric', 'min:0'],
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
                'estimate_amount' => $data['estimate_amount'] ?? null,
                'status' => 'draft',
            ]);

            if (! empty($data['items'])) {
                $proposal->items()->createMany($data['items']);
                $proposal->update(['estimate_amount' => $proposal->fresh('items')->itemsTotal()]);
            }

            return $proposal;
        });

        return $proposal->load('items', 'project');
    }

    public function update(Request $request, Proposal $proposal)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'estimate_amount' => ['nullable', 'numeric', 'min:0'],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('company_id', $proposal->company_id)],
            ...$this->itemRules(),
        ]);

        DB::transaction(function () use ($data, $proposal) {
            $proposal->update([
                'contact_id' => $data['contact_id'] ?? null,
                'title' => $data['title'],
                'body' => $data['body'],
                'estimate_amount' => $data['estimate_amount'] ?? null,
            ]);

            // Replace the line items wholesale rather than diffing -- simpler,
            // and proposal items don't carry any state worth preserving by ID.
            $proposal->items()->delete();

            if (! empty($data['items'])) {
                $proposal->items()->createMany($data['items']);
                $proposal->update(['estimate_amount' => $proposal->fresh('items')->itemsTotal()]);
            }
        });

        return $proposal->fresh()->load('items', 'project');
    }

    public function send(Proposal $proposal)
    {
        $proposal->update(['status' => 'sent', 'sent_at' => now()]);

        // Email the client a link built from $proposal->accept_token,
        // pointing at the public showPublic() route below.

        if ($proposal->project_id) {
            $this->moveProjectToStatus($proposal->project, 'estimated');
        }

        return $proposal;
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
