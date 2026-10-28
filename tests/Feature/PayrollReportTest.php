<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\CashRequirementSummary;
use App\Services\Payroll\PayrollReportException;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Payroll entered from Data Service's Cash Requirement Summary: reading the
// report, refusing one that doesn't add up, and posting from it.
class PayrollReportTest extends TestCase
{
    use RefreshDatabase;

    // A pay run in the report's layout (made-up figures). Gross wages are
    // 5,000 net + 1,375 taxes + 50 HSA + 450 withheld for the IRAs and
    // health = 6,875.
    private const REPORT = <<<'TXT'
        MADHOUSE STUDIO LLC CASH REQUIREMENT SUMMARY
        1215 JACKSON ST PERIOD DATE: 03/13/2026
        TOLEDO, OH 43604 CHECK DATE: 03/15/2026
        CLIENT 1234567 ABC123X
        PAGE 1
        DESCRIPTION TOTAL
        NET PAYROLL 5000.00
        -------------------------------------------------------------------
        EMPLOYEE TAX WITHHOLDINGS
        SOCIAL SECURITY 400.00
        MEDICARE 100.00
        FEDERAL 600.00
        OH STATE 150.00
        CITY TOL TOLEDO 125.00
        1375.00
        -------------------------------------------------------------------
        EMPLOYEE DEDUCTIONS
        06 HSA 125 50.00
        50.00
        -------------------------------------------------------------------
        EMPLOYER TAX EXPENSES
        SOCIAL SECURITY 400.00
        MEDICARE 100.00
        FEDERAL UNEMPLOYMENT (ESTIMATE) .00
        500.00
        -------------------------------------------------------------------
        TOTAL CASH REQUIREMENT 6925.00 *
        OTHER ITEMS PAID BY CLIENT
        -------------------------------------------------------------------
        EMPLOYEE DEDUCTIONS
        03 SIMP IRA 100.00
        03 * EMPLOYER CONTRIBUTION 80.00
        04 HLTH125 200.00
        09 ROTH IRA 150.00
        09 * EMPLOYER CONTRIBUTION 120.00
        650.00
        -------------------------------------------------------------------
        EMPLOYER TAX EXPENSES
        OH STATE WORKERS COMP (ESTIMATE) 7.00
        7.00
        -------------------------------------------------------------------
        OTHER ITEMS PAID BY CLIENT 657.00 *
        TXT;

    private const AMOUNTS = [
        'wages' => '6875.00',
        'payroll_taxes' => '500.00',
        'retirement_expense' => '200.00',
        'payroll_debit' => '6925.00',
        'retirement_debit' => '450.00',
        'health_deduction' => '200.00',
    ];

    private function pdf(string $text = self::REPORT): UploadedFile
    {
        $html = '<pre style="font-family: monospace">'.e($text).'</pre>';

        return UploadedFile::fake()->createWithContent('Cash_Requirement_Summary.pdf', Pdf::loadHTML($html)->output());
    }

    public function test_the_report_is_read_into_the_entry_it_makes(): void
    {
        $report = CashRequirementSummary::fromText(self::REPORT);

        $this->assertSame('2026-03-15', $report->checkDate->toDateString());
        $this->assertSame('2026-03-13', $report->periodDate->toDateString());
        $this->assertSame(self::AMOUNTS, $report->amounts());
    }

    public function test_the_pdf_text_runs_its_words_together_and_reads_the_same(): void
    {
        // How the PDF library hands the text over: no spaces inside a
        // label, one before its amount.
        $squashed = preg_replace_callback('/^(.*?)( -?[\d,]*\.\d{2} ?\*?)?$/m', fn ($m) => str_replace(' ', '', $m[1]).($m[2] ?? ''), self::REPORT);

        $this->assertSame(self::AMOUNTS, CashRequirementSummary::fromText($squashed)->amounts());
    }

    public function test_a_report_that_does_not_add_up_is_refused(): void
    {
        $this->expectException(PayrollReportException::class);
        $this->expectExceptionMessage('Employee tax withholdings don’t add up');

        CashRequirementSummary::fromText(str_replace('FEDERAL 600.00', 'FEDERAL 660.00', self::REPORT));
    }

