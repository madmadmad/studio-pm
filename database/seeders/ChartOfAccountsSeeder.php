<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// The starting chart of accounts (docs/ledger-plan.md, section 3), the
// expense categories that mirror our Bonsai tags, and which account each
// category posts to. Run by the 2026_10_06_110000 migration, so a deploy
// creates it. Safe to run again: it only adds what's missing and never
// changes or removes a row -- once the chart exists, it's edited in the app.
//
// Codes are placeholders until our CPA supplies a chart. The account names
// and Bonsai mappings live here and nowhere else, so accounts can be
// merged or renamed later (Studio Software / Work Devices & Software /
// Client Software may be). Code finds the accounts it depends on by
// system_key, never by name.
//
// Uses the query builder rather than models, so this keeps working from
// its migration however the models change.
class ChartOfAccountsSeeder extends Seeder
{
    // [code, name, type, system_key, description]. Each group's first row
    // is its heading: the parent of the rows under it, and not postable.
    public const ACCOUNTS = [
        'assets' => [
            ['1000', 'Assets', 'asset', 'assets', null],
            ['1010', 'Checking – Waterford Bank', 'asset', 'checking', null],
            ['1050', 'Stripe Clearing', 'asset', 'stripe_clearing', 'Card and ACH payments collected by Stripe and not yet paid out to checking.'],
        ],
        'liabilities' => [
            ['2000', 'Liabilities', 'liability', 'liabilities', null],
            ['2010', 'Capital One Card', 'liability', 'capital_one_card', 'Most expenses are charged here; paid off monthly from checking (a transfer, not an expense).'],
            ['2200', 'Sales Tax Payable', 'liability', 'sales_tax_payable', 'Ohio sales tax collected on invoices, owed to the state until remitted.'],
        ],
        'equity' => [
            ['3000', 'Equity', 'equity', 'equity', null],
            ['3010', 'Shareholder Capital', 'equity', 'shareholder_capital', null],
            ['3020', 'Shareholder Distributions', 'equity', 'shareholder_distributions', 'Draws and personal spending (Bonsai "Draw" and "Personal"). Not an expense.'],
            ['3900', 'Retained Earnings', 'equity', 'retained_earnings', null],
            ['3950', 'Opening Balance Equity', 'equity', 'opening_balance_equity', 'The other side of the opening balances on the day the ledger starts.'],
        ],
        'income' => [
            ['4000', 'Income', 'income', 'income', null],
            ['4010', 'Design & Development Services', 'income', 'service_revenue', 'Project work: design and development. The default for invoice lines with no other revenue account.'],
            ['4020', 'Ad Management', 'income', 'ad_management_revenue', 'Our time managing client ad campaigns.'],
            ['4030', 'Client Media', 'income', 'client_media_revenue', 'Client ad spend rebilled to them (the cost is Client Media Spend).'],
            ['4040', 'Hosting', 'income', 'hosting_revenue', 'Hosting billed to clients, marked up (the cost is Hosting Cost).'],
            ['4050', 'Printing', 'income', 'printing_revenue', 'Printing resold to clients, marked up (the cost is Printing Cost).'],
            ['4090', 'Card Surcharge Income', 'income', 'surcharge_income', 'The card processing fee clients pay to cover Stripe\'s fee.'],
            ['4095', 'Late Fee Income', 'income', 'late_fee_income', 'Late fees clients paid on overdue invoices.'],
            ['4900', 'Other Income', 'income', 'other_income', null],
        ],
        'cost_of_revenue' => [
            ['5000', 'Cost of Revenue', 'expense', 'cost_of_revenue', 'Costs of delivering client work. Gross profit is income less these.'],
            ['5010', 'Hosting Cost', 'expense', 'hosting_cost', 'Linode, AWS, Forge and similar (Bonsai "Hosting").'],
            ['5020', 'Client Media Spend', 'expense', 'client_media_spend', 'Ad spend run for clients and rebilled. Not our own marketing.'],
            ['5025', 'Printing Cost', 'expense', 'printing_cost', 'Printing bought for clients and resold (taxable when billed). The studio\'s own printing is Advertising & Marketing. CPA: bought for resale, so it may be exempt from sales tax with a resale certificate.'],
            ['5030', 'Client Software', 'expense', null, null],
            ['5040', 'Subcontractors', 'expense', null, null],
            ['5050', 'Cost of Labor', 'expense', null, null],
            ['5060', 'Materials & Supplies', 'expense', null, null],
            ['5090', 'Misc COGS', 'expense', null, null],
        ],
        'operating_expenses' => [
            ['6000', 'Operating Expenses', 'expense', 'operating_expenses', null],
            ['6010', 'Accounting Fees', 'expense', null, null],
            ['6020', 'Advertising & Marketing', 'expense', null, 'The studio\'s own marketing. Client ad spend is Client Media Spend.'],
            ['6030', 'Auto Insurance', 'expense', null, null],
            ['6040', 'Business Insurance', 'expense', null, null],
            ['6050', 'Business Meals', 'expense', null, 'CPA: generally 50% deductible.'],
            ['6060', 'Car & Truck Expenses', 'expense', null, null],
            ['6070', 'Charitable Donations', 'expense', null, 'CPA: passes through to the shareholder return; not a business deduction.'],
            ['6080', 'Client Entertainment', 'expense', null, 'CPA: generally not deductible.'],
            ['6090', 'Depreciation', 'expense', null, 'Year-end entries from our CPA only.'],
            ['6100', 'Education & Training', 'expense', null, null],
            ['6110', 'Electronics & Furniture', 'expense', null, 'CPA: larger purchases may need to be capitalized.'],
            ['6120', 'Equipment Repairs', 'expense', null, null],
            ['6130', 'Flights, Taxi & Transportation', 'expense', null, null],
            ['6140', 'Gas & Fuel', 'expense', null, null],
            ['6150', 'Health & Life Insurance', 'expense', null, null],
            ['6160', 'Hotel & Accommodation', 'expense', null, null],
            ['6170', 'HSA fees', 'expense', null, null],
            ['6180', 'Internet', 'expense', null, null],
            ['6190', 'Local Taxes', 'expense', null, null],
            ['6200', 'Misc Fees', 'expense', null, null],
            ['6210', 'Mobile Phone', 'expense', null, null],
            ['6220', 'Officer Compensation', 'expense', 'officer_compensation', 'Shareholder-employee salary (payroll).'],
            ['6230', 'Ohio State/County Sales Tax', 'expense', null, 'Sales or use tax we pay on purchases. Tax collected on invoices is Sales Tax Payable.'],
            ['6240', 'Ohio State Workers\' Compensation tax', 'expense', null, null],
            ['6250', 'Other Expenses', 'expense', null, null],
            ['6260', 'Other Office Expenses', 'expense', null, null],
            ['6270', 'Other Perks & Benefits', 'expense', null, null],
            ['6280', 'Other Travel Expenses', 'expense', null, null],
            ['6290', 'Payment Processing Fees', 'expense', 'merchant_fees', 'Stripe\'s fees on client payments.'],
            ['6300', 'Payroll Processing Fees', 'expense', null, null],
            ['6310', 'Payroll Taxes', 'expense', 'payroll_taxes', null],
            ['6320', 'Professional Services', 'expense', null, null],
            ['6330', 'Real Estate Taxes', 'expense', null, null],
            ['6340', 'Rental Equipment', 'expense', null, null],
            ['6350', 'Rent & Lease Property', 'expense', null, null],
            ['6360', 'Retirement Expense', 'expense', 'retirement_expense', 'Retirement plan match (payroll).'],
            ['6370', 'Studio Software', 'expense', null, null],
            ['6380', 'Subscriptions & Memberships', 'expense', null, null],
            ['6390', 'Taxes & Licenses', 'expense', null, null],
            ['6400', 'Telephone', 'expense', null, null],
            ['6410', 'Utilities', 'expense', null, null],
            ['6420', 'Wages', 'expense', 'wages', 'Employee wages (payroll). Officer salary is Officer Compensation.'],
            ['6430', 'Work Devices & Software', 'expense', null, null],
            ['6990', 'Uncategorized Expense', 'expense', 'uncategorized_expense', 'Expenses with no category, or a category with no account. Reclassify before closing a period.'],
        ],
    ];

