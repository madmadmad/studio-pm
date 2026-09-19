<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\StudioProfile;
use Inertia\Inertia;
use Inertia\Response;

class PublicInvoiceController extends Controller
{
    public function show(string $token): Response
    {
        $invoice = Invoice::with(['company', 'project', 'contact', 'items', 'payments'])
            ->where('public_token', $token)
            ->firstOrFail();

        return Inertia::render('Public/InvoiceShow', [
            'invoice' => $invoice,
            'studio' => StudioProfile::current(),
        ]);
    }
}
