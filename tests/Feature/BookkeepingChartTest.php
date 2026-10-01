<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookkeepingChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_chart_has_each_month_so_far_with_income_less_tax_expenses_and_net(): void
    {
        $this->travelTo('2026-03-15 10:00:00');
        Transaction::create(['type' => 'income', 'amount' => 1077.5, 'tax_amount' => 77.5, 'occurred_on' => '2026-01-20']);
        Transaction::create(['type' => 'income', 'amount' => 500, 'occurred_on' => '2026-03-02']);
        Expense::create(['name' => 'Linode', 'amount' => 40, 'date' => '2026-01-01']);
        Expense::create(['name' => 'Linode', 'amount' => 40, 'date' => '2026-02-01']);

        $this->actingAs(User::factory()->create())->get('/bookkeeping')->assertInertia(fn ($page) => $page
            ->where('year.year', 2026)
            ->has('year.months', 12)
            ->where('year.months.0.income', 1000)
            ->where('year.months.0.expenses', 40)
            ->where('year.months.0.net', 960)
            ->where('year.months.1.income', 0)
            ->where('year.months.1.net', -40)
            ->where('year.months.2.net', 500)
            // April onwards hasn't happened yet.
            ->where('year.months.3.income', null));
    }
}
