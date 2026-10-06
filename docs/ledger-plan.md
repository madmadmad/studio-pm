# Ledger plan

Double-entry bookkeeping under studio-pm's existing invoices, payments,
income and expenses. Phase 0: what's there today, how the design fits it,
the phases, and the questions to settle before code.

Branch: `feature/ledger`, from `main` at `ca3685c`. The suite is green at
the start (530 tests, PHPUnit).

---

## 1. What exists today

### Money is decimal everywhere

Every amount is a `decimal` column, mostly `decimal(10,2)`: no integer cents,
no floats in the schema. PHP does the arithmetic in floats with
`round(..., 2)` (`app/Models/Invoice.php:211-269`); there's no money library.
Some amount columns have a `decimal:2` cast (Transaction, Expense,
ExpenseSplit), some have none (`invoice_items.amount`, `payments.amount`,
`payments.surcharge_amount`). The only cents helpers are local:
`StripeCheckoutService::toCents` (`app/Services/StripeCheckoutService.php:105`)
and a JS `toCents` in `resources/js/Components/NewInvoiceDrawer.jsx:63`.

So the ledger converts at the boundary: a single `App\Support\Money::toCents()`
that parses the decimal *string* (not `float * 100`), used by every poster.

### Invoices, payments, income

| Table | What it holds | Notes |
|---|---|---|
| `invoices` | status draft → sent → paid, `issued_on`, `due_on`, `category_id` (null = project work, or e.g. Hosting), `tax_name`/`tax_rate` copied on, `surcharge` (= "offer card payment") | **No stored totals**: subtotal, tax, total, balance are computed from the items. Hard deleted (refused once paid). `company_id` cascades on delete. |
| `invoice_items` | `description`, `details`, `amount`, `taxable`, `position`, nullable `service_id` | One lump amount per line, no qty/rate. `expense` (hasOne via `expenses.invoice_item_id`) and `timeEntries` hang off it. |
| `payments` | `invoice_id` (cascade), `method` (card/ach/check/other), `amount` (= invoice total incl. tax), `surcharge_amount` (3% the client paid on card), `stripe_payment_intent_id`, `paid_at` | No edit or delete endpoint. Partial payments are possible in the schema (`remainingBalance()`), but nothing creates one: `Invoice::recordPayment()` (`Invoice.php:391-420`) always records the full total and marks the invoice paid, and it isn't wrapped in a DB transaction. |
| `transactions` | Income only (`type='income'`): `amount`, `tax_amount`, `taxable_amount`, free-text `category`, `occurred_on`, nullable `invoice_id` (nullOnDelete), `project_id` | Two sources: `recordPayment` writes one per invoice payment (`category='client invoice'`, `occurred_on=now()`, base amount, surcharge left out), and the Bookkeeping "Add income" API writes manual ones (UI currently commented out). Hard deletable via the API; no update. The model's own comment says it's "not double-entry accounting". |

Stripe: Checkout sessions add a separate "Card processing fee (3%)" line for
card, none for ACH. Cashier's webhook fires `WebhookReceived`, handled by
`app/Listeners/MarkInvoicePaidFromStripeWebhook.php` (checkout completed /
async succeeded), which calls `recordPayment`. **Stripe's actual fee, the net,
balance transactions and payouts aren't stored or fetched anywhere.** The 3%
surcharge the client paid is on `payments`, but no report counts it as income.

### Expenses

`expenses`: `name`, `amount`, `currency`, `category_id`, `project_id`,
`is_billable`, `markup_percent`, `tax_id`, `date`, receipt fields,
`source_label` (free text, e.g. "Chase ••4521"), `plaid_transaction_id`
(unique), `billing_status` (unbilled / billed / billed_and_paid),
`invoice_id`, `invoice_item_id`. Editable and deletable only while unbilled;
hard deleted. A `saving` hook requires a project when billable.

`expense_splits` (`expense_id`, `company_id`, `amount`) allocate a shared
cost (hosting) across clients; they must sum to the expense and are never
billed.

**Billable expenses:** cost is `expenses.amount`; the billed price isn't on
the expense. It's the linked `invoice_items.amount`, computed as
cost × (1 + markup) when attached via `Expense::attachToInvoice`, or
whatever the client sends when a line carries an `expense_id` on invoice
create/update (`InvoiceController.php:193-210`; not checked server-side).
The link (`invoice_item_id`) and the "unbilled" state already exist.

### Expense categories, Bonsai, Plaid

