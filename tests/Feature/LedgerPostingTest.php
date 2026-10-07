<?php

namespace Tests\Feature;

use App\Listeners\MarkInvoicePaidFromStripeWebhook;
use App\Models\Account;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\InvoiceCategory;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use App\Services\StripeFees;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\TestCase;

// Expenses, payments and income posting themselves to the ledger
// (docs/ledger-plan.md, Phase 3).
class LedgerPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->company = Company::create(['name' => 'Alder & Finch Design']);
        $this->project = Project::create(['company_id' => $this->company->id, 'name' => 'Brand refresh']);
    }

    // An account's net debits, in cents (credits count against).
    private function balance(string $keyOrName): int
    {
        $account = Account::where('system_key', $keyOrName)->orWhere('name', $keyOrName)->firstOrFail();

        return (int) $account->lines()->sum('debit_cents') - (int) $account->lines()->sum('credit_cents');
    }

    private function category(string $name): ExpenseCategory
    {
        return ExpenseCategory::where('name', $name)->firstOrFail();
    }

    private function live($record): ?JournalEntry
    {
        return app(Ledger::class)->liveEntryFor($record);
    }

    private function createExpense(array $fields = []): Expense
    {
        $response = $this->actingAs($this->manager)->postJson('/api/expenses', [
            'name' => 'Spectrum',
            'amount' => '120.00',
            'date' => '2026-03-04',
            'category_id' => $this->category('Internet')->id,
            ...$fields,
        ])->assertCreated();

        return Expense::findOrFail($response->json('id'));
    }

    // ---- Expenses ------------------------------------------------------

    public function test_an_expense_is_charged_to_the_card_unless_it_says_otherwise(): void
    {
        $expense = $this->createExpense();

        $this->assertSame(Account::forKey('capital_one_card')->id, $expense->paid_from_account_id);
        $entry = $this->live($expense);
        $this->assertSame('2026-03-04', $entry->entry_date->toDateString());
        $this->assertSame('Spectrum', $entry->memo);
        $this->assertSame($this->manager->id, $entry->created_by);
        $this->assertSame(12000, $this->balance('Internet'));
        $this->assertSame(-12000, $this->balance('capital_one_card'));
    }

    public function test_an_expense_paid_from_checking_credits_checking(): void
    {
        $this->createExpense(['name' => 'Rent', 'category_id' => $this->category('Rent & Lease Property')->id, 'paid_from_account_id' => Account::forKey('checking')->id]);

        $this->assertSame(12000, $this->balance('Rent & Lease Property'));
        $this->assertSame(-12000, $this->balance('checking'));
        $this->assertSame(0, $this->balance('capital_one_card'));
    }

    public function test_an_expense_cant_be_paid_from_an_income_account(): void
    {
        $this->actingAs($this->manager)->postJson('/api/expenses', [
            'name' => 'Spectrum', 'amount' => '120.00', 'date' => '2026-03-04',
            'paid_from_account_id' => Account::forKey('service_revenue')->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('paid_from_account_id');
    }

    public function test_an_expense_with_no_category_posts_to_uncategorized(): void
    {
        $this->createExpense(['category_id' => null]);

        $this->assertSame(12000, $this->balance('uncategorized_expense'));
    }

    public function test_advertising_billed_to_a_client_is_client_media_spend_and_carries_the_client(): void
    {
        $billed = $this->createExpense(['name' => 'Meta ads', 'category_id' => $this->category('Advertising')->id, 'is_billable' => true, 'project_id' => $this->project->id]);
        $this->createExpense(['name' => 'Our own ads', 'category_id' => $this->category('Advertising')->id]);

        $this->assertSame(12000, $this->balance('client_media_spend'));
        $this->assertSame(12000, $this->balance('Advertising & Marketing'));
        $this->assertSame($this->company->id, $this->live($billed)->lines->firstWhere('debit_cents', '>', 0)->company_id);
    }

    public function test_a_cost_split_across_clients_is_a_debit_per_client(): void
    {
        $other = Company::create(['name' => 'Marsh Grove Bakery']);

        $expense = $this->createExpense([
            'name' => 'Linode',
            'amount' => '100.00',
            'category_id' => $this->category('Hosting')->id,
            'splits' => json_encode([['company_id' => $this->company->id, 'amount' => '60.00'], ['company_id' => $other->id, 'amount' => '40.00']]),
        ]);

        $debits = $this->live($expense)->lines->where('debit_cents', '>', 0);
        $this->assertSame([$this->company->id => 6000, $other->id => 4000], $debits->pluck('debit_cents', 'company_id')->all());
        $this->assertSame(10000, $this->balance('hosting_cost'));
    }

    public function test_changing_the_amount_reverses_the_entry_and_posts_the_new_one(): void
    {
        $expense = $this->createExpense();
        $original = $this->live($expense);

        $this->actingAs($this->manager)->patchJson("/api/expenses/{$expense->id}", ['amount' => '130.00'])->assertOk();

        $this->assertNotNull($original->fresh()->reversal);
        $this->assertSame(13000, $this->live($expense)->totalCents());
        $this->assertSame(13000, $this->balance('Internet'));
        $this->assertSame(3, JournalEntry::count(), 'the original, its reversal, the new one');
    }

    public function test_a_change_that_doesnt_touch_the_money_posts_nothing(): void
    {
        $expense = $this->createExpense();

        $this->actingAs($this->manager)->patchJson("/api/expenses/{$expense->id}", ['name' => 'Spectrum business internet', 'source_label' => 'Capital One'])->assertOk();

        $this->assertSame(1, JournalEntry::count());
    }

    public function test_changing_only_the_split_reposts(): void
    {
        $other = Company::create(['name' => 'Marsh Grove Bakery']);
        $expense = $this->createExpense(['name' => 'Linode', 'amount' => '100.00', 'category_id' => $this->category('Hosting')->id]);

        $this->actingAs($this->manager)->patchJson("/api/expenses/{$expense->id}", [
            'splits' => json_encode([['company_id' => $this->company->id, 'amount' => '50.00'], ['company_id' => $other->id, 'amount' => '50.00']]),
        ])->assertOk();

        $this->assertSame(2, $this->live($expense)->lines->where('debit_cents', '>', 0)->count());
    }

    public function test_deleting_an_expense_reverses_it(): void
    {
        $expense = $this->createExpense();

        $this->actingAs($this->manager)->deleteJson("/api/expenses/{$expense->id}")->assertNoContent();

        $this->assertNull($this->live($expense));
        $this->assertSame(0, $this->balance('Internet'));
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_an_expense_in_a_locked_period_cant_be_changed_added_or_deleted(): void
    {
        $expense = $this->createExpense();
        app(Ledger::class)->lockPeriod('2026-03-01', '2026-03-31');

        $this->actingAs($this->manager)->patchJson("/api/expenses/{$expense->id}", ['amount' => '130.00'])
            ->assertUnprocessable()->assertJsonPath('message', 'The books are locked from Mar 1, 2026 to Mar 31, 2026, so this can\'t be changed.');
        $this->actingAs($this->manager)->patchJson("/api/expenses/{$expense->id}", ['date' => '2026-04-02'])->assertUnprocessable();
        $this->actingAs($this->manager)->deleteJson("/api/expenses/{$expense->id}")->assertUnprocessable();
        $this->actingAs($this->manager)->postJson('/api/expenses', ['name' => 'Late', 'amount' => '5.00', 'date' => '2026-03-20'])->assertUnprocessable();

        $this->assertSame('120.00', $expense->fresh()->amount);
        $this->assertSame(1, JournalEntry::count());

        // A change that leaves the money alone is still fine.
        $this->actingAs($this->manager)->patchJson("/api/expenses/{$expense->id}", ['name' => 'Spectrum (March)'])->assertOk();
    }

    public function test_a_printing_expense_rebilled_on_an_invoice_starts_out_taxable(): void
    {
        $expense = $this->createExpense(['name' => 'Brochures', 'amount' => '400.00', 'category_id' => $this->category('Printing')->id, 'is_billable' => true, 'project_id' => $this->project->id, 'markup_percent' => '25']);
        $invoice = $this->company->invoices()->create(['status' => 'draft', 'project_id' => $this->project->id, 'issued_on' => now(), 'due_on' => now()->addDays(30)]);

        $this->actingAs($this->manager)->postJson("/api/expenses/{$expense->id}/attach-to-invoice", ['invoice_id' => $invoice->id])->assertOk();

        $item = $invoice->items()->sole();
        $this->assertTrue((bool) $item->taxable);
        $this->assertSame('500.00', number_format((float) $item->amount, 2, '.', ''));
        $this->assertSame(40000, $this->balance('printing_cost'), 'billed, so the cost is a cost of revenue');
    }

    // ---- Payments ------------------------------------------------------

    private function sentInvoice(array $items, array $fields = []): Invoice
    {
        $invoice = $this->company->invoices()->create(['status' => 'sent', 'issued_on' => '2026-03-01', 'due_on' => '2026-03-31', ...$fields]);
        foreach ($items as $item) {
            $invoice->items()->create($item);
        }

        return $invoice->fresh();
    }

    public function test_a_check_payment_goes_to_checking_and_earns_design_and_development_revenue(): void
    {
        $invoice = $this->sentInvoice([['description' => 'Brand refresh', 'amount' => 507.50]]);

        $this->actingAs($this->manager)->postJson("/api/invoices/{$invoice->id}/mark-paid", ['method' => 'check'])->assertOk();

        $this->assertSame(50750, $this->balance('checking'));
        $this->assertSame(-50750, $this->balance('service_revenue'));
        $entry = $this->live($invoice->payments()->sole());
        $this->assertSame("Payment on invoice #{$invoice->invoice_number}", $entry->memo);
        $this->assertSame($this->company->id, $entry->lines->firstWhere('credit_cents', 50750)->company_id);
        $this->assertSame(1, JournalEntry::count(), 'the income row it also writes doesn\'t post again');
    }

    public function test_sales_tax_in_a_payment_is_owed_to_the_state_not_earned(): void
    {
        $invoice = $this->sentInvoice([
            ['description' => 'Design', 'amount' => 1000],
            ['description' => 'Brochures', 'amount' => 200, 'taxable' => true],
        ], ['tax_name' => 'Ohio sales tax', 'tax_rate' => 7.25]);

        $this->actingAs($this->manager)->postJson("/api/invoices/{$invoice->id}/mark-paid", ['method' => 'check'])->assertOk();

        $this->assertSame(121450, $this->balance('checking'));
        $this->assertSame(-1450, $this->balance('sales_tax_payable'));
        $this->assertSame(-120000, $this->balance('service_revenue'));
    }

    public function test_each_line_earns_revenue_in_its_own_account(): void
    {
        $adManagement = Service::create(['name' => 'Ad management', 'default_rate' => 125, 'unit' => 'hourly', 'revenue_account_id' => Account::forKey('ad_management_revenue')->id]);
        $printing = $this->createExpense(['name' => 'Brochures', 'amount' => '400.00', 'category_id' => $this->category('Printing')->id, 'is_billable' => true, 'project_id' => $this->project->id]);
        $invoice = $this->sentInvoice([
            ['description' => 'Website', 'amount' => 3000],
            ['description' => 'Campaign management', 'amount' => 500, 'service_id' => $adManagement->id],
            ['description' => 'Brochures', 'amount' => 500],
        ]);
        $printing->update(['invoice_id' => $invoice->id, 'invoice_item_id' => $invoice->items()->where('description', 'Brochures')->value('id'), 'billing_status' => 'billed']);

        $this->actingAs($this->manager)->postJson("/api/invoices/{$invoice->id}/mark-paid", ['method' => 'check'])->assertOk();

        $this->assertSame(-300000, $this->balance('service_revenue'));
        $this->assertSame(-50000, $this->balance('ad_management_revenue'));
        $this->assertSame(-50000, $this->balance('printing_revenue'));
    }

    public function test_a_hosting_invoice_earns_hosting_revenue(): void
    {
        $invoice = $this->sentInvoice([['description' => 'Hosting, March', 'amount' => 75]], ['category_id' => InvoiceCategory::where('name', 'Hosting')->value('id')]);

        $this->actingAs($this->manager)->postJson("/api/invoices/{$invoice->id}/mark-paid", ['method' => 'check'])->assertOk();

        $this->assertSame(-7500, $this->balance('hosting_revenue'));
    }

    public function test_a_card_payment_lands_in_stripe_clearing_less_the_fee_with_the_surcharge_as_income(): void
    {
        $this->mock(StripeFees::class, fn ($mock) => $mock->shouldReceive('forPaymentIntent')->with('pi_test_1')->andReturn(30.17));
        $invoice = $this->sentInvoice([['description' => 'Brand refresh', 'amount' => 1000]], ['stripe_checkout_session_id' => 'cs_test_1']);

        (new MarkInvoicePaidFromStripeWebhook)->handle(new WebhookReceived([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_1',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_1',
                'metadata' => ['method' => 'card', 'base_amount' => '1000.00', 'surcharge_amount' => '30.00'],
            ]],
        ]));

        $this->assertEqualsWithDelta(30.17, (float) $invoice->payments()->sole()->stripe_fee, 0.001);
        $this->assertSame(103000 - 3017, $this->balance('stripe_clearing'));
        $this->assertSame(3017, $this->balance('merchant_fees'));
        $this->assertSame(-3000, $this->balance('surcharge_income'));
        $this->assertSame(-100000, $this->balance('service_revenue'));
    }

    public function test_without_the_fee_a_card_payment_posts_gross_to_clearing(): void
    {
        $this->mock(StripeFees::class, fn ($mock) => $mock->shouldReceive('forPaymentIntent')->andReturn(null));
        $invoice = $this->sentInvoice([['description' => 'Brand refresh', 'amount' => 1000]], ['stripe_checkout_session_id' => 'cs_test_2']);

        (new MarkInvoicePaidFromStripeWebhook)->handle(new WebhookReceived([
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_2', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_2', 'metadata' => ['method' => 'card', 'base_amount' => '1000.00', 'surcharge_amount' => '30.00']]],
        ]));

        $this->assertSame(103000, $this->balance('stripe_clearing'));
        $this->assertSame(0, $this->balance('merchant_fees'));
    }

    public function test_an_ach_payment_recorded_by_hand_goes_straight_to_checking(): void
    {
        $invoice = $this->sentInvoice([['description' => 'Brand refresh', 'amount' => 1000]]);

        $this->actingAs($this->manager)->postJson("/api/invoices/{$invoice->id}/mark-paid", ['method' => 'ach'])->assertOk();

        $this->assertSame('ach', $invoice->payments()->sole()->method);
        $this->assertSame(100000, $this->balance('checking'));
        $this->assertSame(0, $this->balance('stripe_clearing'));
    }

    public function test_a_client_with_ledger_history_cant_be_deleted(): void
    {
        $invoice = $this->sentInvoice([['description' => 'Brand refresh', 'amount' => 100]]);
        $this->actingAs($this->manager)->postJson("/api/invoices/{$invoice->id}/mark-paid", ['method' => 'check'])->assertOk();

        $this->actingAs($this->manager)->deleteJson("/api/companies/{$this->company->id}")
            ->assertUnprocessable()->assertJsonPath('message', 'Alder & Finch Design has entries in the books, so it can\'t be deleted.');
        $this->assertNotNull($this->company->fresh());
    }

    // ---- Other income --------------------------------------------------

    public function test_income_entered_by_hand_posts_to_other_income_and_comes_out_when_deleted(): void
    {
        $response = $this->actingAs($this->manager)->postJson('/api/transactions', [
            'amount' => '107.25', 'tax_amount' => '7.25', 'occurred_on' => '2026-03-10', 'description' => 'Workshop seats',
        ])->assertCreated();
        $income = Transaction::findOrFail($response->json('id'));

        $this->assertSame(10725, $this->balance('checking'));
        $this->assertSame(-10000, $this->balance('other_income'));
        $this->assertSame(-725, $this->balance('sales_tax_payable'));
        $this->assertSame('Workshop seats', $this->live($income)->memo);

        $this->actingAs($this->manager)->deleteJson("/api/transactions/{$income->id}")->assertNoContent();

        $this->assertNull($this->live($income));
        $this->assertSame(0, $this->balance('other_income'));
    }
}
