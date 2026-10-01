<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectIndexMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_managers_see_active_estimated_and_left_to_invoice_figures(): void
    {
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $active = $company->projects()->create(['name' => 'Brand refresh', 'status' => 'active', 'budget' => 10000]);
        $company->projects()->create(['name' => 'Signage', 'status' => 'active', 'budget' => 2000]);
        $estimated = $company->projects()->create(['name' => 'Website', 'status' => 'estimated']);
        $company->proposals()->create(['project_id' => $estimated->id, 'title' => 'Website', 'body' => 'Scope', 'estimate_amount' => 8000, 'status' => 'sent']);

        // $4,000 of the first budget invoiced; tax on it doesn't count.
        $invoice = $company->invoices()->create(['project_id' => $active->id, 'status' => 'sent', 'issued_on' => today(), 'due_on' => today(), 'payment_terms' => 'net_30', 'tax_name' => 'Ohio sales tax', 'tax_rate' => 7.75]);
        $invoice->items()->create(['description' => 'Design', 'amount' => 4000, 'taxable' => true, 'position' => 0]);

        $this->actingAs(User::factory()->create())->get('/projects')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('metrics.active.count', 2)
                ->where('metrics.active.amount', 12000)
                ->where('metrics.estimated.count', 1)
                ->where('metrics.estimated.amount', 8000)
                ->where('metrics.left_to_invoice.count', 2)
                ->where('metrics.left_to_invoice.amount', 8000));
    }

    public function test_team_members_get_no_money_figures(): void
    {
        $this->actingAs(User::factory()->teamMember()->create())->get('/projects')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('metrics', null));
    }
}
