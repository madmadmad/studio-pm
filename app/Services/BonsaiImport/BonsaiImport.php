<?php

namespace App\Services\BonsaiImport;

use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\InvoiceCategory;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Service;
use App\Services\Ledger;
use App\Services\LedgerReports\Balances;
use App\Services\LedgerReports\ProfitAndLoss;
use App\Services\LedgerReports\TrialBalance;
use App\Services\Posting\ExpensePoster;
use App\Services\Posting\PaymentPoster;
use App\Support\Money;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

// Brings the Bonsai history into the app (docs/ledger-plan.md, Phase 6):
// clients, contacts and projects, expenses, invoices with their lines,
// payments, and the few rows that are journal entries rather than
// expenses (sales tax payments, distributions). Everything posts to the
// ledger as it's created.
//
// Reads Bonsai's expense and invoice CSV exports and the invoice line
// items pulled through the Bonsai connector (JSON Lines). Rules are in
// BonsaiRules. All in one transaction: without $commit it's rolled back at
// the end -- a full rehearsal, ledger included, that changes nothing.
// Records already imported (ImportRecords) are skipped, so it can run
// again safely.
class BonsaiImport
{
    private ImportRecords $records;

    private ImportReport $report;

    /** @var array<string, ExpenseCategory> */
    private array $categories = [];

    /** @var array<string, int> lowercase service name => id */
    private array $services = [];

    /** @var array<int|string, Company> Bonsai company id => company */
    private array $companiesByBonsai = [];

    /** @var array<string, Company> normalized name => company */
    private array $companiesByName = [];

    /** @var array<string, Project> "company id:normalized name" => project */
    private array $projectsByName = [];

    /** @var array<string, int> invoice number => fee in cents, from Bonsai's online payments */
    private array $bonsaiFees = [];

    /** @var list<array{expense: Expense, company: int, project: string, name: string, price: int, date: string, line: string, taken: bool}> */
    private array $billable = [];

    /** @var array<string, string> "company id:normalized project" => last invoice date */
    private array $lastInvoiced = [];

    /** @var array<int, string> company id => last invoice date */
    private array $lastInvoicedByCompany = [];

    /** @var list<Expense> */
    private array $expenses = [];

    /** @var list<Payment> */
    private array $payments = [];

    /** @var list<array{date: string, balance: int}> the bank's balance after each row it exported */
    private array $bankBalances = [];

    public function __construct(private Ledger $ledger) {}

    // $bankCsv is checking's export from the bank (the card payments come
    // from it, and the rest is matched against the ledger); $cardCsvs the
    // card's exports, matched likewise; $opening the balances the day
    // before $from, as decimals by account handle ('checking' =>
    // '200000.00', 'capital_one_card' => ...).
    public function run(string $expensesCsv, string $invoicesCsv, string $itemsJsonl, string $from = BonsaiRules::FROM, bool $commit = false, ?string $bankCsv = null, array $opening = [], array $cardCsvs = []): ImportReport
    {
        $this->report = new ImportReport;
        $expenseRows = $this->readCsv($expensesCsv);
        $invoiceRows = collect($this->readCsv($invoicesCsv))
            ->keyBy(fn (array $row) => (int) Str::afterLast($row['contractor_invoice_link'], '/'))
            ->all();
        $invoices = $this->readJsonl($itemsJsonl);
        $bankRows = $bankCsv ? $this->readCsv($bankCsv) : [];
        $statements = array_filter([
            'checking' => StatementMatch::rows($bankRows),
            'capital_one_card' => StatementMatch::rows(array_merge(...array_map(fn ($csv) => $this->readCsv($csv), $cardCsvs))),
        ]);

        DB::beginTransaction();
        try {
            $this->records = new ImportRecords;
            $this->prepare();
            $this->bonsaiFees($invoiceRows);
            $this->companies($invoices, $invoiceRows);
            $this->importExpenses($expenseRows, $from);
            $this->importInvoices($invoices, $invoiceRows, $from);
            $this->settleUnbilled();
            $this->openingBalances($opening, $from);
            $this->cardPayments($bankRows);
            $this->postAndMatch($statements, $from);
            $this->summarize($invoiceRows, $from, $opening, $statements);
            $commit ? DB::commit() : DB::rollBack();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->report;
    }

    private function prepare(): void
    {
        $this->categories = ExpenseCategory::all()->keyBy('name')->all();
        $this->services = Service::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [strtolower($name) => $id])->all();
    }

    // ---- Clients, contacts, projects ----------------------------------

    private function companies(array $invoices, array $invoiceRows): void
    {
        foreach ($invoices as $invoice) {
            $row = $invoiceRows[$invoice['bonsai_id']] ?? [];
            $name = trim($row['client_or_company_name'] ?? '') ?: trim((string) $invoice['client_name']);
            $company = $this->company("company:{$invoice['company_id']}", $name);
            $this->companiesByBonsai[$invoice['company_id']] = $company;

            if ($email = strtolower(trim((string) $invoice['client_email']))) {
                $this->contact($company, trim((string) $invoice['client_name']) ?: $email, $email);
            }
        }
    }

