<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Proposal;
use App\Models\StudioProfile;
use App\Services\ProposalPdfRenderer;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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

    // The download icon on the public page, gated by the same token.
    public function pdf(string $token): HttpResponse
    {
        $proposal = Proposal::with(['company', 'project', 'items'])
            ->where('accept_token', $token)
            ->firstOrFail();

        $name = Str::slug($proposal->title) ?: 'proposal';

        return ProposalPdfRenderer::render($proposal)->download("proposal-{$name}.pdf");
    }
}