    public function test_a_deduction_it_does_not_know_is_refused(): void
    {
        $this->expectException(PayrollReportException::class);
        $this->expectExceptionMessage('(05 GARNISHMENT)');

        CashRequirementSummary::fromText(str_replace(
            ['09 ROTH IRA 150.00', "650.00\n"],
            ["09 ROTH IRA 150.00\n05 GARNISHMENT 25.00", "675.00\n"],
            str_replace('OTHER ITEMS PAID BY CLIENT 657.00 *', 'OTHER ITEMS PAID BY CLIENT 682.00 *', self::REPORT),
        ));
    }

    public function test_reading_then_posting_the_report_enters_the_pay_run_and_keeps_the_pdf(): void
    {
        Storage::fake(config('filesystems.private_disk'));
        $manager = User::factory()->create();

        $read = $this->actingAs($manager)->post('/api/journal-entries/payroll/read', ['report' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('entry_date', '2026-03-15')
            ->assertJsonPath('memo', 'Payroll, check date Mar 15, 2026')
            ->assertJsonPath('already_entered', null);
        $this->assertSame(self::AMOUNTS, $read->json('amounts'));

        $entry = $this->actingAs($manager)->post('/api/journal-entries/payroll', [
            'entry_date' => '2026-03-15', 'memo' => $read->json('memo'), ...self::AMOUNTS, 'report' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertNotNull($entry->json('payroll_report_url'));
        $run = PayrollRun::sole();
        $this->assertSame('2026-03-15', $run->check_date->toDateString());
        Storage::disk(config('filesystems.private_disk'))->assertExists($run->report_path);
        $this->assertSame(687500, Account::forKey('wages')->netDebitCents());
        $this->assertSame(-737500, Account::forKey('checking')->netDebitCents());
        $this->assertSame(-20000, Account::forKey('health_insurance')->netDebitCents());

        // The same report again is caught, unless it's meant.
        $this->actingAs($manager)->post('/api/journal-entries/payroll/read', ['report' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertJsonPath('already_entered.entry_number', $entry->json('entry_number'));
        $this->actingAs($manager)->post('/api/journal-entries/payroll', [
            'entry_date' => '2026-03-15', ...self::AMOUNTS, 'report' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_amounts_changed_from_the_attached_report_are_refused(): void
    {
        Storage::fake(config('filesystems.private_disk'));

        $this->actingAs(User::factory()->create())->post('/api/journal-entries/payroll', [
            'entry_date' => '2026-03-15', ...self::AMOUNTS, 'wages' => '6975.00', 'payroll_debit' => '7025.00', 'report' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'The amounts don’t match the attached report. Read the report again, or post without it.');

        $this->assertSame(0, JournalEntry::count());
    }

    public function test_past_officer_compensation_is_moved_to_wages_once(): void
    {
        $manager = User::factory()->create();
        $officer = Account::forKey('officer_compensation');
        foreach (['2026-01-15', '2026-01-31'] as $day) {
            $this->actingAs($manager)->postJson('/api/journal-entries', ['entry_date' => $day, 'memo' => 'Payroll', 'lines' => [
                ['account_id' => $officer->id, 'debit' => '4000'],
                ['account_id' => Account::forKey('checking')->id, 'credit' => '4000'],
            ]])->assertOk();
        }

        $this->artisan('ledger:officer-comp-to-wages')->expectsOutputToContain('2 pay dates to move')->assertSuccessful();
        $this->assertSame(800000, $officer->netDebitCents(), 'a dry run changes nothing');

        $this->artisan('ledger:officer-comp-to-wages', ['--force' => true])->expectsOutputToContain('Moved 2 of 2')->assertSuccessful();
        $this->assertSame(0, $officer->netDebitCents());
        $this->assertSame(800000, Account::forKey('wages')->netDebitCents());

        $this->artisan('ledger:officer-comp-to-wages', ['--force' => true])->expectsOutputToContain('Nothing in Officer Compensation')->assertSuccessful();
    }
}