    private function company(string $key, string $name): Company
    {
        $norm = $this->norm($name);
        $company = $this->records->find($key, Company::class) ?? $this->companiesByName[$norm] ?? null;
        if (! $company) {
            $company = Company::create(['name' => $name]);
            $this->report->count('Clients');
        }
        if (! $this->records->has($key)) {
            $this->records->remember($key, $company);
        }

        return $this->companiesByName[$norm] ??= $company;
    }

    private function companyNamed(string $name): Company
    {
        return $this->companiesByName[$this->norm($name)] ?? $this->company('company-name:'.$this->norm($name), trim($name));
    }

    private function contact(Company $company, string $name, string $email): void
    {
        $key = "contact:{$company->id}:{$email}";
        if ($this->records->has($key)) {
            return;
        }
        $contact = $company->contacts()->whereRaw('lower(email) = ?', [$email])->first();
        if (! $contact) {
            $first = ! $company->contacts()->exists();
            $contact = $company->contacts()->create(['name' => $name, 'email' => $email, 'is_primary' => $first, 'is_billing' => $first]);
            $this->report->count('Contacts');
        }
        $this->records->remember($key, $contact);
    }

    private function project(Company $company, string $name, ?int $bonsaiId): Project
    {
        $byName = "{$company->id}:".$this->norm($name);
        $key = $bonsaiId ? "project:{$bonsaiId}" : "project-name:{$byName}";
        $project = $this->records->find($key, Project::class) ?? $this->projectsByName[$byName] ?? null;
        if (! $project) {
            $project = Project::create(['company_id' => $company->id, 'name' => $name, 'status' => 'completed']);
            $this->report->count('Projects');
        }
        if (! $this->records->has($key)) {
            $this->records->remember($key, $project);
        }

        return $this->projectsByName[$byName] ??= $project;
    }

    // ---- Expenses -------------------------------------------------------