- `expense_categories` (`name`, `color`) is a table that users can edit
  (add/delete in the Expenses page's category drawer). The rows come from
  migrations, not a seeder: **Software & Subscriptions, Equipment, Travel,
  Office Supplies, Contractors, Advertising, Hosting**. That's not the Bonsai
  tag list in the brief (see conflict C1). No Bonsai mapping exists in the
  code. Some reports match a category by name (`HostingProfitabilityReport`
  looks for the literal "Hosting").
- `invoice_categories` (unique `name`, seeded "Hosting", "Project work" is
  the virtual null bucket) groups income in the P&L.
- `services` (`name`, `default_rate`, `unit` hourly/fixed, `billable`) is
  user-managed; there's no revenue taxonomy (design / dev / ad management /
  media / hosting) anywhere.
- **Plaid exists, Sandbox only in practice** (`PLAID_ENV=sandbox`; the code
  supports production). `PlaidSync` turns card/bank charges straight into
  `expenses` rows, keyed by `plaid_transaction_id`, and skips money in,
  pending, transfers and loan payments. It also updates and deletes
  *unbilled* imported expenses when Plaid modifies/removes the transaction.
  Account identity is only a JSON `accounts` blob on `plaid_items` (name +
  mask, no type); expenses keep a display label, not the account. Bank
  feeds are out of scope here, but Plaid-created expenses will flow through
  the same expense posting.

### Reports today

All take `?year=` (no date ranges). Cards, chart, P&L and sales tax are cash
basis off `transactions.occurred_on` and `expenses.date`; the invoice-category
and hosting reports go by `issued_on`. CSVs stream via
`response()->streamDownload` + `fputcsv`; the helper is
`BookkeepingPageController::csv()` (line 189). Report services are static
(`forYear()` + `csvRows()`), returning plain arrays.

### Hooks and tenancy

No `app/Observers` or `app/Events`. The only listener is the Stripe one. The
`booted()` hooks are Invoice (number/token), Expense (billable needs a
project), Proposal (token). The app is single-tenant (no studio/team
scoping), and money tables have no created-by columns.

### Name collisions

None. No `accounts` table, no `Account` model, nothing called ledger,
journal, period or reconciliation. In the UI, though, "Account" already
means the user's login (Profile › Account, `AccountSettingsTest`), so UI
copy should say "Ledger accounts" / "Chart of accounts".

### Frontend and conventions

- Inertia 3 + React 19 + Vite 8, Sass with BEM (`resources/scss`), **no
  Tailwind** (README still says Tailwind 4; it's stale). `STYLES.md` is the
  authority, and `/style-guide` is the live one. Role-named CSS variables from
  `base/_tokens.scss` (`--color-text-muted`, `--space-panel`…), dark default
  plus light theme via tokens only, three `u-*` utilities and no more, one
  BEM block per partial `@forward`ed from its folder index, no `@import`,
  no magic numbers without a comment.
- Pages: `Web/*PageController` → `Pages/<Feature>/*.jsx` for GET. JSON
  writes via `routes/api.php` controllers and `lib/api.js`. Forms are
  `useState` + `api.post/patch` (not Inertia `useForm`), records are created
  and edited in a `Drawer`, lists are `card card--flush` + `table` with
  `table__cell--numeric`, `EmptyState`, `TabToolbar`, `PageHeader`,
  `MetricCard`, `CurrencyInput` for amounts, `formatCurrency`.
- Backend: inline `$request->validate()` (no FormRequests),
  `Validator::after()` for cross-field rules, `abort(422, '…')` for business
  rules, `DB::transaction` for multi-row writes. Write services are instance
  classes with constructor injection (`PlaidSync`).
- Permissions: `config/permissions.php`; `bookkeeping` (reports/exports),
  `expenses`, `invoices`, `settings` (super admin only); `permission:`
  middleware on route groups.
- Tests: **PHPUnit 12** (not Pest), SQLite `:memory:`, `RefreshDatabase`,
  only a `UserFactory`. Other models are built inline with `::create()` in
  private `setUp…()` helpers. Long snake_case test names.
- DB: SQLite locally and in tests. `foreign_key_constraints` on, no CHECK
  constraints anywhere yet. Laravel 13.31's schema builder has no
  `check()` method, so the CHECK is raw SQL per driver (Q1).
- Commits: short sentence-case subject, optional prose body.
- No `docs/` folder before this file.

---

## 2. Conflicts with the design, and how I'd resolve them

**C1. The expense categories aren't the Bonsai list.** The migrations seed
seven generic categories. The brief's 22 Bonsai tags aren't in code;
production may have more rows than local. Proposal: seed the 22 as
*expense accounts* (codes marked placeholder), add
`expense_categories.account_id`, and map each existing category to an
account (seeded guesses, editable). Categories stay the friendly picker;
the account is what posts. An expense whose category has no account posts
to an **Uncategorized Expense** account and gets flagged. See Q3.

**C2. Client media vs our own advertising share one category.** "Advertising"
is used for both. Proposal: categories get an optional second account,
`billable_account_id`, used when the expense is billable. So Advertising
posts to *Advertising & Marketing* normally and to *Client Media Spend*
(cost of revenue) when billable. Hosting maps to *Hosting Cost* either way.

**C3. The Stripe fee isn't known when a payment arrives.** The design debits
`stripe_clearing` for the net and fees for the fee at payment time, but the
app never learns the fee, and the Stripe API is out of scope. Proposal: at
payment, debit `stripe_clearing` for the **gross** the client paid
(amount + surcharge). The fee is recognized when the **payout** is entered
(Phase 4 form: date, gross cleared, net deposited): debit checking (net),
debit Payment Processing Fees (the difference), credit `stripe_clearing`
(gross). Clearing still nets to zero per payout, and a later Stripe
integration can post the same payout entry automatically. See Q4.

**C4. Sales tax is missing from the design.** Invoices collect sales tax
(`tax_rate`, `taxable` lines, `SalesTaxReport`), and it isn't revenue.
Proposal: a `sales_tax_payable` liability. A payment credits it for the
tax portion; remitting to the state is a manual entry (debit payable,
credit checking).

**C5. Revenue by service line has nothing to key off.** Lines only carry an
optional service and the invoice an optional category. Proposal, a
resolution order per invoice line:
1. Line rebills an expense → the revenue account paired with the expense's
   cost account (Client Media Spend → Client Media revenue, Hosting Cost →
   Hosting revenue).
2. Line has a service → `services.revenue_account_id` (e.g. "Ad management"
   → Ad Management revenue).
3. Invoice has a category → `invoice_categories.revenue_account_id`
   (Hosting → Hosting revenue).
4. Otherwise → Design & Development Services.

On cash basis a payment is split across the invoice's lines pro rata (tax to
payable, surcharge to `surcharge_income`), with largest-remainder rounding so
cents always balance. Today every payment is the full total, so it's just
the lines.

