<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortalProfilePageController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Portal/Profile/Index', [
            'profileContact' => $request->user(),
        ]);
    }
}
