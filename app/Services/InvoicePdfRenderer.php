<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\StudioProfile;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class InvoicePdfRenderer
{
    /**
     * dompdf's font cache registry (storage/fonts/installed-fonts.json)
     * trusts a font is still cached once it's been registered once -- it
     * never re-checks the .ufm/.ttf file is still on disk before loading
     * it (see registerFont()'s early return in
     * vendor/dompdf/dompdf/src/FontMetrics.php). storage/fonts is
     * gitignored and rebuilt per-server, so if it's ever partially cleared
     * (disk cleanup, a bad deploy) while the registry survives, every
     * render fails permanently with an unrecoverable fopen() error until
     * someone clears the cache by hand. Detect that failure and self-heal
     * by clearing the cache and rendering once more.
     */
    public static function render(Invoice $invoice): PdfDocument
    {
        try {
            return self::build($invoice);
        } catch (Throwable $e) {
            if (! self::isStaleFontCache($e)) {
                throw $e;
            }

            Log::warning('Stale dompdf font cache, clearing storage/fonts and retrying.', [
                'invoice_id' => $invoice->id,
                'message' => $e->getMessage(),
            ]);

            File::deleteDirectory(storage_path('fonts'));

            return self::build($invoice);
        }
    }

    protected static function build(Invoice $invoice): PdfDocument
    {
        $pdf = Pdf::loadView('pdfs.invoice', [
            'invoice' => $invoice,
            'studio' => StudioProfile::current(),
        ])->setPaper('letter'); // US business -- dompdf defaults to A4

        $pdf->output(); // force rendering now so a stale cache fails here, not on ->download()/->output() later

        return $pdf;
    }

    protected static function isStaleFontCache(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'fopen(')
            && str_contains($e->getMessage(), 'storage/fonts');
    }
}
