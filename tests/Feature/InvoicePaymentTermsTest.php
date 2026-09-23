<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\StudioProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoicePaymentTermsTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(?string $defaultTerms = null): Company
    {
        return Company::create(['name' => 'Alder & Finch Design', 'default_payment_terms' => $defaultTerms]);
    }

    // issued_on/due_on serialize as full ISO datetime strings (a pre-existing
    // characteristic of the app -- the frontend even relies on it, slicing
    // the first 10 characters), so every comparison here goes through
    // Carbon::parse()->toDateString() rather than raw string equality.
    private function dateFrom(mixed $json): string
    {
        return Carbon::parse($json)->toDateString();
    }

    // At 2:30am UTC, it's still 9:30pm the *previous* day in America/New_York
    // (config/app.php's timezone) -- this is exactly the gap that made the
    // old hardcoded now()->addDays(30) wrong for a firm in Ohio.
    public function test_a_new_invoice_defaults_to_todays_date_in_the_app_timezone(): void
    {
        $this->travelTo(Carbon::create(2026, 1, 15, 2, 30, 0, 'UTC'));

        $user = User::factory()->create();
        $company = $this->makeCompany();

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ]);

        $response->assertCreated();
        $this->assertSame('2026-01-14', $this->dateFrom($response->json('issued_on')));
    }

    public function test_a_client_with_no_default_terms_gets_net_30(): void
    {
        $user = User::factory()->create();
        $company = $this->makeCompany();

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ]);

        $response->assertCreated();
        $this->assertSame('net_30', $response->json('payment_terms'));
        $this->assertSame(
            Carbon::parse($this->dateFrom($response->json('issued_on')))->addDays(30)->toDateString(),
            $this->dateFrom($response->json('due_on'))
        );
    }

    public function test_a_client_with_a_net_15_default_produces_a_15_day_due_date(): void
    {
        $user = User::factory()->create();
        $company = $this->makeCompany('net_15');

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ]);

        $response->assertCreated();
        $this->assertSame('net_15', $response->json('payment_terms'));
        $this->assertSame(
            Carbon::parse($this->dateFrom($response->json('issued_on')))->addDays(15)->toDateString(),
            $this->dateFrom($response->json('due_on'))
        );
    }

    private function assertTermsProduceDueDate(string $terms, int $days): void
    {
        $user = User::factory()->create();
        $company = $this->makeCompany();

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'issued_on' => '2026-05-01',
            'payment_terms' => $terms,
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ]);

        $response->assertCreated();
        $this->assertSame(Carbon::parse('2026-05-01')->addDays($days)->toDateString(), $this->dateFrom($response->json('due_on')));
    }

    public function test_due_on_receipt_due_date_equals_issue_date(): void
    {
        $this->assertTermsProduceDueDate('due_on_receipt', 0);
    }

    public function test_net_15_due_date_is_15_days_out(): void
    {
        $this->assertTermsProduceDueDate('net_15', 15);
    }

    public function test_net_30_due_date_is_30_days_out(): void
    {
        $this->assertTermsProduceDueDate('net_30', 30);
    }

    public function test_net_45_due_date_is_45_days_out(): void
    {
        $this->assertTermsProduceDueDate('net_45', 45);
    }

    public function test_net_60_due_date_is_60_days_out(): void
    {
        $this->assertTermsProduceDueDate('net_60', 60);
    }

    // The server ignores a submitted due_on for any non-Custom term, even
    // one that would otherwise look plausible -- it always recomputes.
    public function test_a_submitted_due_date_is_ignored_unless_terms_are_custom(): void
    {
        $user = User::factory()->create();
        $company = $this->makeCompany();

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'issued_on' => '2026-05-01',
            'payment_terms' => 'net_15',
            'due_on' => '2099-01-01',
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ]);

        $response->assertCreated();
        $this->assertSame('2026-05-16', $this->dateFrom($response->json('due_on')));
    }

    public function test_a_custom_due_date_is_preserved_on_update_when_not_resubmitted(): void
    {
        $user = User::factory()->create();
        $company = $this->makeCompany();

        $invoice = $company->invoices()->create([
            'status' => 'draft', 'surcharge' => false,
            'issued_on' => '2026-05-01', 'due_on' => '2026-08-15', 'payment_terms' => 'custom',
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        // Only touching items -- no date/terms fields sent at all.
        $response = $this->actingAs($user)->patchJson("/api/invoices/{$invoice->id}", [
            'items' => [['description' => 'Design work (revised)', 'amount' => 1200]],
        ]);

        $response->assertOk();
        $invoice->refresh();
        $this->assertSame('2026-05-01', $invoice->issued_on->toDateString());
        $this->assertSame('2026-08-15', $invoice->due_on->toDateString());
        $this->assertSame('custom', $invoice->payment_terms->value);
    }

    public function test_validation_rejects_a_due_date_before_the_issue_date(): void
    {
        $user = User::factory()->create();
        $company = $this->makeCompany();

        $response = $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'issued_on' => '2026-05-10',
            'payment_terms' => 'custom',
            'due_on' => '2026-05-01',
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('due_on');
    }

    public function test_payment_terms_rejects_an_invalid_value(): void
    {
        $user = User::factory()->create();
        $company = $this->makeCompany();

        $this->actingAs($user)->postJson("/api/companies/{$company->id}/invoices", [
            'payment_terms' => 'net_90',
            'items' => [['description' => 'Design work', 'amount' => 1000]],
        ])->assertUnprocessable();
    }

    // A company's own default_payment_terms overrides the firm-wide config
    // default; 'custom' is not offered as a firm-wide/client-level choice.
    public function test_a_companys_default_payment_terms_cannot_be_set_to_custom(): void
    {
        $user = User::factory()->create();
        $company = $this->makeCompany();

        $this->actingAs($user)->patchJson("/api/companies/{$company->id}", [
            'default_payment_terms' => 'custom',
        ])->assertUnprocessable();
    }

    // The practical, testable form of "the backfill migration sets dates
    // correctly": a row inserted without payment_terms (as any pre-migration
    // invoice effectively was) gets the column's own DB-level default.
    public function test_a_raw_inserted_invoice_row_defaults_payment_terms_to_net_30(): void
    {
        $company = $this->makeCompany();

        $id = DB::table('invoices')->insertGetId([
            'company_id' => $company->id,
            'status' => 'draft',
            'surcharge' => false,
            'issued_on' => '2026-01-01',
            'due_on' => '2026-01-31',
            'invoice_number' => 5000,
            'public_token' => 'test-token-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('net_30', DB::table('invoices')->where('id', $id)->value('payment_terms'));
    }

    public function test_dates_and_terms_render_on_the_pdf_and_public_invoice_page(): void
    {
        $company = $this->makeCompany();
        $invoice = $company->invoices()->create([
            'status' => 'sent', 'surcharge' => false,
            'issued_on' => '2026-03-01', 'due_on' => '2026-03-31', 'payment_terms' => 'net_30',
        ]);
        $invoice->items()->create(['description' => 'Design work', 'amount' => 1000]);

        $pdfHtml = view('pdfs.invoice', [
            'invoice' => $invoice->fresh()->load('items', 'payments', 'company', 'contact', 'project'),
            'studio' => StudioProfile::current(),
        ])->render();

        $this->assertStringContainsString('Mar 1, 2026', $pdfHtml);
        $this->assertStringContainsString('Mar 31, 2026', $pdfHtml);
        $this->assertStringContainsString('Net 30', $pdfHtml);

        $publicResponse = $this->get("/i/{$invoice->public_token}");
        $publicResponse->assertOk();
    }
}