**C6. "Store cost and billed price" is half there already.** The cost is
`expenses.amount`, the link is `expenses.invoice_item_id`, and the billed
price is that line's `amount`. Unbilled billable expenses =
`is_billable AND invoice_item_id IS NULL`. I'd **not** add a duplicate
`billed_amount` column. The invoice line is the source of truth, and the
markup gives the expected price before billing. Separately, the create/update
path should validate an expense line's amount, or at least post whatever the
line says (it already does). See Q5.

**C7. Cascade deletes bypass model events.** Deleting a company cascades to its
invoices and payments in the database, so no observer runs and no
reversal is posted (and today it orphans their income transactions).
Proposal: refuse to delete a company (and an invoice, already refused once
paid) that has posted ledger entries. `journal_entries.source_*` is a plain
morph with no FK, so history survives regardless.

**C8. Edits arrive in pieces.** An expense is saved, *then* its splits are
replaced (same controller transaction); Plaid sync edits expenses in a loop.
A naive `saved` observer would post twice or post the pre-split state.
Proposal: posting is **idempotent and diff-based**. Each poster computes
the entry a source *should* have; if it matches the source's current live
entry, nothing happens; otherwise it reverses the live entry and posts the
new one. Observers run after commit (`ShouldHandleEventsAfterCommit`), and
the controller calls the poster once more after the splits are saved. That
way edits that don't touch money (a receipt upload, a renamed expense)
post nothing.

**C9. Locked periods vs editable records.** The Ledger rejects entries dated in
a locked period, so editing or deleting an expense/income dated in one
(which would need a reversal there) must be refused up front with a clear
422, not fail inside the poster. Plaid sync skips and logs such changes.

**C10. Manual "other income" has no deposit account.** Proposal:
`transactions.deposit_account_id` (default checking), credited to Other
Income. Invoice-payment `transactions` rows are **not** posted (the Payment
is the source), so income isn't counted twice.

**C11. Payment dates.** `recordPayment` dates income `now()`. The ledger dates a
payment's entry by `payments.paid_at`. I'd also wrap `recordPayment` in a
transaction so the payment and its entry commit together.

