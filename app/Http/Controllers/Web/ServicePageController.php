<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Inertia\Inertia;
use Inertia\Response;

class ServicePageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Services/Index', [
            'services' => Service::orderBy('name')->get(),
        ]);
    }
}
