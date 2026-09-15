<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Proposal;
use App\Notifications\ProposalAccepted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class ProposalController extends Controller
{
    public function index(Company $company)
    {
        return $company->proposals()->latest()->get();
    }

    public function store(Request $request, Company $company)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'], // rich text HTML from the editor
            'estimate_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['nullable', 'array'],
            'items.*.description' => ['required_with:items', 'string'],
            'items.*.details' => ['nullable', 'string'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.01'],
            'items.*.rate' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.service_id' => ['nullable', 'exists:services,id'],
        ]);

        $proposal = DB::transaction(function () use ($data, $company) {
            $proposal = $company->proposals()->create([
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

        return $proposal->load('items');
    }

    public function send(Proposal $proposal)
    {
        $proposal->update(['status' => 'sent', 'sent_at' => now()]);

        // Email the client a link built from $proposal->accept_token,
        // pointing at the public showPublic() route below.

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
            $proposal->update(['status' => 'accepted', 'accepted_at' => now()]);

            // Notifies you -- swap the destination for wherever you want to be reached.
            Notification::route('mail', config('mail.from.address'))
                ->notify(new ProposalAccepted($proposal));
        }

        return $proposal;
    }
}
