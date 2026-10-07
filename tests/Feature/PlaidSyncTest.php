<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PlaidItem;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaidSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.plaid' => ['client_id' => 'client', 'secret' => 'secret', 'env' => 'sandbox']]);
    }

    private function transaction(string $id, float $amount, array $extra = []): array
    {
        return [
            'transaction_id' => $id,
            'account_id' => 'acc-1',
            'amount' => $amount,
            'iso_currency_code' => 'USD',
            'date' => '2026-09-14',
            'name' => 'ADOBE *CREATIVE CLD',
            'merchant_name' => 'Adobe',
            'pending' => false,
            'personal_finance_category' => ['primary' => 'GENERAL_SERVICES', 'detailed' => 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES'],
            ...$extra,
        ];
    }

    private function syncPage(array $added = [], array $modified = [], array $removed = [], string $cursor = 'cursor-1'): array
    {
        return [
            'added' => $added,
            'modified' => $modified,
            'removed' => $removed,
            'accounts' => [['account_id' => 'acc-1', 'name' => 'Business Card', 'mask' => '4521']],
            'next_cursor' => $cursor,
            'has_more' => false,
        ];
    }

    private function connectedItem(): PlaidItem
    {
        return PlaidItem::create(['item_id' => 'item-1', 'access_token' => 'access-sandbox-1', 'institution_name' => 'Chase']);
    }

    public function test_connecting_a_bank_stores_its_token_encrypted_and_imports_its_charges(): void
    {
        Http::fake([
            '*/item/public_token/exchange' => Http::response(['access_token' => 'access-sandbox-1', 'item_id' => 'item-1']),
            '*/transactions/sync' => Http::response($this->syncPage([
                $this->transaction('t-1', 54.99),
                $this->transaction('t-2', -810, ['name' => 'Payroll deposit', 'merchant_name' => null, 'personal_finance_category' => ['primary' => 'INCOME']]),
                $this->transaction('t-3', 300, ['name' => 'Card payment', 'personal_finance_category' => ['primary' => 'LOAN_PAYMENTS']]),
                $this->transaction('t-4', 18, ['pending' => true]),
            ])),
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/plaid/items', ['public_token' => 'public-sandbox-1', 'institution_name' => 'Chase'])
            ->assertOk()
            ->assertJsonPath('result.added', 1)
            ->assertJsonMissingPath('item.access_token');

        $item = PlaidItem::sole();
        $this->assertSame('access-sandbox-1', $item->access_token);
        $this->assertNotSame('access-sandbox-1', $item->getRawOriginal('access_token'));
        $this->assertSame('cursor-1', $item->cursor);

        $expense = Expense::sole();
        $this->assertSame('Adobe', $expense->name);
        $this->assertSame('54.99', $expense->amount);
        $this->assertSame('Chase ••4521', $expense->source_label);
        $this->assertSame('unbilled', $expense->billing_status);
        $this->assertFalse($expense->is_billable);
    }

    public function test_a_second_sync_picks_up_only_whats_new_and_applies_changes_and_removals(): void
    {
        $item = $this->connectedItem();
        Http::fakeSequence('*/transactions/sync')
            ->push($this->syncPage([$this->transaction('t-1', 54.99), $this->transaction('t-2', 20, ['merchant_name' => 'Figma'])]))
            ->push($this->syncPage(
                added: [$this->transaction('t-1', 54.99), $this->transaction('t-5', 12.65, ['merchant_name' => 'Spotify'])],
                modified: [$this->transaction('t-1', 59.99)],
                removed: [['transaction_id' => 't-2']],
                cursor: 'cursor-2',
            ));

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/plaid/sync')->assertOk()->assertJsonPath('result.added', 2);
        $this->actingAs($user)->postJson('/api/plaid/sync')->assertOk()
            ->assertJsonPath('result', ['added' => 1, 'updated' => 1, 'removed' => 1]);

        $this->assertEqualsCanonicalizing(['Adobe', 'Spotify'], Expense::pluck('name')->all());
        $this->assertSame('59.99', Expense::firstWhere('name', 'Adobe')->amount);
        $this->assertSame('cursor-2', $item->fresh()->cursor);
        Http::assertSent(fn ($request) => ($request['cursor'] ?? null) === 'cursor-1');
    }

    public function test_bank_charges_post_to_the_card_and_changes_and_removals_follow_in_the_ledger(): void
    {
        $this->connectedItem();
        Http::fakeSequence('*/transactions/sync')
            ->push($this->syncPage([$this->transaction('t-1', 54.99), $this->transaction('t-2', 20, ['merchant_name' => 'Figma'])]))
            ->push($this->syncPage(modified: [$this->transaction('t-1', 59.99)], removed: [['transaction_id' => 't-2']], cursor: 'cursor-2'));
        $card = Account::forKey('capital_one_card');
        $owed = fn () => (int) $card->lines()->sum('credit_cents') - (int) $card->lines()->sum('debit_cents');

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/plaid/sync')->assertOk();
        $this->assertSame(7499, $owed());

        $this->actingAs($user)->postJson('/api/plaid/sync')->assertOk();
        $this->assertSame(5999, $owed(), 'Adobe went up, Figma came back out');
    }

    public function test_a_change_from_the_bank_inside_a_locked_period_is_skipped(): void
    {
        $this->connectedItem();
        Http::fakeSequence('*/transactions/sync')
            ->push($this->syncPage([$this->transaction('t-1', 54.99)]))
            ->push($this->syncPage(modified: [$this->transaction('t-1', 59.99)], cursor: 'cursor-2'));

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/plaid/sync')->assertOk();
        app(Ledger::class)->lockPeriod('2026-09-01', '2026-09-30');

        $this->actingAs($user)->postJson('/api/plaid/sync')->assertOk()->assertJsonPath('result.updated', 0);
        $this->assertSame('54.99', Expense::sole()->amount);
    }

    public function test_a_deleted_bank_charge_never_syncs_back(): void
    {
        $this->connectedItem();
        Http::fake(['*/transactions/sync' => Http::response($this->syncPage([$this->transaction('t-1', 54.99)]))]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/plaid/sync')->assertOk();
        $this->actingAs($user)->deleteJson('/api/expenses/'.Expense::sole()->id)->assertNoContent();

        // Plaid sends it again (a fresh connection starts over).
        PlaidItem::query()->update(['cursor' => null]);
        $this->actingAs($user)->postJson('/api/plaid/sync')->assertOk()->assertJsonPath('result.added', 0);
        $this->assertSame(0, Expense::count());
    }

    public function test_billed_expenses_are_left_alone_by_changes(): void
    {
        $this->connectedItem();
        Expense::create(['name' => 'Adobe', 'amount' => 54.99, 'date' => '2026-09-14', 'plaid_transaction_id' => 't-1', 'billing_status' => 'billed']);
        Http::fake(['*/transactions/sync' => Http::response($this->syncPage(modified: [$this->transaction('t-1', 99)], removed: [['transaction_id' => 't-1']]))]);

        $this->actingAs(User::factory()->create())->postJson('/api/plaid/sync')->assertOk();

        $this->assertSame('54.99', Expense::sole()->amount);
    }

    public function test_a_flight_gets_the_flights_category(): void
    {
        $this->connectedItem();
        $travel = ExpenseCategory::where('name', 'Flights, Taxi & Transportation')->firstOrFail();
        Http::fake(['*/transactions/sync' => Http::response($this->syncPage([$this->transaction('t-1', 220, ['merchant_name' => 'United', 'personal_finance_category' => ['primary' => 'TRAVEL', 'detailed' => 'TRAVEL_FLIGHTS']])]))]);

        $this->actingAs(User::factory()->create())->postJson('/api/plaid/sync')->assertOk();

        $this->assertSame($travel->id, Expense::sole()->category_id);
    }

    public function test_a_failed_sync_is_recorded_on_the_bank(): void
    {
        $item = $this->connectedItem();
        Http::fake(['*/transactions/sync' => Http::response(['error_code' => 'ITEM_LOGIN_REQUIRED'], 400)]);

        $this->actingAs(User::factory()->create())->postJson('/api/plaid/sync')->assertOk()->assertJsonPath('items.0.last_error', 'ITEM_LOGIN_REQUIRED');
        $this->assertSame('ITEM_LOGIN_REQUIRED', $item->fresh()->last_error);
    }

    public function test_disconnecting_removes_the_bank_and_keeps_its_expenses(): void
    {
        $item = $this->connectedItem();
        Expense::create(['name' => 'Adobe', 'amount' => 54.99, 'date' => '2026-09-14', 'plaid_transaction_id' => 't-1']);
        Http::fake(['*/item/remove' => Http::response(['request_id' => 'r'])]);

        $this->actingAs(User::factory()->create())->deleteJson("/api/plaid/items/{$item->id}")->assertNoContent();

        $this->assertSame(0, PlaidItem::count());
        $this->assertSame(1, Expense::count());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/item/remove') && $request['access_token'] === 'access-sandbox-1');
    }

    public function test_team_members_cant_use_the_bank_feed(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'team_member']))->postJson('/api/plaid/sync')->assertForbidden();
    }
}
