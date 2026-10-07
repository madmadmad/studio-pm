<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Models\InvoiceCategory;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// Bookkeeping > Chart of accounts, and its Mappings tab: what each expense
// category, service and invoice category posts to.
class AccountMappingTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $permissions): User
    {
        $user = User::factory()->teamMember()->create();
        $user->forceFill(['permissions' => $permissions])->save();

        return $user;
    }

    private function account(string $name): Account
    {
        return Account::where('name', $name)->firstOrFail();
    }

    public function test_the_chart_of_accounts_page_lists_accounts_and_mappings(): void
    {
        $this->actingAs($this->member(['bookkeeping']))->get('/bookkeeping/accounts')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Bookkeeping/Accounts')
            ->has('accounts', Account::count())
            ->has('expenseCategories', ExpenseCategory::count())
            ->where('fallbacks.expense', 'Uncategorized Expense')
            ->where('fallbacks.revenue', 'Design & Development Services'));
    }

    public function test_the_chart_of_accounts_needs_bookkeeping(): void
    {
        $member = $this->member(['expenses']);
        $category = ExpenseCategory::where('name', 'Internet')->first();

        $this->actingAs($member)->get('/bookkeeping/accounts')->assertForbidden();
        $this->actingAs($member)->patchJson("/api/account-mappings/expense-categories/{$category->id}", ['account_id' => null])->assertForbidden();
    }

    public function test_an_expense_category_is_pointed_at_an_expense_account(): void
    {
        $category = ExpenseCategory::create(['name' => 'Stock photos', 'color' => '#595F64']);
        $studioSoftware = $this->account('Studio Software');

        $this->actingAs($this->member(['bookkeeping']))
            ->patchJson("/api/account-mappings/expense-categories/{$category->id}", ['account_id' => $studioSoftware->id])
            ->assertOk()
            ->assertJsonPath('account_id', $studioSoftware->id)
            ->assertJsonPath('billable_account_id', null);

        $this->assertSame($studioSoftware->id, $category->fresh()->account_id);
    }

    public function test_clearing_a_mapping_falls_back(): void
    {
        $advertising = ExpenseCategory::where('name', 'Advertising')->first();

        $this->actingAs($this->member(['bookkeeping']))
            ->patchJson("/api/account-mappings/expense-categories/{$advertising->id}", ['billable_account_id' => null])
            ->assertOk();

        $advertising->refresh();
        $this->assertNull($advertising->billable_account_id);
        $this->assertNotNull($advertising->account_id, 'the other mapping is left alone');
    }

    public function test_a_mapping_must_be_an_active_postable_account_of_the_right_type(): void
    {
        $category = ExpenseCategory::where('name', 'Internet')->first();
        $url = "/api/account-mappings/expense-categories/{$category->id}";
        $manager = $this->member(['bookkeeping']);

        $inactive = $this->account('Rental Equipment');
        $inactive->update(['is_active' => false]);

        $this->actingAs($manager)->patchJson($url, ['account_id' => Account::forKey('operating_expenses')->id])
            ->assertUnprocessable()->assertJsonPath('errors.account_id.0', 'Pick an account, not a group heading.');
        $this->actingAs($manager)->patchJson($url, ['account_id' => Account::forKey('service_revenue')->id])->assertUnprocessable();
        $this->actingAs($manager)->patchJson($url, ['account_id' => $inactive->id])->assertUnprocessable();
        $this->actingAs($manager)->patchJson($url, ['account_id' => 999999])->assertUnprocessable();
    }

    public function test_services_and_invoice_categories_take_a_revenue_account(): void
    {
        $service = Service::create(['name' => 'Ad management', 'default_rate' => 125, 'unit' => 'hourly']);
        $hosting = InvoiceCategory::where('name', 'Hosting')->first();
        $adManagement = Account::forKey('ad_management_revenue');
        $manager = $this->member(['bookkeeping']);

        $this->actingAs($manager)->patchJson("/api/account-mappings/services/{$service->id}", ['revenue_account_id' => $adManagement->id])->assertOk();
        $this->assertSame($adManagement->id, $service->fresh()->revenue_account_id);

        $this->actingAs($manager)->patchJson("/api/account-mappings/invoice-categories/{$hosting->id}", ['revenue_account_id' => Account::forKey('hosting_cost')->id])
            ->assertUnprocessable();
        $this->actingAs($manager)->patchJson("/api/account-mappings/invoice-categories/{$hosting->id}", ['revenue_account_id' => null])->assertOk();
        $this->assertNull($hosting->fresh()->revenue_account_id);
    }
}