    private function importExpenses(array $rows, string $from): void
    {
        $sameDay = collect($rows)->groupBy(fn (array $r) => $r['date'].'|'.$this->cents($r['amount_after_tax']));
        $seen = [];

        foreach ($rows as $r) {
            $line = $this->describe($r);
            $hash = sha1(implode('|', [$r['date'], $r['name'], $r['amount_after_tax'], $r['tags'], $r['client'], $r['project'], $r['notes'], $r['receipt']]));
            $key = "expense:{$hash}:".($seen[$hash] = ($seen[$hash] ?? -1) + 1);
            if ($this->records->has($key)) {
                $this->report->count('Expenses already imported (skipped)');

                continue;
            }
            if ($r['date'] < $from) {
                $this->report->skip("Before {$from}", $line);

                continue;
            }

            if (preg_match('#/expenses/(\d+)/#', $r['receipt'], $m) && isset(BonsaiRules::SKIP_EXPENSES[$m[1]])) {
                $this->report->skip(BonsaiRules::SKIP_EXPENSES[$m[1]], $line);

                continue;
            }

            $others = $sameDay[$r['date'].'|'.$this->cents($r['amount_after_tax'])]->reject(fn (array $o) => $o === $r);
            if (BonsaiRules::isMasked($r['name'])) {
                if ($others->contains(fn (array $o) => BonsaiRules::isMedia($o['name']))) {
                    $this->report->skip('Masked bank-feed copy of a Google Ads charge', $line);

                    continue;
                }
                $this->report->review('Masked name with no matching charge', $line);
            }
            [$windowFrom, $windowTo] = BonsaiRules::DUPLICATE_WINDOW;
            if ($r['receipt'] === '' && $r['date'] >= $windowFrom && $r['date'] <= $windowTo
                && $others->contains(fn (array $o) => $o['receipt'] !== '' && BonsaiRules::firstWord($o['name']) === BonsaiRules::firstWord($r['name']))) {
                $this->report->skip('Receipt-less copy of a charge with a receipt (bank feed)', $line);

                continue;
            }

            $cents = $this->cents($r['amount_after_tax']);
            if (preg_match(BonsaiRules::BONSAI_FEE, $r['name'], $m)) {
                if (abs(($this->bonsaiFees[$m[1]] ?? -100) - $cents) <= 1) {
                    $this->report->skip('Bonsai payment fee (posted with its payment)', $line);

                    continue;
                }
                $this->report->review('Bonsai fee with no matching payment (imported as a fee)', $line);
            }

            $tag = trim($r['tags']);
            $alias = ChartOfAccountsSeeder::BONSAI_ALIASES[$tag] ?? null;
            $paidFrom = $this->paidFrom($r);
            $date = $r['date'];

            if (preg_match(BonsaiRules::SALES_TAX_REMITTANCE, $r['name'])) {
                $this->journal($key, $date, "Sales tax payment ({$r['name']})", [
                    ['account' => 'sales_tax_payable', 'debit_cents' => $cents],
                    ['account' => 'checking', 'credit_cents' => $cents],
                ], 'Sales tax payments (to Sales Tax Payable)');

                continue;
            }
            if ($alias === ChartOfAccountsSeeder::DISTRIBUTION) {
                $this->journal($key, $date, "Distribution: {$r['name']}", [
                    ['account' => 'shareholder_distributions', 'debit_cents' => $cents],
                    ['account' => $paidFrom, 'credit_cents' => $cents],
                ], 'Distributions (Draw, Personal)');

                continue;
            }
            if (in_array($alias, [ChartOfAccountsSeeder::JOURNAL_ONLY, ChartOfAccountsSeeder::REVIEW, ChartOfAccountsSeeder::REPORT], true)) {
                $this->report->skip("Bonsai tag \"{$tag}\" (for a person to enter)", $line);

                continue;
            }

            $categoryName = BonsaiRules::isMedia($r['name']) ? 'Advertising' : (BonsaiRules::isPrinting($r['name']) ? 'Printing' : (is_string($alias) ? $alias : $tag));
            $category = $this->categories[$categoryName] ?? null;
            if (! $category) {
                $this->report->review('Tag with no category (posts to Uncategorized)', $line);
            }

            $company = trim($r['client']) !== '' ? $this->companyNamed($r['client']) : null;
            $media = BonsaiRules::isMedia($r['name']);
            $billable = $company && ($media || $r['billable'] === 'true');
            if (! $company && $r['billable'] === 'true') {
                $this->report->review('Billable with no client (imported as ours)', $line);
            }
            $projectName = trim($r['project']) ?: ($billable ? 'Rebilled expenses' : '');
            $project = $company && $projectName !== '' ? $this->project($company, $projectName, null) : null;

            $price = $this->cents($r['sales_price']) ?: $cents;
            $markup = $cents > 0 && $price !== $cents ? round(($price / $cents - 1) * 100, 2) : 0;

            if ($billable && $category && ! $category->billable_account_id && ! $category->revenue_account_id && $category->account?->parent?->system_key === 'operating_expenses') {
                $this->report->review("Billable but tagged \"{$tag}\" (an operating expense)", $line);
            }
            if ($tag === 'Wages & Commissions') {
                $this->report->review('Wages to split out officer compensation', $line);
            }
            if (stripos($r['notes'], 'personal') !== false) {
                $this->report->review('Notes mention personal funds', $line);
            }

            $expense = Expense::create([
                'name' => $r['name'],
                'amount' => Money::fromCents($cents),
                'currency' => $r['currency'] ?: 'USD',
                'category_id' => $category?->id,
                'project_id' => $project?->id,
                'is_billable' => $billable,
                'markup_percent' => $markup,
                'date' => $date,
                'billing_status' => 'unbilled',
                'paid_from_account_id' => $paidFrom->id,
                'source_label' => 'Bonsai',
                'notes' => trim(implode("\n", array_filter([trim($r['notes']), $r['receipt'] !== '' ? "Bonsai receipt: {$r['receipt']}" : null]))) ?: null,
            ]);
            $this->records->remember($key, $expense);
            $this->expenses[] = $expense;
            $this->report->count('Expenses');

            if ($billable) {
                $this->billable[] = [
                    'expense' => $expense, 'company' => $company->id, 'project' => $this->norm($projectName),
                    'name' => $this->norm($r['name']), 'price' => $price, 'date' => $date, 'line' => $line, 'taken' => false,
                ];
            }
        }
    }

    private function paidFrom(array $r): Account
    {
        $checking = in_array(trim($r['tags']), BonsaiRules::CHECKING_TAGS, true)
            || preg_match(BonsaiRules::CHECKING_NOTES, $r['notes'])
            || preg_match(BonsaiRules::CHECKING_VENDORS, $r['name'])
            || preg_match(BonsaiRules::SALES_TAX_REMITTANCE, $r['name']);

        return Account::forKey($checking ? 'checking' : 'capital_one_card');
    }

    private function journal(string $key, string $date, string $memo, array $lines, string $countAs): void
    {
        $entry = $this->ledger->post($date, $lines, $memo);
        $this->records->remember($key, $entry);
        $this->report->count($countAs);
    }

    // ---- Invoices -------------------------------------------------------

    private function bonsaiFees(array $invoiceRows): void
    {
        foreach ($invoiceRows as $row) {
            if (in_array($row['payment_method'], ['credit_card', 'ach_transfer'], true)) {
                $this->bonsaiFees[$row['invoice_number']] = $this->cents($row['paid_amount']) - $this->cents($row['paid_amount_after_fees']);
            }
        }
    }

