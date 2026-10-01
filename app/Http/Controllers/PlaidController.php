<?php

namespace App\Http\Controllers;

use App\Models\PlaidItem;
use App\Models\StudioProfile;
use App\Services\PlaidClient;
use App\Services\PlaidSync;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;

// Bank feeds on the Expenses page: connect a bank through Plaid Link,
// sync its charges into expenses, disconnect it.
class PlaidController extends Controller
{
    public function __construct(private PlaidClient $plaid, private PlaidSync $syncer) {}

    public function linkToken(Request $request)
    {
        abort_unless($this->plaid->isConfigured(), 422, 'Plaid isn\'t set up -- add PLAID_CLIENT_ID and PLAID_SECRET to .env.');

        return ['link_token' => $this->plaid->createLinkToken((string) $request->user()->id, StudioProfile::current()->name ?: config('app.name'))];
    }

    // Link's success: keep the bank's token and pull its charges in.
    public function store(Request $request)
    {
        $data = $request->validate([
            'public_token' => ['required', 'string'],
            'institution_name' => ['nullable', 'string', 'max:255'],
        ]);

        $token = $this->plaid->exchangePublicToken($data['public_token']);
        $item = PlaidItem::updateOrCreate(['item_id' => $token['item_id']], [
            'access_token' => $token['access_token'],
            'institution_name' => $data['institution_name'],
        ]);

        return ['item' => $item->fresh(), 'result' => $this->trySync($item)];
    }

    public function sync()
    {
        $results = PlaidItem::all()->map(fn (PlaidItem $item) => $this->trySync($item));

        return [
            'items' => PlaidItem::orderBy('institution_name')->get(),
            'result' => [
                'added' => $results->sum('added'),
                'updated' => $results->sum('updated'),
                'removed' => $results->sum('removed'),
            ],
        ];
    }

    // Stops the feed; expenses already imported stay.
    public function destroy(PlaidItem $plaidItem)
    {
        try {
            $this->plaid->removeItem($plaidItem->access_token);
        } catch (RequestException) {
            // Already gone on Plaid's side -- forget it here too.
        }
        $plaidItem->delete();

        return response()->noContent();
    }

    // A failed sync is recorded on the bank (shown in the drawer) rather
    // than failing the whole request.
    private function trySync(PlaidItem $item): array
    {
        try {
            return $this->syncer->sync($item);
        } catch (RequestException) {
            return ['added' => 0, 'updated' => 0, 'removed' => 0];
        }
    }
}