    // Expense category => [account name, account name when billable]. The
    // categories people pick when recording an expense, named as in Bonsai.
    public const CATEGORIES = [
        'Accounting Fees' => ['Accounting Fees'],
        'Advertising' => ['Advertising & Marketing', 'Client Media Spend'],
        'Auto Insurance' => ['Auto Insurance'],
        'Business Insurance' => ['Business Insurance'],
        'Business Meals' => ['Business Meals'],
        'Car & Truck Expenses' => ['Car & Truck Expenses'],
        'Charitable Donations' => ['Charitable Donations'],
        'Client Entertainment' => ['Client Entertainment'],
        'Client Software' => ['Client Software'],
        'Cost of Labor' => ['Cost of Labor'],
        'Education & Training' => ['Education & Training'],
        'Electronics & Furniture' => ['Electronics & Furniture'],
        'Equipment Repairs' => ['Equipment Repairs'],
        'Flights, Taxi & Transportation' => ['Flights, Taxi & Transportation'],
        'Gas & Fuel' => ['Gas & Fuel'],
        'Health & Life Insurance' => ['Health & Life Insurance'],
        'Hosting' => ['Hosting Cost'],
        'Hotel & Accommodation' => ['Hotel & Accommodation'],
        'HSA fees' => ['HSA fees'],
        'Internet' => ['Internet'],
        'Local Taxes' => ['Local Taxes'],
        'Materials & Supplies' => ['Materials & Supplies'],
        'Misc COGS' => ['Misc COGS'],
        'Misc Fees' => ['Misc Fees'],
        'Mobile Phone' => ['Mobile Phone'],
        'Ohio State/County Sales Tax' => ['Ohio State/County Sales Tax'],
        'Ohio State Workers\' Compensation tax' => ['Ohio State Workers\' Compensation tax'],
        'Other Expenses' => ['Other Expenses'],
        'Other Office Expenses' => ['Other Office Expenses'],
        'Other Perks & Benefits' => ['Other Perks & Benefits'],
        'Other Travel Expenses' => ['Other Travel Expenses'],
        'Payment Processing Fees' => ['Payment Processing Fees'],
        'Payroll Processing Fees' => ['Payroll Processing Fees'],
        'Payroll Taxes' => ['Payroll Taxes'],
        'Printing' => ['Advertising & Marketing', 'Printing Cost'],
        'Professional Services' => ['Professional Services'],
        'Real Estate Taxes' => ['Real Estate Taxes'],
        'Rental Equipment' => ['Rental Equipment'],
        'Rent & Lease Property' => ['Rent & Lease Property'],
        'Retirement Expense' => ['Retirement Expense'],
        'Studio Software' => ['Studio Software'],
        'Subcontractors' => ['Subcontractors'],
        'Subscriptions & Memberships' => ['Subscriptions & Memberships'],
        'Taxes & Licenses' => ['Taxes & Licenses'],
        'Telephone' => ['Telephone'],
        'Utilities' => ['Utilities'],
        'Wages & Commissions' => ['Wages'],
        'Work Devices & Software' => ['Work Devices & Software'],
    ];