    private function importInvoices(array $invoices, array $invoiceRows, string $from): void
    {
        usort($invoices, fn ($a, $b) => [$a['issued_date'], $a['bonsai_id']] <=> [$b['issued_date'], $b['bonsai_id']]);
        $taken = array_flip(Invoice::whereNotNull('invoice_number')->pluck('invoice_number')->all());
        $nextFree = max(array_merge([999], array_keys($taken), array_map(fn ($i) => (int) $i['invoice_number'], $invoices))) + 1;
        $hosting = InvoiceCategory::where('name', 'Hosting')->first();

        foreach ($invoices as $inv) {
            $row = $invoiceRows[$inv['bonsai_id']] ?? null;
            $label = "#{$inv['invoice_number']} {$inv['issued_date']} ".($row['client_or_company_name'] ?? '').' '.($row['contractor_project_name'] ?? '')." \${$inv['total_amount']}";
            $key = "invoice:{$inv['bonsai_id']}";
            if ($this->records->has($key)) {
                $this->report->count('Invoices already imported (skipped)');

                continue;
            }
            if (! $row) {
                $this->report->review('Invoice missing from the CSV (not imported)', $label);

                continue;
            }
            if (! in_array($row['status'], BonsaiRules::INVOICE_STATUSES, true)) {
                $this->report->skip("Invoice {$row['status']} in Bonsai", $label);

                continue;
            }
            if (max($inv['issued_date'], substr($row['paid_date'], 0, 10)) < $from) {
                $this->report->skip("Before {$from}", $label);

                continue;
            }

            $company = $this->companiesByBonsai[$inv['company_id']];
            $projectName = trim($row['contractor_project_name']);
            $isHosting = str_starts_with($projectName, 'Hosting');
            $project = $isHosting ? null : $this->project($company, $projectName ?: 'Bonsai invoices', $inv['project_id'] ? (int) $inv['project_id'] : null);
            if ($project && in_array($row['status'], ['outstanding', 'overdue'], true) && $project->status !== 'active') {
                $project->update(['status' => 'active']);
            }

            $number = (int) $inv['invoice_number'];
            $legacy = null;
            if (isset($taken[$number])) {
                $legacy = $inv['invoice_number'].'-1';
                $number = $nextFree++;
                $this->report->review('Bonsai number already used (kept as its reference)', "{$label} → #{$number}, reference {$legacy}");
            }
            $taken[$number] = true;

            $days = Carbon::parse($inv['issued_date'])->diffInDays(Carbon::parse($inv['due_date']));
            $invoice = new Invoice([
                'company_id' => $company->id,
                'project_id' => $project?->id,
                'category_id' => $isHosting ? $hosting?->id : null,
                'contact_id' => $company->contacts()->whereRaw('lower(email) = ?', [strtolower((string) $inv['client_email'])])->value('id'),
                'status' => $row['status'] === 'paid' ? 'paid' : 'sent',
                'issued_on' => $inv['issued_date'],
                'due_on' => $inv['due_date'],
                'payment_terms' => match ((int) $days) {
                    0 => 'due_on_receipt', 15 => 'net_15', 30 => 'net_30', 45 => 'net_45', 60 => 'net_60', 90 => 'net_90', default => 'custom'
                },
                'sent_at' => $inv['sent_date'] ? Carbon::parse($inv['sent_date']) : Carbon::parse($inv['issued_date']),
            ]);
            $invoice->forceFill(['invoice_number' => $number, 'legacy_number' => $legacy])->save();
            $this->records->remember($key, $invoice);
            $this->report->count($row['status'] === 'paid' ? 'Invoices, paid' : 'Invoices, open');

            $lines = $this->lines($invoice, $inv, $company, $projectName);
            $lines = $this->fillGap($invoice, $lines, $row, $company, $projectName, $label);
            $this->tax($invoice, $lines, $row, $label);

            $invoice->unsetRelations();
            $totalCents = $this->cents($row['total_amount']);
            if (abs(Money::toCents($invoice->total()) - $totalCents) > 1) {
                $this->report->review('Invoice total differs from Bonsai', "{$label} → app ".number_format($invoice->total(), 2));
            }
            if ($row['status'] === 'paid' && $totalCents > 0) {
                $this->payment($invoice, $row, $totalCents, $label);
            }

            $projectKey = $company->id.':'.$this->norm($projectName);
            $this->lastInvoiced[$projectKey] = $inv['issued_date'];
            $this->lastInvoicedByCompany[$company->id] = max($this->lastInvoicedByCompany[$company->id] ?? '', $inv['issued_date']);
        }
    }

