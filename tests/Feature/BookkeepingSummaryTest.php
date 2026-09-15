<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookkeepingSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_only_aggregates_the_requested_month(): void
    {
        $user = User::factory()->create();

        Transaction::create(['type' => 'income', 'amount' => 500, 'occurred_on' => '2026-09-05']);
        Transaction::create(['type' => 'income', 'amount' => 250, 'occurred_on' => '2026-09-20']);
        Transaction::create(['type' => 'expense', 'amount' => 120, 'occurred_on' => '2026-09-10']);
        // Different month -- must not be included.
        Transaction::create(['type' => 'income', 'amount' => 9999, 'occurred_on' => '2026-08-15']);

        $response = $this->actingAs($user)->getJson('/api/bookkeeping/summary?month=2026-09');

        $response->assertOk();
        $response->assertJson([
            'month' => '2026-09',
            'income' => 750.0,
            'expenses' => 120.0,
            'net' => 630.0,
        ]);
    }
}
