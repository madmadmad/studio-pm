<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PlaidItem;
use App\Models\StudioProfile;
use App\Services\EmailTemplates;
use App\Services\PlaidClient;
use Inertia\Inertia;
use Inertia\Response;

class SettingsPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'studioProfile' => StudioProfile::current(),
            // The logo's two versions, and which are uploaded (vs the bundled one).
            'logos' => [
                'logo' => StudioProfile::current()->logoUrl(),
                'logo_dark' => StudioProfile::current()->logoDarkUrl(),
                'custom_light' => (bool) StudioProfile::current()->logo_path,
                'custom_dark' => (bool) StudioProfile::current()->logo_dark_path,
            ],
            // The notification emails: label, who gets it, placeholders, defaults.
            'emailTemplates' => EmailTemplates::definitions(),
            // Bank feeds, and whether Plaid's keys are in .env at all.
            'plaidItems' => PlaidItem::orderBy('institution_name')->get(),
            'plaidConfigured' => app(PlaidClient::class)->isConfigured(),
        ]);
    }
}
