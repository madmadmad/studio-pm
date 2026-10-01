<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PlaidItem;
use App\Models\StudioProfile;
use App\Services\PlaidClient;
use Inertia\Inertia;
use Inertia\Response;

class SettingsPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'studioProfile' => StudioProfile::current(),
            // Bank feeds, and whether Plaid's keys are in .env at all.
            'plaidItems' => PlaidItem::orderBy('institution_name')->get(),
            'plaidConfigured' => app(PlaidClient::class)->isConfigured(),
        ]);
    }
}