    /** @return list<InvoiceItem> */
    private function lines(Invoice $invoice, array $inv, Company $company, string $projectName): array
    {
        $lines = [];
        foreach ($inv['items'] as $i => $item) {
            $description = trim((string) $item['description']);
            $name = trim((string) $item['name']) ?: (Str::limit(strtok($description, "\n") ?: '', 80) ?: 'Item');
            $cents = $this->cents($item['total']);
            $quantity = rtrim(rtrim((string) $item['quantity'], '0'), '.');
            $rate = number_format((float) $item['rate'], 2);
            $howMuch = match ($item['unit_type']) {
                'per_hour' => "{$quantity} hours at \${$rate}/hour",
                'per_item' => (float) $item['quantity'] != 1.0 ? "{$quantity} × \${$rate}" : null,
                default => null,
            };

            $line = $invoice->items()->create([
                'description' => Str::limit($name, 250),
                'details' => trim(implode("\n", array_filter([$description !== $name ? $description : '', $howMuch]))) ?: null,
                'amount' => Money::fromCents($cents),
                'position' => $i,
                'service_id' => $this->services[strtolower($name)] ?? null,
            ]);

            if ($match = $this->takeBillable($company->id, $this->norm($name), $cents, $inv['issued_date'])) {
                $this->bill($match, $invoice, $line);
            } else {
                $line->update(['revenue_account_id' => $this->lineRevenue($name, $item['unit_type'], $projectName)]);
            }
            $lines[] = $line;
        }

        return $lines;
    }

    // Where a line's income goes when it isn't a rebilled expense
    // (whose category says): late fees, media, printing, hosting, time on
    // an ad invoice (ad management), and everything else design and
    // development.
    private function lineRevenue(string $name, string $unit, string $projectName): int
    {
        $key = match (true) {
            (bool) preg_match('/^late fee/i', $name) => 'late_fee_income',
            BonsaiRules::isMedia($name) => 'client_media_revenue',
            BonsaiRules::isPrinting($name) => 'printing_revenue',
            str_starts_with(strtolower($name), 'hosting') => 'hosting_revenue',
            $unit === 'per_hour' && $projectName === 'Digital Advertising' => 'ad_management_revenue',
            default => 'service_revenue',
        };

        return Account::forKey($key)->id;
    }

    // The open billable expense a line names: same client, name and price,
    // the most recent on or before the invoice -- identical charges ($500
    // of Google Ads) recur every month, and the oldest open one belongs to
    // an earlier invoice.
    private function takeBillable(int $companyId, string $name, int $cents, string $issued): ?int
    {
        $found = null;
        foreach ($this->billable as $i => $b) {
            if (! $b['taken'] && $b['company'] === $companyId && $b['name'] === $name && $b['price'] === $cents && $b['date'] <= $this->attachableUntil($issued)
                && ($found === null || $b['date'] > $this->billable[$found]['date'])) {
                $found = $i;
            }
        }

        return $found;
    }

    // Bonsai lets an expense dated a little after the invoice go on it (a
    // printer's bill that lands the next day).
    private function attachableUntil(string $issued): string
    {
        return Carbon::parse($issued)->addDays(BonsaiRules::ATTACH_GRACE_DAYS)->toDateString();
    }

    // Indexes of the expenses (index => cents) adding up to $target
    // exactly, trying the most recent first; null when none do. Bounded so a
    // long list can't run away.
    private function subsetSum(array $prices, int $target): ?array
    {
        $reach = [0 => []];
        foreach (array_slice($prices, 0, 80, true) as $i => $cents) {
            foreach ($reach as $sum => $set) {
                $next = $sum + $cents;
                if ($next <= $target && ! isset($reach[$next])) {
                    $reach[$next] = [...$set, $i];
                    if ($next === $target) {
                        return $reach[$next];
                    }
                }
            }
            if (count($reach) > 200000) {
                return null;
            }
        }

        return null;
    }

    private function bill(int $i, Invoice $invoice, InvoiceItem $line): void
    {
        $this->billable[$i]['taken'] = true;
        $this->billable[$i]['expense']->update([
            'invoice_id' => $invoice->id,
            'invoice_item_id' => $line->id,
            'billing_status' => $invoice->status === 'paid' ? 'billed_and_paid' : 'billed',
        ]);
        $this->report->count('Billable expenses matched to their invoice line');
    }