**C12. Payroll overlaps Bonsai tags.** Wages & Commissions, Payroll Taxes and
Retirement Expense are both Bonsai tags (expense categories) and payroll
form lines. The payroll form posts straight to Officer Compensation /
Wages / Payroll Tax Expense / Retirement Match. Payroll shouldn't also be
entered as expenses, or it's counted twice. The backfill will flag expenses
in those categories. Withholding liabilities aren't modeled: crediting
checking for the full amount is right if the payroll provider pulls
gross + employer costs from checking (Q8).

**C13. Opening balances.** Nothing records the card or checking balances, or the
monthly card payments, today. After a backfill, checking and the card won't
match the bank until opening balances are entered (against Opening Balance
Equity) and past card payments are entered as transfers (Q6).

---

## 3. Proposed chart of accounts (placeholder codes)

Codes are placeholders (`is_placeholder_code` noted in the seeder) until the
CPA's chart arrives. `parent_id` groups them for reports. Cost of revenue is
a parent under expenses, so the P&L can show gross profit without a sixth
type.

- **1000 Assets**: 1010 Checking – Waterford Bank (`checking`), 1050 Stripe
  Clearing (`stripe_clearing`)
- **2000 Liabilities**: 2010 Capital One Card (`capital_one_card`), 2200
  Sales Tax Payable (`sales_tax_payable`)
- **3000 Equity**: 3010 Owner's Capital, 3020 Owner's Draws, 3900 Retained
  Earnings, 3950 Opening Balance Equity (`opening_balance_equity`)
- **4000 Income**: 4010 Design & Development Services (`service_revenue`),
  4020 Ad Management, 4030 Client Media, 4040 Hosting, 4090 Card Surcharge
  Income (`surcharge_income`), 4900 Other Income (`other_income`)
- **5000 Cost of Revenue**: 5010 Client Media Spend, 5020 Hosting Cost,
  5030 Client Software
- **6000 Operating Expenses**: Advertising & Marketing, Work Devices &
  Software, Officer Compensation, Wages & Commissions, Payroll Taxes,
  Retirement Match, Business Insurance, Health & Life Insurance, Rent &
  Lease Property, Utilities, Internet, Telephone, Mobile Phone, Business
  Meals, Charitable Donations, Local Taxes, Accounting Fees, Payment
  Processing Fees (`merchant_fees`), Payroll Processing Fees, HSA Fees,
  Other Office Expenses, Uncategorized Expense (`uncategorized_expense`)

