<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Proposal;
use App\Models\Service;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProposalPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Proposals/Index', [
            // Items for the proposal drawer's editor; the client list and
            // services it picks from, as on the create/edit pages.
            'proposals' => Proposal::with(['company', 'contact', 'project', 'items'])->latest()->get(),
            'companies' => Company::with(['contacts', 'projects'])->orderBy('name')->get(['id', 'name']),
            'services' => Service::orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Proposals/Form', [
            'proposal' => null,
            'companies' => Company::with(['contacts', 'projects'])->orderBy('name')->get(['id', 'name']),
            'services' => Service::orderBy('name')->get(),
            'presetCompanyId' => $request->integer('company_id') ?: null,
            'presetProjectId' => $request->integer('project_id') ?: null,
        ]);
    }

    public function edit(Proposal $proposal): Response
    {
        return Inertia::render('Proposals/Form', [
            'proposal' => $proposal->load('items', 'contact', 'project'),
            'companies' => Company::with(['contacts', 'projects'])->orderBy('name')->get(['id', 'name']),
            'services' => Service::orderBy('name')->get(),
            'presetCompanyId' => null,
            'presetProjectId' => null,
        ]);
    }
}
