<?php

namespace App\Services;

use App\Models\Proposal;
use App\Models\StudioProfile;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

class ProposalPdfRenderer
{
    // Same fonts as the invoice PDF, so the same font cache directory
    // has to exist first (see InvoicePdfRenderer).
    public static function render(Proposal $proposal): PdfDocument
    {
        InvoicePdfRenderer::ensureFontCacheDirectoryExists();

        return Pdf::loadView('pdfs.proposal', [
            'proposal' => $proposal,
            'studio' => StudioProfile::current(),
        ])->setPaper('letter');
    }
}