    // Bonsai's API leaves out expenses attached to an invoice outside its
    // lines. Rebuild them from the client's unbilled billable expenses since
    // the last invoice for the project (or the client); if nothing adds up
    // to the gap, one "Rebilled expenses" line keeps the total right.
    /** @param list<InvoiceItem> $lines */
    private function fillGap(Invoice $invoice, array $lines, array $row, Company $company, string $projectName, string $label): array
    {
        $gap = $this->cents($row['total_amount']) - $this->cents($row['calculated_tax_amount']) - array_sum(array_map(fn ($l) => Money::toCents($l->amount), $lines));
        if (abs($gap) <= 1) {
            return $lines;
        }

        if ($gap < 0) {
            $lines[] = $invoice->items()->create(['description' => 'Discount', 'amount' => Money::fromCents($gap), 'position' => count($lines), 'revenue_account_id' => Account::forKey('service_revenue')->id]);
            $this->report->review('Discount line added to match the total', $label);

            return $lines;
        }

        $issued = $invoice->issued_on->toDateString();
        $open = array_filter($this->billable, fn ($b) => ! $b['taken'] && $b['company'] === $company->id && $b['date'] <= $this->attachableUntil($issued));
        uasort($open, fn ($a, $b) => $b['date'] <=> $a['date']);
        $candidates = [
            array_filter($open, fn ($b) => $b['project'] === $this->norm($projectName)),
            $open,
        ];
        foreach ($candidates as $pool) {
            $found = $pool ? $this->subsetSum(array_map(fn ($b) => $b['price'], $pool), $gap) : null;
            if ($found !== null) {
                foreach ($found as $i) {
                    $b = $this->billable[$i];
                    $line = $invoice->items()->create(['description' => $b['expense']->name, 'amount' => Money::fromCents($b['price']), 'position' => count($lines)]);
                    $this->bill($i, $invoice, $line);
                    $lines[] = $line;
                }
                $this->report->count('Invoices whose attached expenses were rebuilt');

                return $lines;
            }
        }

        $lines[] = $invoice->items()->create([
            'description' => 'Rebilled expenses',
            'amount' => Money::fromCents($gap),
            'position' => count($lines),
            'revenue_account_id' => Account::forKey($projectName === 'Digital Advertising' ? 'client_media_revenue' : 'service_revenue')->id,
        ]);
        $this->report->review('Attached expenses not found (one "Rebilled expenses" line)', "{$label}: ".Money::fromCents($gap));

        return $lines;
    }

    // Sales tax: Ohio's rate on the lines that make up the tax Bonsai
    // charged (it doesn't say which lines were taxable).
    /** @param list<InvoiceItem> $lines */
    private function tax(Invoice $invoice, array $lines, array $row, string $label): void
    {
        $tax = $this->cents($row['calculated_tax_amount']);
        if ($tax <= 0) {
            return;
        }
        $invoice->update(['tax_name' => BonsaiRules::SALES_TAX_NAME, 'tax_rate' => BonsaiRules::SALES_TAX_RATE]);

        $amounts = array_map(fn ($l) => Money::toCents($l->amount), $lines);
        $n = count($amounts);
        for ($mask = 1; $n <= 16 && $mask < (1 << $n); $mask++) {
            $sum = 0;
            for ($i = 0; $i < $n; $i++) {
                $sum += ($mask >> $i & 1) ? $amounts[$i] : 0;
            }
            if ((int) round($sum * BonsaiRules::SALES_TAX_RATE / 100) === $tax) {
                foreach ($lines as $i => $line) {
                    if ($mask >> $i & 1) {
                        $line->update(['taxable' => true]);
                    }
                }

                return;
            }
        }
        $this->report->review('Taxable lines not found', $label);
    }

    private function payment(Invoice $invoice, array $row, int $totalCents, string $label): void
    {
        $method = match ($row['payment_method']) {
            'credit_card' => 'card',
            'ach_transfer' => 'ach',
            default => 'other',
        };
        $online = $method !== 'other';
        $paid = $this->cents($row['paid_amount']);
        $extra = max(0, $paid - $totalCents);
        $fee = $online ? $paid - $this->cents($row['paid_amount_after_fees']) : 0;
        if ($paid < $totalCents) {
            $this->report->review('Paid less than the total (recorded as paid in full)', $label);
        }
        if (! $online && $extra > 0) {
            $this->report->count('Payments with a late fee');
        }

        $payment = $invoice->payments()->create([
            'method' => $method,
            'amount' => Money::fromCents($totalCents),
            'surcharge_amount' => Money::fromCents($online ? $extra : 0),
            'late_fee' => Money::fromCents($online ? 0 : $extra),
            'stripe_fee' => $fee > 0 ? Money::fromCents($fee) : null,
            'paid_at' => Carbon::parse($row['paid_date'])->timezone(config('app.timezone')),
        ]);
        $this->payments[] = $payment;
        $this->report->count('Payments');
    }

    // Billable expenses no invoice line was found for: billed already if
    // the client has been invoiced since, otherwise still to bill.
    private function settleUnbilled(): void
    {
        foreach ($this->billable as $b) {
            if ($b['taken']) {
                continue;
            }
            if ($b['date'] <= ($this->lastInvoicedByCompany[$b['company']] ?? '')) {
                $b['expense']->update(['billing_status' => 'billed_and_paid']);
                $this->report->review('Billable expense not found on an invoice (marked billed)', $b['line']);
            } else {
                $this->report->count('Billable expenses still to bill');
            }
        }
    }

    // ---- Opening balances and the bank -----------------------------------

