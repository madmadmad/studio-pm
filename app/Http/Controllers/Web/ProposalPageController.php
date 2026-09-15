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
            'proposals' => Proposal::with('company')->latest()->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Proposals/Form', [
            'proposal' => null,
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'services' => Service::orderBy('name')->get(),
        ]);
    }

    public function edit(Proposal $proposal): Response
    {
        return Inertia::render('Proposals/Form', [
            'proposal' => $proposal->load('items'),
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'services' => Service::orderBy('name')->get(),
        ]);
    }
}
