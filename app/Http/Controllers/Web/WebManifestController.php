<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\StudioProfile;
use Illuminate\Http\JsonResponse;

// The web app manifest: what Safari's "Add to Dock" (and Chrome's Install,
// and "Add to Home Screen" on a phone) calls the app, its icon, and where
// it opens -- My Work. Public, as browsers fetch it without cookies.
class WebManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $name = StudioProfile::brandName();

        return response()->json([
            'name' => $name,
            'short_name' => $name,
            'id' => '/',
            'start_url' => '/my-work',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#FFFFFF',
            'theme_color' => '#FFFFFF',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
