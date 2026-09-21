<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompanyAndProject(): array
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);

        return [$company, $project];
    }

    public function test_a_billable_expense_cannot_be_created_without_a_project(): void
    {
        $this->expectException(ValidationException::class);

        Expense::create([
            'name' => 'Stock photography',
            'amount' => 50,
            'date' => now(),
            'is_billable' => true,
        ]);
    }

    public function test_a_manager_gets_a_422_creating_a_billable_expense_without_a_project(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/expenses', [
            'name' => 'Stock photography',
            'amount' => 50,
            'date' => now()->toDateString(),
            'is_billable' => true,
        ])->assertUnprocessable();
    }

    public function test_billable_amount_applies_markup_percent_on_top_of_the_base_amount(): void
    {
        [, $project] = $this->makeCompanyAndProject();

        $expense = Expense::create([
            'name' => 'Stock photography',
            'amount' => 100,
            'project_id' => $project->id,
            'is_billable' => true,
            'markup_percent' => 15,
            'date' => now(),
        ]);

        $this->assertSame(115.0, $expense->billableAmount());
    }

    public function test_attaching_a_billable_expense_to_a_draft_invoice_creates_a_marked_up_line_item(): void
    {
        [$company, $project] = $this->makeCompanyAndProject();
        $invoice = $company->invoices()->create(['project_id' => $project->id, 'status' => 'draft']);

        $expense = Expense::create([
            'name' => 'Stock photography',
            'amount' => 100,
            'project_id' => $project->id,
            'is_billable' => true,
            'markup_percent' => 20,
            'date' => now(),
        ]);

        $expense->attachToInvoice($invoice);
        $expense->refresh();

        $this->assertSame('billed', $expense->billing_status);
        $this->assertNotNull($expense->invoice_item_id);
        $this->assertSame(120.0, (float) $expense->invoiceItem->amount);
    }

    public function test_paying_an_invoice_cascades_its_attached_expenses_to_billed_and_paid(): void
    {
        [$company, $project] = $this->makeCompanyAndProject();
        $invoice = $company->invoices()->create(['project_id' => $project->id, 'status' => 'draft']);

        $expense = Expense::create([
            'name' => 'Stock photography',
            'amount' => 100,
            'project_id' => $project->id,
            'is_billable' => true,
            'date' => now(),
        ]);
        $expense->attachToInvoice($invoice);

        $invoice->recordPayment('check', $invoice->subtotal());

        $this->assertSame('billed_and_paid', $expense->fresh()->billing_status);
    }

    public function test_detaching_an_expense_reverts_it_to_unbilled_and_removes_the_line_item(): void
    {
        [$company, $project] = $this->makeCompanyAndProject();
        $invoice = $company->invoices()->create(['project_id' => $project->id, 'status' => 'draft']);

        $expense = Expense::create([
            'name' => 'Stock photography',
            'amount' => 100,
            'project_id' => $project->id,
            'is_billable' => true,
            'date' => now(),
        ]);
        $expense->attachToInvoice($invoice);
        $itemId = $expense->invoice_item_id;

        $expense->detachFromInvoice();
        $expense->refresh();

        $this->assertSame('unbilled', $expense->billing_status);
        $this->assertNull($expense->invoice_id);
        $this->assertNull($expense->invoice_item_id);
        $this->assertDatabaseMissing('invoice_items', ['id' => $itemId]);
    }

    public function test_deleting_an_invoice_reverts_its_attached_expenses_to_unbilled(): void
    {
        $user = User::factory()->create();
        [$company, $project] = $this->makeCompanyAndProject();
        $invoice = $company->invoices()->create(['project_id' => $project->id, 'status' => 'draft']);

        $expense = Expense::create([
            'name' => 'Stock photography',
            'amount' => 100,
            'project_id' => $project->id,
            'is_billable' => true,
            'date' => now(),
        ]);
        $expense->attachToInvoice($invoice);

        $this->actingAs($user)->deleteJson("/api/invoices/{$invoice->id}")->assertNoContent();

        $expense->refresh();
        $this->assertSame('unbilled', $expense->billing_status);
        $this->assertNull($expense->invoice_id);
        $this->assertNull($expense->invoice_item_id);
    }

    public function test_a_non_billable_expense_cannot_be_attached_to_an_invoice(): void
    {
        [$company, $project] = $this->makeCompanyAndProject();
        $invoice = $company->invoices()->create(['project_id' => $project->id, 'status' => 'draft']);

        $expense = Expense::create([
            'name' => 'Office snacks',
            'amount' => 20,
            'date' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $expense->attachToInvoice($invoice);
    }

    public function test_plaid_transaction_ids_are_deduplicated_at_the_database_level(): void
    {
        Expense::create([
            'name' => 'Imported charge',
            'amount' => 42,
            'date' => now(),
            'plaid_transaction_id' => 'plaid-abc-123',
        ]);

        $this->expectException(QueryException::class);

        Expense::create([
            'name' => 'Imported charge (duplicate webhook)',
            'amount' => 42,
            'date' => now(),
            'plaid_transaction_id' => 'plaid-abc-123',
        ]);
    }
}