(Charitable donations and owner draws may belong elsewhere for an S corp /
sole prop; the CPA's call.)

---

## 4. Phases

Each phase is its own small commits, `composer test` before each, and a stop
for review.

### Phase 1: schema, models, Ledger service
- Migrations: `accounts`, `journal_entries`, `journal_lines`,
  `accounting_periods`, `bank_reconciliations`, per the design. Plus:
  `journal_entries.entry_number` unique and sequential (assigned inside the
  posting transaction); `created_by` nullable (webhooks, Plaid and the
  backfill have no user); indexes on `(source_type, source_id)`,
  `entry_date`, `journal_lines.account_id`.
- The CHECK on `journal_lines` (exactly one of `debit_cents`/`credit_cents`,
  greater than zero) as raw SQL in the migration, branching on the driver
  (SQLite needs it at `CREATE TABLE`; MySQL 8 needs a table-level
  constraint). A test proves the database itself rejects a bad line.
- Models: `Account`, `JournalEntry`, `JournalLine`, `AccountingPeriod`,
  `BankReconciliation`. Updates and deletes on posted entries/lines throw
  at the model level, and accounts can't be deleted, only deactivated.
- `App\Support\Money` (decimal string ↔ cents).
- `App\Services\Ledger`: the only way to post. `post(EntryDraft)` and
  `reverse(JournalEntry, date)`, each inside `DB::transaction`. It rejects
  unbalanced entries, an empty entry, a zero line, inactive accounts and dates
  in a locked period. A reversal is a mirror entry with `reverses_entry_id`;
  an entry can be reversed only once. `liveEntryFor($source)` returns the
  unreversed entry. Plus `lockPeriod()`/`unlockPeriod()`.
- Tests: balancing, immutability, reversals (incl. double reversal),
  period locking, the CHECK constraint, entry numbering.

### Phase 2: chart of accounts
- An idempotent `ChartOfAccountsSeeder` keyed by `system_key`/`code`
  (creates what's missing, never edits or deletes). Called from a migration
  so Forge's deploy `migrate` creates the chart, matching how categories are
  seeded today (Q2).
- Migration adding `expense_categories.account_id` + `billable_account_id`,
  `services.revenue_account_id`, `invoice_categories.revenue_account_id`,
  with seeded guesses for the existing rows (Hosting → Hosting Cost /
  Hosting revenue, Advertising → Advertising & Marketing / Client Media
  Spend, Software & Subscriptions → Work Devices & Software, etc.).
- A read-only Chart of accounts page under Bookkeeping, plus account
  pickers on the category and service drawers.

### Phase 3: automatic posting
- `expenses.paid_from_account_id` (default `capital_one_card`), plus a "Paid
  from" select in the expense drawer (asset/liability accounts only).
- `transactions.deposit_account_id` (C10).
- Posters (instance services): `ExpensePoster` (debit category/billable
  account, one line per split with `company_id`; credit paid-from),
  `PaymentPoster` (C3/C4/C5 split; card/ACH → `stripe_clearing`,
  check/other → checking), `IncomePoster` (manual income only).
- After-commit observers on Expense, Payment, Transaction call the posters,
  diff-based (C8). Deletes reverse. Locked-period guards (C9). Guard
  company deletion (C7). `recordPayment` in a transaction, dated
  `paid_at` (C11).
- An "Unbilled billable expenses" view (or filter on Expenses).
- Tests for each posting rule, edits that do and don't repost, deletes,
  splits, Plaid-imported expenses, locked periods.

### Phase 4: manual entry screens
Bookkeeping › Ledger, following the drawer convention:
- Journal: list of entries (number, date, memo, source link, amount),
  entry drawer with its lines, "Reverse" action.
- New general entry drawer: balanced line editor (account, debit, credit,
  client, description) with a live out-of-balance total.
- Transfer drawer (card payment: from checking to Capital One; also
  Stripe payout per C3, and owner contributions/draws).
- Payroll drawer: pay date, period, Officer Compensation, Wages, Payroll
  Tax, Retirement Match, total credited to checking.
- Accounts: add, rename, deactivate. Periods: lock/unlock (super admin).
- Manual entries have no source, so they're corrected by reversal, never
  edited.

### Phase 5: reports and CSV
All with `from`/`to` date ranges (new for this app; quick picks for month,
quarter, year), CSV via the existing `csv()` helper, static report services
like the current ones:
- General ledger by account with opening balance and running balance.
- Trial balance (debits = credits, shown).
- Profit & loss (income, cost of revenue, gross profit, expenses, net).
- Balance sheet as of a date (with current-period earnings rolled into
  equity).
The existing year-based reports stay as they are for now (Q7).

### Phase 6: backfill
`php artisan ledger:backfill {--dry-run} {--from=YYYY-MM-DD}`, local or
explicitly run by you only. It posts through the same posters in date order
and skips sources that already have a live entry, so re-running is safe.
`--dry-run` writes nothing and reports problems: expenses with no category
or an unmapped category, payroll-category expenses (C12), billable expenses
never billed, income transactions orphaned from deleted invoices, payments
whose amount ≠ the invoice total, Plaid Sandbox expenses, and records in
locked periods.

---

## 5. Questions for you

1. **What database does production run?** SQLite or MySQL (Forge)? It
   decides how the CHECK constraint is written, and I want the migration
   tested on that engine before deploy.
2. **Seed the chart from a migration** (runs on deploy, like the categories),
   or a seeder you run by hand? I'd use a migration, idempotent.
3. **Production expense categories:** are the Bonsai tags already rows in
   production's `expense_categories`, or only the seven from the
   migrations? If you can share the production list (names only), I'll
   seed the category→account mapping to match. Otherwise I'll map the seven
   and leave the rest to the picker.
4. **Stripe fee at payout (C3):** OK to post gross to clearing and record the
   fee when you enter each payout?
5. **Billed price (C6):** OK to treat the invoice line as the billed price
   rather than adding a column? Should the server enforce cost × markup on
   expense lines, or keep them editable?
6. **Backfill start date and opening balances (C13):** from what date? Do you
   want an opening-balance form (checking, card, Stripe clearing as of the
   start date), and will you enter past card payments as transfers?
7. **Existing reports:** keep the current year-based P&L and cards next to the
   ledger ones, or switch them to the ledger once it's trusted? They'll
   differ (surcharge income, payroll, fees, sales tax).
8. **Payroll:** does the provider debit checking for the gross plus employer
   tax and match (so crediting checking for the total is right), or should
   withholdings go to a payable?
9. **Permissions:** viewing the ledger and making manual entries under the
   existing `bookkeeping` permission, period locking super admin only? Or a
   new `ledger` permission?
10. **Payments by check/other:** straight to checking, or through an
    Undeposited Funds account?
11. **Card surcharge:** the 3% fee is credited to Card Surcharge Income as
    designed, right? Your current reports don't count it as income at all.
