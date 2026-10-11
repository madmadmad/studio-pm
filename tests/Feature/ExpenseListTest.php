<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// The Expenses page a few months at a time, as Invoices: All, Unbilled and
// Paid list the latest three months with expenses, whole, and "Show earlier
// months" adds three more; Billed lists everything. Filters and search run
// on the server, and the figures and the year's chart cover every expense.
class ExpenseListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'America/New_York'));
    }

    private function expense(string $date, array $extra = []): Expense
    {
        // A billable expense belongs to a project.
        if ($extra['is_billable'] ?? false) {
            $extra['project_id'] ??= Company::firstOrCreate(['name' => 'Acme'])->projects()->firstOrCreate(['name' => 'Rebrand'])->id;
        }

        return Expense::create([
            'name' => 'Figma',
            'amount' => 100,
            'currency' => 'USD',
            'date' => $date,
            'is_billable' => false,
            'billing_status' => 'unbilled',
            ...$extra,
        ]);
    }

    private function page(array $query = [])
    {
        return $this->actingAs(User::factory()->create())
            ->get('/expenses?'.http_build_query($query))
            ->assertOk();
    }

    private function listed($response): array
    {
        return collect($response->viewData('page')['props']['expenses'])->pluck('id')->sort()->values()->all();
    }

    private function ids(Expense ...$expenses): array
    {
        return collect($expenses)->pluck('id')->sort()->values()->all();
    }

    public function test_all_lists_the_latest_three_months_with_expenses_and_shows_earlier_on_request(): void
    {
        $byMonth = collect(['2026-10-01', '2026-10-20', '2026-08-05', '2026-06-30', '2026-03-01'])->map(fn ($d) => $this->expense($d));

        $first = $this->page();
        $this->assertSame($this->ids(...$byMonth->take(4)->all()), $this->listed($first));
        $first->assertInertia(fn ($page) => $page->where('hasEarlier', true)->where('filters.months', 3));

        $more = $this->page(['months' => 6]);
        $this->assertSame($this->ids(...$byMonth->all()), $this->listed($more));
        $more->assertInertia(fn ($page) => $page->where('hasEarlier', false));
    }

    public function test_billed_lists_everything(): void
    {
        $old = $this->expense('2024-01-10', ['is_billable' => true, 'billing_status' => 'billed']);
        $new = $this->expense('2026-10-01', ['is_billable' => true, 'billing_status' => 'billed']);
        $this->expense('2026-10-02');

        $response = $this->page(['status' => 'billed']);

        $this->assertSame($this->ids($old, $new), $this->listed($response));
        $response->assertInertia(fn ($page) => $page->where('hasEarlier', false));
    }

    public function test_search_and_category_cover_every_expense(): void
    {
        $software = ExpenseCategory::create(['name' => 'Software Tools', 'color' => '#595F64']);
        $old = $this->expense('2024-02-01', ['name' => 'Linode hosting']);
        $tagged = $this->expense('2025-05-01', ['category_id' => $software->id]);
        $this->expense('2026-10-01');

        $this->assertSame($this->ids($old), $this->listed($this->page(['q' => 'linode'])));
        $this->assertSame($this->ids($tagged), $this->listed($this->page(['category' => (string) $software->id])));
        $this->assertSame([], $this->listed($this->page(['q' => '_'])));
    }

    public function test_the_figures_and_chart_are_over_every_expense(): void
    {
        $this->expense('2026-10-03', ['amount' => 50]);
        $this->expense('2026-02-10', ['amount' => 200, 'is_billable' => true]);
        $this->expense('2025-12-31', ['amount' => 999]);
        $this->expense('2026-01-15', ['amount' => 30, 'is_billable' => true, 'billing_status' => 'billed']);
        // A split bill: $40 of $100 is a client's, the rest the studio's.
        $split = $this->expense('2026-10-05', ['amount' => 100]);
        $split->splits()->create(['company_id' => Company::firstOrCreate(['name' => 'Acme'])->id, 'amount' => 40]);
        $split->splits()->create(['company_id' => null, 'amount' => 60]);

        $this->page(['status' => 'billed'])->assertInertia(fn ($page) => $page
            ->where('metrics.month', ['count' => 2, 'amount' => 150])
            ->where('metrics.year', ['count' => 4, 'amount' => 380])
            ->where('metrics.ready', ['count' => 1, 'amount' => 200])
            ->where('metrics.billed', ['count' => 1, 'amount' => 30])
            ->where('spend.year', '2026')
            ->where('spend.months.0', ['month' => 1, 'billable' => 30, 'studio' => 0, 'total' => 30])
            ->where('spend.months.1', ['month' => 2, 'billable' => 200, 'studio' => 0, 'total' => 200])
            ->where('spend.months.9', ['month' => 10, 'billable' => 40, 'studio' => 110, 'total' => 150])
            ->where('spend.months.10', ['month' => 11, 'billable' => null, 'studio' => null, 'total' => null])
            ->where('hasExpenses', true));
    }

    public function test_no_expenses_at_all(): void
    {
        $this->page()->assertInertia(fn ($page) => $page->where('hasExpenses', false)->has('expenses', 0)->where('hasEarlier', false));
    }
}
