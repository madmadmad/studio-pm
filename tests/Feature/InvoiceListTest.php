<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// The Invoices page a few months at a time: All and Paid list the latest
// three months with invoices, whole, and "Show earlier months" adds three
// more; Draft, Outstanding and Overdue list everything. Filters and search
// run on the server, over every invoice, and the figures across the top
// don't depend on what's listed.
class InvoiceListTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'America/New_York'));
        $this->acme = Company::create(['name' => 'Acme Studio']);
    }

    private function invoice(string $issuedOn, string $status = 'paid', array $extra = []): Invoice
    {
        $invoice = $this->acme->invoices()->create([
            'status' => $status,
            'issued_on' => $issuedOn,
            'due_on' => Carbon::parse($issuedOn)->addDays(30)->toDateString(),
            ...$extra,
        ]);
        $invoice->items()->create(['description' => 'Design', 'amount' => 100]);

        return $invoice;
    }

    private function page(array $query = [])
    {
        return $this->actingAs(User::factory()->create())
            ->get('/invoices?'.http_build_query($query))
            ->assertOk();
    }

    private function listed($response): array
    {
        return collect($response->viewData('page')['props']['invoices'])->pluck('id')->sort()->values()->all();
    }

    private function ids(Invoice ...$invoices): array
    {
        return collect($invoices)->pluck('id')->sort()->values()->all();
    }

    public function test_all_lists_the_latest_three_months_with_invoices_whole(): void
    {
        $oct = $this->invoice('2026-10-02');
        $oct2 = $this->invoice('2026-10-30');
        $aug = $this->invoice('2026-08-10'); // no September: the window skips empty months
        $jul = $this->invoice('2026-07-01');
        $jun = $this->invoice('2026-06-20');
        $undated = $this->invoice('2026-01-01');
        $undated->forceFill(['issued_on' => null])->save();

        $response = $this->page();

        $this->assertSame($this->ids($oct, $oct2, $aug, $jul, $undated), $this->listed($response));
        $response->assertInertia(fn ($page) => $page->where('hasEarlier', true)->where('filters.months', 3));
    }

    public function test_show_earlier_months_adds_three_more(): void
    {
        $months = collect(range(1, 7))->map(fn ($m) => $this->invoice(sprintf('2026-%02d-15', $m)));

        $response = $this->page(['months' => 6]);

        $this->assertSame($this->ids(...$months->slice(1)->all()), $this->listed($response));
        $response->assertInertia(fn ($page) => $page->where('hasEarlier', true));

        $this->page(['months' => 9])->assertInertia(fn ($page) => $page->where('hasEarlier', false)->has('invoices', 7));
    }

    public function test_months_are_whole_steps(): void
    {
        $this->invoice('2026-10-01');

        $this->page(['months' => 4])->assertInertia(fn ($page) => $page->where('filters.months', 6));
        $this->page(['months' => -2])->assertInertia(fn ($page) => $page->where('filters.months', 3));
    }

    public function test_outstanding_overdue_and_draft_list_everything(): void
    {
        $old = $this->invoice('2025-01-10', 'sent'); // long overdue
        $recent = $this->invoice('2026-10-01', 'sent', ['due_on' => '2026-10-31']);
        $dueToday = $this->invoice('2026-09-15', 'sent', ['due_on' => '2026-10-15']);
        $draft = $this->invoice('2024-12-01', 'draft');
        $this->invoice('2026-10-03', 'paid');

        $this->assertSame($this->ids($old, $recent, $dueToday), $this->listed($this->page(['status' => 'outstanding'])));
        // Due today isn't overdue yet (the firm's day).
        $this->assertSame($this->ids($old), $this->listed($this->page(['status' => 'overdue'])));
        $this->assertSame($this->ids($draft), $this->listed($this->page(['status' => 'draft'])));
        $this->page(['status' => 'outstanding'])->assertInertia(fn ($page) => $page->where('hasEarlier', false));
    }

    public function test_paid_is_a_window_of_its_own_months(): void
    {
        $paid = collect(['2026-10-01', '2026-06-01', '2026-02-01', '2025-11-01'])->map(fn ($d) => $this->invoice($d, 'paid'));
        $this->invoice('2026-09-01', 'sent');

        $response = $this->page(['status' => 'paid']);

        $this->assertSame($this->ids(...$paid->take(3)->all()), $this->listed($response));
        $response->assertInertia(fn ($page) => $page->where('hasEarlier', true));
    }

    public function test_search_covers_every_invoice_by_number_client_or_project(): void
    {
        $other = Company::create(['name' => 'Marsh Grove Bakery']);
        $old = $other->invoices()->create(['status' => 'paid', 'issued_on' => '2024-03-01', 'due_on' => '2024-04-01']);
        $project = $this->acme->projects()->create(['name' => 'Harbor Rebrand']);
        $byProject = $this->invoice('2025-05-01', 'paid', ['project_id' => $project->id]);
        $this->invoice('2026-10-01');

        $this->assertSame($this->ids($old), $this->listed($this->page(['q' => 'marsh'])));
        $this->assertSame($this->ids($byProject), $this->listed($this->page(['q' => 'harbor'])));
        $this->assertSame($this->ids($old), $this->listed($this->page(['q' => (string) $old->invoice_number])));
        // Search characters are literal, not wildcards.
        $this->assertSame([], $this->listed($this->page(['q' => '%'])));
    }

    public function test_category_filters_project_work_or_a_category(): void
    {
        $hosting = InvoiceCategory::create(['name' => 'Workshops']);
        $project = $this->invoice('2026-10-01');
        $host = $this->invoice('2026-10-02', 'paid', ['category_id' => $hosting->id]);

        $this->assertSame($this->ids($project), $this->listed($this->page(['category' => 'project'])));
        $this->assertSame($this->ids($host), $this->listed($this->page(['category' => (string) $hosting->id])));
        $this->assertSame($this->ids($project, $host), $this->listed($this->page(['category' => 'nonsense'])));
    }

    public function test_an_unknown_status_is_all(): void
    {
        $this->invoice('2026-10-01');

        $this->page(['status' => 'everything'])->assertInertia(fn ($page) => $page->where('filters.status', 'all')->has('invoices', 1));
    }

    public function test_the_figures_are_over_every_invoice_whatever_is_listed(): void
    {
        // Owed: two sent, one of them overdue, one from long ago.
        $this->invoice('2025-01-10', 'sent');
        $this->invoice('2026-10-01', 'sent', ['due_on' => '2026-10-31', 'sent_at' => '2026-10-02 14:00:00']);
        // Paid this month (Eastern), and last month.
        $paidNow = $this->invoice('2026-09-01', 'paid');
        $paidNow->payments()->create(['method' => 'check', 'amount' => 100, 'paid_at' => '2026-10-03 10:00:00']);
        $paidBefore = $this->invoice('2026-08-01', 'paid');
        $paidBefore->payments()->create(['method' => 'check', 'amount' => 100, 'paid_at' => '2026-09-30 22:00:00']);

        $this->page(['status' => 'paid'])->assertInertia(fn ($page) => $page
            ->where('metrics.outstanding', ['count' => 2, 'amount' => 200])
            ->where('metrics.overdue', ['count' => 1, 'amount' => 100])
            ->where('metrics.paid', ['count' => 1, 'amount' => 100])
            ->where('metrics.sent', ['count' => 1, 'amount' => 100])
            ->where('hasInvoices', true));
    }

    public function test_no_invoices_at_all(): void
    {
        $this->page()->assertInertia(fn ($page) => $page->where('hasInvoices', false)->has('invoices', 0)->where('hasEarlier', false));
    }
}
