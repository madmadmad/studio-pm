<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Proposal;
use App\Models\Service;
use Inertia\Inertia;
use Inertia\Response;

class ProposalPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Proposals/Index', [
            'proposals' => Proposal::with(['company', 'items'])->latest()->get(),
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'services' => Service::orderBy('name')->get(),
        ]);
    }
}
