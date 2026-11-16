<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\StudioProfile;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use RuntimeException;

class InvoicePdfRenderer
{
    // 72 so a CSS px in the PDF views is one point. At dompdf's default
    // 96, 11px set as 8.25pt and the PDFs read a size smaller than the web
    // pages. The views' sizes are the web page's scaled to the printed
    // column: 468pt (8.5in less 1in side margins) for the page's 680px, ~0.69.
    public const DPI = 72;

    // dompdf sets each line at line-height times the font's own height
    // (ascender to descender) times this ratio, its default 1.1. Inter
    // Tight's height is 1.21em, so line-height: 1.3 printed as 1.73. This
    // cancels it out: a line-height in the views is what prints.
    public const FONT_HEIGHT_RATIO = 1 / 1.21;

    /**
     * dompdf caches the Inter Tight TTFs used by the PDFs under storage/fonts
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
        ])->setPaper('letter') // US business -- dompdf defaults to A4
            ->setOption('dpi', self::DPI)
            ->setOption('fontHeightRatio', self::FONT_HEIGHT_RATIO);
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
