<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ServesPrivateFile;
use App\Http\Controllers\Controller;
use App\Models\Proposal;
use App\Models\StudioProfile;
use App\Models\User;
use App\Services\ProposalPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PublicProposalController extends Controller
{
    use ServesPrivateFile;

    public function show(string $token): Response
    {
        $proposal = Proposal::with(['company', 'project', 'items.service'])
            ->where('accept_token', $token)
            ->firstOrFail();

        return Inertia::render('Public/ProposalShow', [
            'proposal' => $proposal->setAttribute('team', $proposal->teamSection($token))->setAttribute('about', $proposal->aboutSection())->setAttribute('dates', $proposal->documentDates()),
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

    // The bio photo of someone in this proposal's team section -- it's
    // private, so the client reaches it only through the proposal's link.
    public function teamPhoto(Request $request, string $token, User $user)
    {
        $proposal = Proposal::where('accept_token', $token)->firstOrFail();
        abort_unless(in_array($user->id, $proposal->team_user_ids ?? [], true) && $user->bio_photo_path, 404);

        return $this->respondWithPhoto($request, $user->bio_photo_path);
    }
}
