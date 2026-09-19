<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvoiceDueDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_invoice_defaults_to_net_30_days(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ]);

        $response->assertCreated();
        $dueOn = Carbon::parse($response->json('due_on'));
        $this->assertTrue($dueOn->isSameDay(now()->addDays(30)));
    }

    public function test_the_due_date_can_be_overridden_at_creation(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'due_on' => '2026-12-01',
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ]);

        $response->assertCreated();
        $this->assertSame('2026-12-01', Carbon::parse($response->json('due_on'))->toDateString());
    }

    public function test_the_due_date_can_be_changed_while_editing_a_draft(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $invoice = $company->invoices()->create([
            'status' => 'draft', 'surcharge' => false, 'issued_on' => now(), 'due_on' => now()->addDays(30),
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        $response = $this->actingAs($user)->patchJson("/api/invoices/{$invoice->id}", [
            'due_on' => '2026-11-15',
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ]);

        $response->assertOk();
        $this->assertSame('2026-11-15', $invoice->fresh()->due_on->toDateString());
    }
}
