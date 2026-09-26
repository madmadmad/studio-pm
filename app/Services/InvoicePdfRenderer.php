<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\StudioProfile;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use RuntimeException;

class InvoicePdfRenderer
{
    /**
     * dompdf caches the Inter TTFs used by pdfs.invoice under storage/fonts
     * (font_dir/font_cache in config/dompdf.php's defaults). That directory
     * is gitignored as a whole (.gitignore: "/storage/fonts", not
     * "/storage/fonts/*"), so a fresh deploy never creates it, and neither
     * dompdf nor the barryvdh package ever calls mkdir() for it -- the
     * package's own config comments just assume it "must exist and be
     * writable". Without it, the first @font-face registration fails with
     * an unrecoverable fopen(..., "w+") error. Ensure it exists before
     * every render rather than relying on it having been provisioned by
     * hand on each server.
     */
    public static function render(Invoice $invoice): PdfDocument
    {
        self::ensureFontCacheDirectoryExists();

        return Pdf::loadView('pdfs.invoice', [
            'invoice' => $invoice,
            'studio' => StudioProfile::current(),
        ])->setPaper('letter'); // US business -- dompdf defaults to A4
    }

    // Public so ProposalPdfRenderer, which uses the same fonts, can share it.
    public static function ensureFontCacheDirectoryExists(): void
    {
        $dir = storage_path('fonts');

        // @-suppressed: concurrent requests can race to create this on a
        // cold server, and a losing mkdir() ("File exists") is expected,
        // not an error -- the is_dir() check below is what actually matters.
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create dompdf font cache directory: {$dir}");
        }
    }
}