    // What checking had and the card owed the day before the history
    // starts, against Opening Balance Equity. (Checking's opening $200,000
    // was the partnership's money moved in; the CPA may reclassify it as
    // shareholder capital.)
    private function openingBalances(array $opening, string $from): void
    {
        $date = Carbon::parse($from)->subDay()->toDateString();
        foreach ($opening as $key => $amount) {
            $cents = $this->cents((string) $amount);
            if ($cents === 0 || $this->records->has("opening:{$key}")) {
                continue;
            }
            $account = Account::forKey($key);
            $lines = $account->isDebitNormal()
                ? [['account' => $account, 'debit_cents' => $cents], ['account' => 'opening_balance_equity', 'credit_cents' => $cents]]
                : [['account' => 'opening_balance_equity', 'debit_cents' => $cents], ['account' => $account, 'credit_cents' => $cents]];
            $this->journal("opening:{$key}", $date, "Opening balance: {$account->name}", $lines, 'Opening balances');
        }
    }

    // The monthly Capital One payments from checking, from the bank's
    // export: transfers, not expenses. Also keeps the bank's running
    // balance to check the ledger against. (The bank's other rows are
    // matched against the ledger once it's posted.)
    private function cardPayments(array $rows): void
    {
        foreach (StatementMatch::rows($rows) as $row) {
            ['date' => $date, 'cents' => $cents] = $row;
            if ($row['balance'] !== null) {
                $this->bankBalances[] = ['date' => $date, 'balance' => $row['balance']];
            }
            if (! preg_match(BonsaiRules::CARD_PAYMENT, $row['description']) || $cents >= 0) {
                continue;
            }
            $key = "bank:{$date}:{$cents}:{$row['description']}";
            if ($this->records->has($key)) {
                $this->report->count('Card payments already imported (skipped)');

                continue;
            }
            $this->journal($key, $date, 'Capital One payment', [
                ['account' => 'capital_one_card', 'debit_cents' => -$cents],
                ['account' => 'checking', 'credit_cents' => -$cents],
            ], 'Card payments from checking (transfers)');
        }
    }

    // ---- Ledger ---------------------------------------------------------

    // Posts everything, then matches the ledger against the statements.
    // An expense on the card that the card never charged, with the same
    // amount leaving checking (or the other way round), was paid from the
    // other account: it's moved and everything posted again -- inside a
    // savepoint, so the history has no reversals from the import.
    private function postAndMatch(array $statements, string $from): void
    {
        DB::beginTransaction();
        $this->post();
        $matches = $this->match($statements, $from);
        $moves = $this->misplaced($matches);
        if ($moves === []) {
            DB::commit();
            $this->reportMatches($matches);

            return;
        }

        DB::rollBack();
        foreach ($moves as [$expense, $key, $why]) {
            $expense->update(['paid_from_account_id' => Account::forKey($key)->id]);
            $this->report->review($key === 'checking' ? 'Moved to paid from checking (the bank paid it, not the card)' : 'Moved to paid from the card (the card paid it, not the bank)', $why);
        }
        $this->post();
        $this->reportMatches($this->match($statements, $from));
    }

    /** @return array<string, StatementMatch> */
    private function match(array $statements, string $from): array
    {
        return collect($statements)->map(fn (array $rows, string $key) => StatementMatch::run(Account::forKey($key), $rows, $from))->all();
    }

    // Expenses left over on one account whose amount is left over on the
    // other's statement, the closest date first.
    /** @param array<string, StatementMatch> $matches */
    private function misplaced(array $matches): array
    {
        if (count($matches) < 2) {
            return [];
        }

        $moves = [];
        foreach (['capital_one_card' => 'checking', 'checking' => 'capital_one_card'] as $on => $to) {
            $free = $matches[$to]->statementLeft;
            foreach ($matches[$on]->ledgerLeft as $left) {
                $expense = $left['line']->entry->source;
                if (! $expense instanceof Expense) {
                    continue;
                }
                $best = null;
                foreach ($free as $i => $row) {
                    $days = abs(Carbon::parse($row['date'])->diffInDays(Carbon::parse($left['date'])));
                    if ($row['cents'] === $left['cents'] && $days <= StatementMatch::MAX_DAYS && ($best === null || $days < $best[1])) {
                        $best = [$i, $days];
                    }
                }
                if ($best !== null) {
                    $row = $free[$best[0]];
                    unset($free[$best[0]]);
                    $moves[] = [$expense, $to, sprintf('%s %s $%s → %s %s', $left['date'], $expense->name, number_format(-$left['cents'] / 100, 2), $row['date'], $row['description'])];
                }
            }
        }

        return $moves;
    }

    /** @param array<string, StatementMatch> $matches */
    private function reportMatches(array $matches): void
    {
        $money = fn (int $cents) => ($cents < 0 ? '-$' : '$').number_format(abs($cents) / 100, 2);
        foreach ($matches as $key => $match) {
            $name = $key === 'checking' ? 'checking' : 'the card';
            $this->report->count("Statement rows matched to the ledger ({$name})", $match->matched);
            foreach ($match->statementLeft as $row) {
                $this->report->review("On {$name}'s statement, not in the ledger", sprintf('%s %12s  %s', $row['date'], $money($row['cents']), $row['description']));
            }
            foreach ($match->ledgerLeft as $row) {
                $this->report->review("In the ledger for {$name}, not on its statement", sprintf('%s %12s  #%d %s', $row['date'], $money($row['cents']), $row['line']->entry->entry_number, $row['description']));
            }
        }
    }

