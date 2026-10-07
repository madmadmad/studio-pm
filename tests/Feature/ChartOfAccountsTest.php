<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InvoiceCategory;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The chart the 2026_10_06_110000 migration seeds (docs/ledger-plan.md,
// section 3), and the Bonsai categories mapped onto it.
class ChartOfAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_accounts_the_ledger_depends_on_exist_with_the_right_type(): void
    {
        $expected = [
            'checking' => Account::ASSET,
            'stripe_clearing' => Account::ASSET,
            'capital_one_card' => Account::LIABILITY,
            'sales_tax_payable' => Account::LIABILITY,
            'shareholder_distributions' => Account::EQUITY,
            'retained_earnings' => Account::EQUITY,
            'opening_balance_equity' => Account::EQUITY,
            'service_revenue' => Account::INCOME,
            'ad_management_revenue' => Account::INCOME,
            'client_media_revenue' => Account::INCOME,
            'hosting_revenue' => Account::INCOME,
            'surcharge_income' => Account::INCOME,
            'other_income' => Account::INCOME,
            'hosting_cost' => Account::EXPENSE,
            'client_media_spend' => Account::EXPENSE,
            'merchant_fees' => Account::EXPENSE,
            'officer_compensation' => Account::EXPENSE,
            'wages' => Account::EXPENSE,
            'payroll_taxes' => Account::EXPENSE,
            'retirement_expense' => Account::EXPENSE,
            'uncategorized_expense' => Account::EXPENSE,
        ];

        foreach ($expected as $key => $type) {
            $account = Account::forKey($key);
            $this->assertSame($type, $account->type, $key);
            $this->assertTrue($account->is_active, $key);
        }

        $this->assertSame('Payment Processing Fees', Account::forKey('merchant_fees')->name);
    }

    public function test_every_account_sits_under_a_group_heading_and_codes_are_placeholders(): void
    {
        $headings = Account::whereNull('parent_id')->pluck('system_key')->sort()->values()->all();
        $this->assertSame(['assets', 'cost_of_revenue', 'equity', 'income', 'liabilities', 'operating_expenses'], $headings);

        foreach (Account::whereNotNull('parent_id')->with('parent')->get() as $account) {
            $this->assertSame($account->parent->type, $account->type, "{$account->name} matches its heading's type");
        }

        $this->assertSame(0, Account::where('code_is_placeholder', false)->count());
        $this->assertSame('cost_of_revenue', Account::forKey('hosting_cost')->parent->system_key);
        $this->assertSame('cost_of_revenue', Account::forKey('client_media_spend')->parent->system_key);
        $this->assertSame('operating_expenses', Account::where('name', 'Advertising & Marketing')->first()->parent->system_key);
    }

    public function test_the_cpa_flags_are_in_the_account_descriptions(): void
    {
        $flags = [
            'Charitable Donations' => 'not a business deduction',
            'Business Meals' => '50% deductible',
            'Client Entertainment' => 'not deductible',
            'Electronics & Furniture' => 'capitalized',
        ];

        foreach ($flags as $name => $flag) {
            $this->assertStringContainsString($flag, Account::where('name', $name)->value('description'), $name);
        }
    }

    public function test_every_bonsai_category_exists_and_posts_to_an_account(): void
    {
        foreach (array_keys(ChartOfAccountsSeeder::CATEGORIES) as $name) {
            $category = ExpenseCategory::where('name', $name)->first();
            $this->assertNotNull($category, "{$name} exists");
            $this->assertNotNull($category->account_id, "{$name} has an account");
        }

        $this->assertSame('Hosting Cost', ExpenseCategory::where('name', 'Hosting')->first()->account->name);
        $this->assertSame('Wages', ExpenseCategory::where('name', 'Wages & Commissions')->first()->account->name);
        $this->assertSame('Hotel & Accommodation', ExpenseCategory::where('name', 'Hotel & Accommodation')->first()->account->name);
    }

    public function test_advertising_is_our_marketing_unless_its_billed_to_a_client(): void
    {
        $advertising = ExpenseCategory::where('name', 'Advertising')->first();

        $this->assertSame('Advertising & Marketing', $advertising->account->name);
        $this->assertSame('Client Media Spend', $advertising->billableAccount->name);
    }

    public function test_draws_and_personal_spending_arent_expense_categories(): void
    {
        foreach (['Draw', 'Personal', 'Depreciation', 'Credit card refund credit', 'Depletion', 'Child Care', 'Home Office'] as $name) {
            $this->assertFalse(ExpenseCategory::where('name', $name)->exists(), $name);
            $this->assertArrayHasKey($name, ChartOfAccountsSeeder::BONSAI_ALIASES);
        }

        $this->assertSame(ChartOfAccountsSeeder::DISTRIBUTION, ChartOfAccountsSeeder::BONSAI_ALIASES['Draw']);
        $this->assertSame('Hotel & Accommodation', ChartOfAccountsSeeder::BONSAI_ALIASES['Hotel & Accomodation']);
    }

    public function test_the_original_categories_became_their_bonsai_equivalents(): void
    {
        foreach (ChartOfAccountsSeeder::RENAMED_CATEGORIES as $from => $to) {
            $this->assertFalse(ExpenseCategory::where('name', $from)->exists(), "{$from} is gone");
            $this->assertSame(1, ExpenseCategory::where('name', $to)->count(), "one {$to}");
        }
    }

    public function test_folding_a_category_moves_its_expenses_to_the_one_that_already_exists(): void
    {
        $equipment = ExpenseCategory::create(['name' => 'Equipment', 'color' => '#595F64']);
        $expense = Expense::create(['name' => 'Monitor', 'amount' => '400.00', 'date' => '2026-03-04', 'is_billable' => false, 'category_id' => $equipment->id]);

        (new ChartOfAccountsSeeder)->foldRenamedCategories();

        $this->assertNull(ExpenseCategory::find($equipment->id));
        $this->assertSame('Electronics & Furniture', $expense->fresh()->category->name);
    }

    public function test_hosting_invoices_post_to_hosting_revenue(): void
    {
        $this->assertSame('hosting_revenue', InvoiceCategory::where('name', 'Hosting')->first()->revenueAccount->system_key);
    }

    public function test_seeding_again_adds_nothing_and_keeps_choices_made_in_the_app(): void
    {
        $internet = ExpenseCategory::where('name', 'Internet')->first();
        $internet->update(['account_id' => Account::where('name', 'Telephone')->value('id')]);
        $counts = [Account::count(), ExpenseCategory::count()];

        (new ChartOfAccountsSeeder)->run();

        $this->assertSame($counts, [Account::count(), ExpenseCategory::count()]);
        $this->assertSame('Telephone', $internet->fresh()->account->name);
    }
}
