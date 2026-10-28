<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Notifications\SalesTaxRateCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// What came out of the CPA review (Oct 2026) that isn't covered with the
// feature it changed: the chart's new accounts and final codes, and the
// yearly sales tax reminder.
class CpaReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_chart_has_the_new_accounts_and_no_placeholder_codes(): void
    {
        $this->assertSame([Account::LIABILITY, '2300'], [Account::forKey('shareholder_note_payable')->type, Account::forKey('shareholder_note_payable')->code]);
        $this->assertSame(Account::ASSET, Account::forKey('equipment')->type);
        $this->assertSame(Account::ASSET, Account::forKey('accumulated_depreciation')->type);
        $this->assertSame('Electronics & Furniture', Account::forKey('electronics_furniture')->name);
        $this->assertSame(1, Account::where('name', 'Electronics & Furniture')->count());
        $this->assertSame(0, Account::where('code_is_placeholder', true)->count());
    }

    public function test_the_super_admins_are_reminded_to_check_the_sales_tax_rate(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $staff = User::factory()->teamMember()->create();

        $this->artisan('sales-tax:remind')->assertSuccessful();

        Notification::assertSentTo($admin, SalesTaxRateCheck::class, function (SalesTaxRateCheck $n) use ($admin) {
            $data = $n->toArray($admin);

            return str_contains($data['message'], '7.75%') && $data['url'] === '/settings?tab=Invoices%20%26%20payments';
        });
        Notification::assertNotSentTo($staff, SalesTaxRateCheck::class);
    }
}
