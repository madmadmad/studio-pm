<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Proposal;
use Inertia\Inertia;
use Inertia\Response;

class PublicProposalController extends Controller
{
    public function show(string $token): Response
    {
        $proposal = Proposal::with('company')
            ->where('accept_token', $token)
            ->firstOrFail();

        return Inertia::render('Public/ProposalShow', [
            'proposal' => $proposal,
            'token' => $token,
        ]);
    }
}
