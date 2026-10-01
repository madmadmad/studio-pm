<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceExpenseLinesTest extends TestCase
{
    use RefreshDatabase;

    private function setUpProject(): array
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $project = $company->projects()->create(['name' => 'Brand refresh']);
        $manager = User::factory()->create();

        return compact('company', 'project', 'manager');
    }

    private function expense($project, array $attributes = []): Expense
    {
        return Expense::create([
            'name' => 'Stock photos',
            'amount' => 100,
            'markup_percent' => 10,
            'date' => '2026-09-01',
            'project_id' => $project?->id,
            'is_billable' => true,
            ...$attributes,
        ]);
    }

    public function test_a_new_invoice_bills_an_expense_added_as_a_line(): void
    {
        ['company' => $company, 'project' => $project, 'manager' => $manager] = $this->setUpProject();
        $expense = $this->expense($project);

        $invoice = $this->actingAs($manager)->postJson("/api/companies/{$company->id}/invoices", [
            'project_id' => $project->id,
            'items' => [
                ['description' => 'Design', 'amount' => 500],
                ['description' => 'Stock photos', 'amount' => 110, 'expense_id' => $expense->id],
            ],
        ])->assertCreated()->json();

        $expense->refresh();
        $this->assertSame('billed', $expense->billing_status);
        $this->assertSame($invoice['id'], $expense->invoice_id);
        $this->assertSame($invoice['items'][1]['id'], $expense->invoice_item_id);
    }

    public function test_any_project_expense_can_be_added_even_one_not_marked_billable(): void
    {
        ['company' => $company, 'project' => $project, 'manager' => $manager] = $this->setUpProject();
        $expense = $this->expense($project, ['is_billable' => false]);

        $this->actingAs($manager)->postJson("/api/companies/{$company->id}/invoices", [
            'project_id' => $project->id,
            'items' => [['description' => 'Stock photos', 'amount' => 100, 'expense_id' => $expense->id]],
        ])->assertCreated();

        $expense->refresh();
        $this->assertTrue($expense->is_billable);
        $this->assertSame('billed', $expense->billing_status);
    }

    public function test_an_expense_can_be_added_to_a_sent_invoice_and_removing_the_line_unbills_it(): void
    {
        ['company' => $company, 'project' => $project, 'manager' => $manager] = $this->setUpProject();
        $invoice = $company->invoices()->create(['project_id' => $project->id, 'status' => 'sent', 'issued_on' => today(), 'due_on' => today()->addDays(30), 'payment_terms' => 'net_30']);
        $design = $invoice->items()->create(['description' => 'Design', 'amount' => 500, 'position' => 0]);
        $expense = $this->expense($project);

        $this->actingAs($manager)->patchJson("/api/invoices/{$invoice->id}", [
            'items' => [
                ['id' => $design->id, 'description' => 'Design', 'amount' => 500],
                ['description' => 'Stock photos', 'amount' => 110, 'expense_id' => $expense->id],
            ],
        ])->assertOk();

        $this->assertSame('billed', $expense->fresh()->billing_status);
        $this->assertSame($invoice->id, $expense->fresh()->invoice_id);

        $this->actingAs($manager)->patchJson("/api/invoices/{$invoice->id}", [
            'items' => [['id' => $design->id, 'description' => 'Design', 'amount' => 500]],
        ])->assertOk();

        $this->assertSame('unbilled', $expense->fresh()->billing_status);
        $this->assertNull($expense->fresh()->invoice_item_id);
    }

    public function test_an_expense_from_another_project_or_already_billed_is_refused(): void
    {
        ['company' => $company, 'project' => $project, 'manager' => $manager] = $this->setUpProject();
        $otherProject = $company->projects()->create(['name' => 'Website']);
        $elsewhere = $this->expense($otherProject);
        $billed = $this->expense($project, ['billing_status' => 'billed']);

        foreach ([$elsewhere, $billed] as $expense) {
            $this->actingAs($manager)->postJson("/api/companies/{$company->id}/invoices", [
                'project_id' => $project->id,
                'items' => [['description' => 'Stock photos', 'amount' => 110, 'expense_id' => $expense->id]],
            ])->assertUnprocessable();
        }

        $this->assertSame(0, $company->invoices()->count());
        $this->assertSame('unbilled', $elsewhere->fresh()->billing_status);
    }
}
