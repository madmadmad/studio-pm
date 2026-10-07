<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClearTrialDataTest extends TestCase
{
    use RefreshDatabase;

    private function trialData(): array
    {
        $admin = User::factory()->create();
        $member = User::factory()->teamMember()->create();
        $company = Company::create(['name' => 'Alder & Finch Design']);
        $invoice = $company->invoices()->create(['status' => 'sent', 'issued_on' => '2026-03-01', 'due_on' => '2026-03-31']);
        $invoice->items()->create(['description' => 'Brand refresh', 'amount' => 100]);
        $invoice->recordPayment('check', 100);
        Storage::fake(config('filesystems.private_disk'));
        Storage::disk(config('filesystems.private_disk'))->put('expense-receipts/r.pdf', 'pdf');
        Expense::create(['name' => 'Spectrum', 'amount' => '120.00', 'date' => '2026-03-04', 'is_billable' => false, 'receipt_path' => 'expense-receipts/r.pdf']);
        Service::create(['name' => 'Design', 'default_rate' => 140, 'unit' => 'hourly']);

        return [$admin, $member];
    }

    public function test_without_force_it_only_shows_what_would_go(): void
    {
        $this->trialData();

        $this->artisan('app:clear-trial-data')->expectsOutputToContain('Nothing deleted')->assertSuccessful();

        $this->assertSame(1, Company::count());
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_it_clears_the_trial_work_and_keeps_the_setup(): void
    {
        [$admin, $member] = $this->trialData();
        $accounts = Account::count();

        $this->artisan('app:clear-trial-data --force')->assertSuccessful();

        $this->assertSame(0, Company::count());
        $this->assertSame(0, Expense::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertNotNull($admin->fresh(), 'super admins stay');
        $this->assertNull($member->fresh(), 'team members go');
        $this->assertSame(1, Service::count());
        $this->assertSame($accounts, Account::count());
        Storage::disk(config('filesystems.private_disk'))->assertMissing('expense-receipts/r.pdf');
    }

    public function test_it_refuses_on_production_without_the_flag(): void
    {
        $this->trialData();
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('app:clear-trial-data --force')->assertFailed();

        $this->assertSame(1, Company::count());
    }
}