    // Category => [revenue account handle, taxable]: how its expenses read
    // when rebilled on an invoice. The line's income posts to that account
    // (rather than the service's or the invoice's), and starts out taxable
    // when flagged -- printing is one of the few taxable things we sell.
    public const REBILLING = [
        'Hosting' => ['hosting_revenue', false],
        'Advertising' => ['client_media_revenue', false],
        'Printing' => ['printing_revenue', true],
    ];

    // The app's original categories, folded into their Bonsai equivalents
    // by the migration (Advertising and Hosting keep their names).
    public const RENAMED_CATEGORIES = [
        'Software & Subscriptions' => 'Subscriptions & Memberships',
        'Equipment' => 'Electronics & Furniture',
        'Travel' => 'Other Travel Expenses',
        'Office Supplies' => 'Other Office Expenses',
        'Contractors' => 'Subcontractors',
    ];

    // Bonsai tag names that aren't a category of ours, for the Bonsai
    // import (Phase 6). A string is the category the tag becomes; the
    // others say what to do with the row instead of guessing.
    public const BONSAI_ALIASES = [
        'Hotel & Accomodation' => 'Hotel & Accommodation', // Bonsai's spelling
        'Draw' => self::DISTRIBUTION,
        'Personal' => self::DISTRIBUTION,
        'Depreciation' => self::JOURNAL_ONLY,
        'Credit card refund credit' => self::REVIEW,
        'Depletion' => self::REPORT,
        'Child Care' => self::REPORT,
        'Home Office' => self::REPORT,
    ];

