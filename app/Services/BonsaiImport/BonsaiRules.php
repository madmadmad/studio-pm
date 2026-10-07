<?php

namespace App\Services\BonsaiImport;

// How the Bonsai history is read (docs/ledger-plan.md, Phase 6, with the
// decisions of 2026-10-07). Kept in one place so the production run at
// cutover applies exactly what the rehearsals were checked against.
// Category names and Bonsai tag aliases live in ChartOfAccountsSeeder.
class BonsaiRules
{
    // The history starts here; earlier rows are left out.
    public const FROM = '2025-01-01';

    // Paid from checking rather than the Capital One card.
    public const CHECKING_TAGS = [
        'Wages & Commissions', 'Payroll Taxes', 'Retirement Expense', 'Payroll Processing Fees',
        'Rent & Lease Property', 'Health & Life Insurance', 'HSA fees', 'Business Insurance',
        'Local Taxes', 'Real Estate Taxes', 'Ohio State Workers\' Compensation tax', 'Taxes & Licenses',
        'Utilities', 'Draw',
    ];

    // Vendors paid by ACH or check from checking, whatever the tag (Level 2
    // Audio by check, the rest ACH). Everyone else is on the card.
    public const CHECKING_VENDORS = '/MADHOUSE FILMS|SCHOLAR HOUSE|PEACOCK SOCIAL|H\.?O\.?T\.? GRAPHICS|APLING|AARON RAJNER|ANDREW MENICH|LUETTKE|MARSHALL MELHORN|LEVEL 2 AUDIO|CLARK SCHAEFER|GRANT BEACHY|SATTLER PAINTING/i';

    // The monthly card payment, as the bank describes it.
    public const CARD_PAYMENT = '/CAPITAL ONE ONLINE PMT/i';

    // Notes that say it was a check, an ACH debit or a bank transfer.
    public const CHECKING_NOTES = '/\b(check|ach|bank transfer)\b|#\s?\d{4}\b/i';

    // Ad platforms: media buys whatever their tag (Client Media Spend when
    // billed to a client).
    public const MEDIA = '/\bADS\d{6,}\b|google ads|FACEBK|FACEBOOKAD|facebook ads/i';

    // Printers: the Printing category (Printing Cost when billed).
    public const PRINTING = '/GOTPRINT|MOO PRINT|H\.?O\.?T\.? Graphics|Invitation Printing|^Printing$/i';

    // Bonsai's own card/ACH fee on a client payment; it posts with the
    // payment instead.
    public const BONSAI_FEE = '/^Bonsai Payments Processing Fee - Invoice #(\d+)/';

    // Paying the state the sales tax collected: Sales Tax Payable, not an
    // expense.
    public const SALES_TAX_REMITTANCE = '/Sales Tax Liability/i';

    // When the bank feed doubled charges: a receipt-less copy of a charge
    // with a receipt (same day, amount and first word) is skipped.
    public const DUPLICATE_WINDOW = ['2026-04-01', '2026-06-30'];

    // Rows left out one by one, by Bonsai's expense id (from its receipt
    // link), with why.
    public const SKIP_EXPENSES = [
        '5767491' => 'Second copy of the ICHRA premium of 2025-02-21 (the bank paid it once)',
    ];

    // How long after an invoice's date an expense can still be on it.
    public const ATTACH_GRACE_DAYS = 14;

    // Invoices that are history; drafts and scheduled repeats aren't.
    public const INVOICE_STATUSES = ['paid', 'outstanding', 'overdue'];

    // Ohio's rate on the few taxable lines (printing).
    public const SALES_TAX_RATE = 7.75;

    public const SALES_TAX_NAME = 'Ohio sales tax';

    public static function isMedia(string $name): bool
    {
        return (bool) preg_match(self::MEDIA, $name);
    }

    public static function isPrinting(string $name): bool
    {
        return (bool) preg_match(self::PRINTING, $name);
    }

    // A masked bank-feed name ("************").
    public static function isMasked(string $name): bool
    {
        return trim($name, "* \t") === '';
    }

    // "Dropbox HG7Y63ZWRYYF" and "Dropbox ************" share "dropbox".
    public static function firstWord(string $name): string
    {
        preg_match('/[a-z0-9]+/', strtolower($name), $m);

        return $m[0] ?? '';
    }
}
