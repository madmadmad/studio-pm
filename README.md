# Firm workspace -- Laravel scaffold

This is a starting scaffold, not a runnable app on its own. It's meant to be
dropped into a fresh Laravel install.

## How to use this

1. `laravel new firm-workspace` (or `composer create-project laravel/laravel firm-workspace`)
   on your own machine, where Composer can reach Packagist.
2. Copy this scaffold's folders into the new project, merging into the
   existing `app/`, `database/`, and `routes/` directories.
3. `composer require laravel/cashier` -- needed for the Stripe integration
   the invoicing/surcharge flow assumes.
4. Set your Stripe test keys in `.env` (`STRIPE_KEY`, `STRIPE_SECRET`).
5. `php artisan migrate` to create all eleven tables.
6. Wire up auth (`php artisan install:api` gives you Sanctum) since the
   protected routes in `routes/api.php` assume `auth:sanctum`.

## What's here

- **database/migrations/** -- all 11 tables: companies, contacts, services,
  projects, tasks, invoices, invoice_items, time_entries, proposals,
  payments, transactions.
- **app/Models/** -- Eloquent models with the relationships wired up.
- **app/Http/Controllers/** -- CRUD controllers for each resource, plus:
  - `ProposalController` -- includes the public, token-based `showPublic()`
    and `accept()` endpoints for the client-facing accept flow (no login,
    much smaller than a full client portal).
  - `InvoiceController::markPaid()` -- marks an invoice paid and writes a
    matching income row to `transactions`, so bookkeeping updates itself.
  - `TransactionController::summary()` -- the simple income/expense
    "bookkeeping" view, not double-entry accounting.
  - `TimeEntryController` -- `index()`/`store()` back Time Tracking;
    `weekly()` backs Timesheets by grouping the same rows by week.
- **app/Services/StripeCheckoutService.php** -- where the surcharge toggle
  actually takes effect, via Stripe's `automatic_surcharge` Checkout Session
  parameter. That parameter is on a preview API version as of writing --
  check your Stripe dashboard/API version before relying on it in production.
- **app/Notifications/ProposalAccepted.php** -- fires when a client accepts
  a proposal.

## Deliberately not included

- Auth scaffolding itself (login, registration) -- use Laravel Breeze or
  Fortify for that; the routes here just assume it exists.
- Contracts/e-signature -- explicitly out of scope.
- Client portal -- deferred; the proposal accept flow above is the one
  piece of client-facing surface that exists right now.
- Frontend -- this is API-only. Pair it with the interactive prototype
  from earlier in this conversation as a starting point for the UI, or
  build views/Inertia pages against these same routes.
