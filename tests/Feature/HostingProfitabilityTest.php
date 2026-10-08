<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InvoiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostingProfitabilityTest extends TestCase
{
    use RefreshDatabase;

    private function setUpClients(): array
    {
        $a = Company::create(['name' => 'Alder & Finch Design']);
        $b = Company::create(['name' => 'Birch Studio']);

        return [$a, $b, User::factory()->create(), ExpenseCategory::where('name', 'Hosting')->firstOrFail()];
    }

    private function hostingInvoice(Company $company, string $issued, string $status, float $amount): void
    {
        $invoice = $company->invoices()->create([
            'category_id' => InvoiceCategory::firstOrCreate(['name' => 'Hosting'])->id,
            'status' => $status, 'issued_on' => $issued, 'due_on' => $issued, 'payment_terms' => 'net_30',
        ]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => $amount, 'position' => 0]);
        if ($status === 'paid') {
            $invoice->payments()->create(['method' => 'check', 'amount' => $amount, 'surcharge_amount' => 0, 'paid_at' => $issued]);
        }
    }

    private function logBill(User $manager, ExpenseCategory $hosting, string $date, float $amount, array $splits)
    {
        return $this->actingAs($manager)->post('/api/expenses', [
            'name' => 'Linode', 'amount' => $amount, 'date' => $date, 'category_id' => $hosting->id,
            // Sent as billable when split, to show the split overrides it.
            'is_billable' => $splits ? '1' : '0', 'splits' => json_encode($splits),
        ], ['Accept' => 'application/json']);
    }

    public function test_a_hosting_bill_is_split_across_clients_and_never_billed(): void
    {
        [$a, $b, $manager, $hosting] = $this->setUpClients();

        $expense = $this->logBill($manager, $hosting, '2026-09-01', 50, [
            ['company_id' => $a->id, 'amount' => 30], ['company_id' => $b->id, 'amount' => 20],
        ])->assertCreated()->assertJsonCount(2, 'splits')->json();

        $this->assertFalse(Expense::find($expense['id'])->is_billable);
    }

    public function test_a_split_has_to_add_up_and_name_each_client_once(): void
    {
        [$a, $b, $manager, $hosting] = $this->setUpClients();

        $this->logBill($manager, $hosting, '2026-09-01', 50, [['company_id' => $a->id, 'amount' => 30]])
            ->assertUnprocessable()->assertJsonValidationErrors('splits');
        $this->logBill($manager, $hosting, '2026-09-01', 50, [['company_id' => $a->id, 'amount' => 25], ['company_id' => $a->id, 'amount' => 25]])
            ->assertUnprocessable();
        $this->assertSame(0, Expense::count());
    }

    public function test_the_expenses_page_offers_the_recent_splits_newest_first(): void
    {
        [$a, $b, $manager, $hosting] = $this->setUpClients();
        $this->logBill($manager, $hosting, '2026-08-01', 40, [['company_id' => $a->id, 'amount' => 24], ['company_id' => $b->id, 'amount' => 16]]);
        $this->logBill($manager, $hosting, '2026-09-01', 50, [['company_id' => $a->id, 'amount' => 30], ['company_id' => $b->id, 'amount' => 20]]);

        $this->actingAs($manager)->get('/expenses')->assertInertia(fn ($page) => $page
            ->has('lastSplits', 2)
            ->where('lastSplits.0.name', 'Linode')
            ->where('lastSplits.0.amount', '50.00')
            ->has('lastSplits.0.splits', 2));
    }

    public function test_a_share_can_be_not_billed_once(): void
    {
        [$a, $b, $manager, $hosting] = $this->setUpClients();

        $this->logBill($manager, $hosting, '2026-09-01', 50, [['company_id' => $a->id, 'amount' => 30], ['company_id' => null, 'amount' => 20]])
            ->assertCreated()->assertJsonCount(2, 'splits');
        $this->logBill($manager, $hosting, '2026-09-01', 50, [['company_id' => null, 'amount' => 25], ['company_id' => null, 'amount' => 25]])
            ->assertUnprocessable()->assertJsonValidationErrors('splits');
        $this->assertSame(1, Expense::count());
    }

    public function test_the_report_sets_each_clients_hosting_against_their_share_of_the_bills(): void
    {
        [$a, $b, $manager, $hosting] = $this->setUpClients();
        $this->hostingInvoice($a, '2026-01-05', 'paid', 1200);
        $this->hostingInvoice($b, '2026-09-01', 'paid', 95);
        $this->hostingInvoice($b, '2026-10-01', 'sent', 95);
        $this->logBill($manager, $hosting, '2026-09-01', 50, [['company_id' => $a->id, 'amount' => 30], ['company_id' => $b->id, 'amount' => 20]]);
        $this->logBill($manager, $hosting, '2026-10-01', 50, [['company_id' => $a->id, 'amount' => 30], ['company_id' => $b->id, 'amount' => 20]]);
        $this->logBill($manager, $hosting, '2026-10-02', 10, []); // not split yet
        $this->logBill($manager, $hosting, '2026-10-03', 7, [['company_id' => null, 'amount' => 7]]); // servers no client pays for

        $this->actingAs($manager)->get('/bookkeeping/hosting?year=2026')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Bookkeeping/Hosting')
            ->where('report.clients.0.name', 'Alder & Finch Design')
            ->where('report.clients.0.invoiced', 1200)
            ->where('report.clients.0.cost', 60)
            ->where('report.clients.0.margin', 1140)
            ->where('report.clients.0.margin_percent', 95)
            ->where('report.clients.1.invoiced', 190)
            ->where('report.clients.1.paid', 95)
            ->where('report.clients.1.cost', 40)
            ->has('report.clients', 2)
            ->where('report.not_billed', 7)
            ->where('report.unassigned', 10)
            ->where('report.totals.cost', 117)
            ->where('report.totals.margin', 1273));

        $csv = $this->get('/bookkeeping/hosting.csv?year=2026')->assertDownload('hosting-profitability-2026.csv')->streamedContent();
        $this->assertStringContainsString('"Alder & Finch Design",1200.00,1200.00,60.00,1140.00,95', $csv);
        $this->assertStringContainsString('"Not billed (servers no client pays for)",,,7.00,-7.00,', $csv);
        $this->assertStringContainsString('"Unassigned hosting cost",,,10.00,-10.00,', $csv);
    }
}
