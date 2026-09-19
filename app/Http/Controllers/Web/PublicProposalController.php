<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Proposal;
use App\Models\StudioProfile;
use Inertia\Inertia;
use Inertia\Response;

class PublicProposalController extends Controller
{
    public function show(string $token): Response
    {
        $proposal = Proposal::with(['company', 'project', 'items.service'])
            ->where('accept_token', $token)
            ->firstOrFail();

        return Inertia::render('Public/ProposalShow', [
            'proposal' => $proposal,
            'token' => $token,
            'studio' => StudioProfile::current(),
        ]);
    }
}
