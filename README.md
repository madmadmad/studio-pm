# Studio PM

A Laravel API for firm/studio operations: companies, contacts, projects,
tasks, invoices, proposals, time tracking, and basic bookkeeping.

## Local setup

```
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Set `STRIPE_KEY` / `STRIPE_SECRET` in `.env` for the invoicing/surcharge
flow. Auth isn't scaffolded (see below), so `routes/api.php` is protected by
`auth:sanctum` but there's no login/registration flow yet — install
[Breeze](https://laravel.com/docs/starter-kits) or
[Fortify](https://laravel.com/docs/fortify), or issue Sanctum tokens
manually, before exercising the protected routes.

## What's here

- **database/migrations/** -- all 11 domain tables: companies, contacts,
  services, projects, tasks, invoices, invoice_items, time_entries,
  proposals, payments, transactions -- plus the framework, Sanctum, and
  Cashier migrations from the base Laravel install.
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
- **app/Http/Controllers/StripeWebhookController.php** -- stub only; not yet
  wired to Cashier's webhook controller or routed. Needed before
  `markPaid()` can be triggered automatically from a Stripe webhook instead
  of manually.

## Deliberately not included

- Auth scaffolding itself (login, registration) -- use Laravel Breeze or
  Fortify for that; the routes here just assume it exists.
- Contracts/e-signature -- explicitly out of scope.
- Client portal -- deferred; the proposal accept flow above is the one
  piece of client-facing surface that exists right now.
- Frontend -- this is API-only.

## Deploying to Laravel Cloud

1. Push this repo to GitHub, then connect it in the
   [Laravel Cloud dashboard](https://cloud.laravel.com) -- New Application
   -> select this repo/branch.
2. Laravel Cloud auto-provisions a database; attach it and it will inject
   `DB_*` env vars automatically. Do the same for a KV Store (Redis) if you
   want `CACHE_STORE`/`SESSION_DRIVER`/`QUEUE_CONNECTION` on Redis instead
   of the default `database` driver.
3. In the environment's **Variables** settings, add: `STRIPE_KEY`,
   `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, and any mail credentials.
   `APP_KEY` is generated automatically on first deploy if left blank.
4. In **Deployments** settings, set:
   - Build command: `composer install --no-dev && php artisan config:cache && php artisan route:cache`
   - Deploy command: `php artisan migrate --force`
5. The environment's filesystem is ephemeral -- don't rely on `storage:link`
   or local disk for anything that must persist. This app doesn't currently
   store user-uploaded files, so no object storage is needed yet; if that
   changes, use Laravel Cloud's Object Storage resource and set
   `FILESYSTEM_DISK=s3` (or the Cloud-provided disk) instead of `local`.
   Sessions/cache/queue already default to the `database` driver, which is
   fine on Cloud's managed database.
6. Push to the connected branch (push-to-deploy is on by default) to
   trigger the first deploy.