    private function post(): void
    {
        foreach ([[ExpensePoster::class, $this->expenses], [PaymentPoster::class, $this->payments]] as [$poster, $records]) {
            foreach ($records as $record) {
                try {
                    app($poster)->sync($record);
                } catch (LedgerException $e) {
                    $this->report->review('Could not post', class_basename($record)." #{$record->id}: {$e->getMessage()}");
                }
            }
        }
    }

    private function summarize(array $invoiceRows, string $from, array $opening = [], array $statements = []): void
    {
        $money = fn (int $cents) => '$'.number_format($cents / 100, 2);
        $today = now()->startOfDay();

        for ($year = (int) substr($from, 0, 4); $year <= $today->year; $year++) {
            $start = Carbon::create($year, 1, 1);
            $end = $year === $today->year ? $today : Carbon::create($year, 12, 31);
            $pl = ProfitAndLoss::for($start, $end)['totals'];
            $bonsaiPaid = collect($invoiceRows)->filter(fn ($r) => $r['status'] === 'paid' && substr($r['paid_date'], 0, 4) == $year)
                ->sum(fn ($r) => $this->cents($r['total_amount']) - $this->cents($r['calculated_tax_amount']));
            $this->report->ledger[] = sprintf('%d: income %s (Bonsai paid invoices less tax: %s), cost of revenue %s, gross profit %s, expenses %s, net %s',
                $year, $money($pl['income']), $money($bonsaiPaid), $money($pl['cost_of_revenue']), $money($pl['gross_profit']), $money($pl['expenses']), $money($pl['net']));
        }

        $tb = TrialBalance::asOf($today);
        $this->report->ledger[] = sprintf('Trial balance today: debits %s, credits %s (%s)', $money($tb['totals']['debit']), $money($tb['totals']['credit']), $tb['balanced'] ? 'balanced' : 'OUT OF BALANCE');
        foreach (['checking', 'capital_one_card', 'sales_tax_payable', 'uncategorized_expense'] as $key) {
            $account = Account::forKey($key);
            $this->report->ledger[] = sprintf('%s: %s', $account->name, $money(Balances::normal($account, Balances::sums(null, $today)[$account->id] ?? null)));
        }
        $this->report->ledger[] = sprintf('Journal entries: %d', JournalEntry::count());

        // The card against its statements: what it owed at the start plus
        // everything on them since.
        if (isset($statements['capital_one_card'])) {
            $card = Account::forKey('capital_one_card');
            $owed = $this->cents((string) ($opening['capital_one_card'] ?? '0')) - collect($statements['capital_one_card'])->where('date', '>=', $from)->sum('cents');
            $ledger = Balances::normal($card, Balances::sums(null, $today)[$card->id] ?? null);
            $this->report->ledger[] = sprintf('Capital One: statements say %s owed, ledger %s, difference %s', $money($owed), $money($ledger), $money($ledger - $owed));
        }

        // Checking against the bank: the ledger's balance at the end of the
        // last day each month the bank reported one. Close means nothing
        // that went through checking is missing.
        if ($this->bankBalances) {
            $checking = Account::forKey('checking');
            $this->report->ledger[] = 'Checking vs the bank (end of each month):';
            // (The bank lists the newest first, within a day too.)
            foreach (collect($this->bankBalances)->reverse()->sortBy('date')->groupBy(fn ($row) => substr($row['date'], 0, 7))->map->last() as $row) {
                $ledger = Balances::normal($checking, Balances::sums(null, Carbon::parse($row['date']))[$checking->id] ?? null);
                $this->report->ledger[] = sprintf('  %s  bank %s  ledger %s  difference %s', $row['date'], $money($row['balance']), $money($ledger), $money($ledger - $row['balance']));
            }
        }
    }

    // ---- Reading --------------------------------------------------------

    private function readCsv(string $path): array
    {
        $handle = @fopen($path, 'r') ?: throw new RuntimeException("Can't read {$path}.");
        $header = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', $h)), fgetcsv($handle, escape: ''));
        $rows = [];
        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if ($values === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_pad($values, count($header), ''));
        }
        fclose($handle);

        return $rows;
    }

    private function readJsonl(string $path): array
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: throw new RuntimeException("Can't read {$path}.");

        return array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
    }

    private function cents(?string $amount): int
    {
        return Money::toCents(trim((string) $amount));
    }

    private function norm(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value));
    }

    private function describe(array $r): string
    {
        return trim("{$r['date']} {$r['name']} \${$r['amount_after_tax']} [{$r['tags']}]".($r['client'] ? " · {$r['client']}" : ''));
    }
}
