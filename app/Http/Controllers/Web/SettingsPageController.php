<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\StudioProfile;
use Inertia\Inertia;
use Inertia\Response;

class SettingsPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'studioProfile' => StudioProfile::current(),
        ]);
    }
}