    // A Shareholder Distributions entry, not an expense.
    public const DISTRIBUTION = 'distribution';

    // The CPA's year-end entries, not expenses.
    public const JOURNAL_ONLY = 'journal_only';

    // Listed for a person to decide.
    public const REVIEW = 'review';

    // Doesn't apply to us; listed if any rows use it.
    public const REPORT = 'report';

    public function run(): void
    {
        $this->seedAccounts();
        $this->seedCategories();
    }

    // Each of the app's original categories becomes its Bonsai equivalent:
    // renamed, or -- if that category already exists -- its expenses moved
    // there and the original removed. Run once, by the migration.
    public function foldRenamedCategories(): void
    {
        foreach (self::RENAMED_CATEGORIES as $from => $to) {
            $original = DB::table('expense_categories')->where('name', $from)->first();
            if (! $original) {
                continue;
            }

            $existing = DB::table('expense_categories')->where('name', $to)->first();
            if (! $existing) {
                DB::table('expense_categories')->where('id', $original->id)->update(['name' => $to, 'updated_at' => now()]);

                continue;
            }

            DB::table('expenses')->where('category_id', $original->id)->update(['category_id' => $existing->id]);
            DB::table('expense_categories')->where('id', $original->id)->delete();
        }
    }

    // Sets REBILLING on categories that don't have a revenue account yet.
    // Run by the 2026_10_07_100000 migration, which adds the columns.
    public function seedRebilling(): void
    {
        foreach (self::REBILLING as $category => [$revenueKey, $taxable]) {
            DB::table('expense_categories')->where('name', $category)->whereNull('revenue_account_id')->update([
                'revenue_account_id' => DB::table('accounts')->where('system_key', $revenueKey)->value('id'),
                'taxable_when_billed' => $taxable,
            ]);
        }
    }

    private function seedAccounts(): void
    {
        foreach (self::ACCOUNTS as $rows) {
            $headingId = null;
            foreach ($rows as $i => [$code, $name, $type, $systemKey, $description]) {
                $id = $this->accountId($systemKey, $name) ?? DB::table('accounts')->insertGetId([
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'system_key' => $systemKey,
                    'parent_id' => $i === 0 ? null : $headingId,
                    'description' => $description,
                    'code_is_placeholder' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($i === 0) {
                    $headingId = $id;
                }
            }
        }
    }

    // Creates the missing categories, and points every category that has
    // no account yet at its account (never overriding a choice made in the
    // app).
    private function seedCategories(): void
    {
        foreach (self::CATEGORIES as $category => $accounts) {
            [$accountName, $billableName] = [$accounts[0], $accounts[1] ?? null];
            $row = DB::table('expense_categories')->where('name', $category)->first();
            if (! $row) {
                DB::table('expense_categories')->insert([
                    'name' => $category,
                    'color' => '#595F64',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('expense_categories')->where('name', $category)->first();
            }

            $changes = array_filter([
                'account_id' => $row->account_id ? null : $this->accountId(null, $accountName),
                'billable_account_id' => $row->billable_account_id || ! $billableName ? null : $this->accountId(null, $billableName),
            ]);
            if ($changes) {
                DB::table('expense_categories')->where('id', $row->id)->update($changes);
            }
        }
    }

    private function accountId(?string $systemKey, string $name): ?int
    {
        $query = DB::table('accounts');
        $query = $systemKey ? $query->where('system_key', $systemKey) : $query->where('name', $name);

        return $query->value('id');
    }
}
