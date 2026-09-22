# Studio PM

Studio PM is a firm-operations app for small studios and agencies: it
brings client management, project/task tracking, time tracking, proposals,
invoicing (with Stripe checkout), expenses, and basic bookkeeping into one
place, with a lightweight client-facing hub layered on top.

It's a Laravel + Inertia (React) monolith — one codebase, one deploy,
server-rendered routing with a React frontend, no separate API client to
maintain.

## Who it's for

Two kinds of users, on two separate auth guards:

- **Staff** (`manager` / `team_member` roles) — sign in normally and use
  the main app: clients, projects, tasks, time tracking, timesheets,
  proposals, invoices, expenses, bookkeeping, services, and team
  management. Firm-wide financials and the client directory are
  manager-only; project/task/time-tracking surfaces are shared, scoped per
  user by the policies.
- **Clients** — get a separate "Client Hub" (`/portal`), reached via a
  passwordless magic-link sign-in tied to their contact record. From there
  they can view their project(s), leave messages, and manage tasks. Outside
  the portal, clients also get one-off public links for proposals (`/p/{token}`,
  accept/decline without an account) and invoices (`/i/{token}`, view + pay
  via Stripe Checkout).

## Stack

- **Backend:** Laravel 13 (PHP ^8.3), Inertia (server adapter), Fortify
  (auth, 2FA, passkeys), Sanctum (SPA/API session), Cashier (Stripe billing
  for invoice checkout), dompdf (invoice PDFs), Postmark (transactional
  mail).
- **Frontend:** React 19 via Inertia, Tailwind CSS 4, Tiptap (rich text),
  Phosphor icons, Vite.
- **Database:** SQLite by default locally; MySQL in production (Forge).

## Local development (Valet)

This project is developed locally with [Laravel
Valet](https://laravel.com/docs/valet).

```bash
git clone <repo-url> studio-pm
cd studio-pm

composer install
npm install

cp .env.example .env
php artisan key:generate

touch database/database.sqlite   # only needed if DB_CONNECTION=sqlite
php artisan migrate

valet link studio-pm             # or `valet park` from the parent folder
valet secure studio-pm           # optional, for HTTPS locally
```

Set `APP_URL` in `.env` to match the Valet domain (e.g.
`http://studio-pm.test` or `https://studio-pm.test` if secured), and set
`VITE_APP_NAME` / mail / Stripe keys as needed — see `.env.example` for the
full list.

Valet serves PHP through `public/index.php` automatically, so there's no
`php artisan serve` process to run. You only need to run the asset
pipeline:

```bash
npm run dev
```

This starts Vite in watch mode; Valet + Vite together give you a working
dev environment at `https://studio-pm.test` with hot module reloading.

To seed some data to work with, run:

```bash
php artisan migrate:fresh --seed
```

### Client Hub / portal locally

Magic-link emails and Stripe checkout links point at `APP_URL`, so as long
as that's set to your Valet domain, portal invites and public invoice/
proposal links will resolve correctly in the browser. With `MAIL_MAILER=log`
(the default), magic-link and invite emails are written to
`storage/logs/laravel.log` instead of actually sending — check there to
grab the links while developing.

### Running tests

```bash
php artisan test
```

## Deploying (Laravel Forge)

This app deploys to [Laravel Forge](https://forge.laravel.com).

1. Create a new site in Forge pointed at this repository/branch, with PHP
   8.3+ and MySQL.
2. Set the environment variables in Forge's **Environment** tab (copy from
   `.env.example` as a starting point) — in particular `APP_KEY` (or let it
   generate on first deploy), `APP_URL`, `DB_*`, mail credentials
   (Postmark), and `STRIPE_KEY` / `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET`
   for invoice checkout.
3. Forge's default Laravel deploy script covers most of this; make sure
   the deploy script includes:
   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci
   npm run build
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
4. Point the site's queue worker (Forge → **Queue**) at the `database`
   queue connection so invite/notification/mail jobs process in the
   background.
5. Enable Forge's SSL (Let's Encrypt) for the site domain — proposal/invoice
   public links and Stripe redirect back to `APP_URL`, so it should be the
   final HTTPS domain.
6. Push to the connected branch to trigger a deploy (or deploy manually
   from the Forge dashboard).

## Project structure

- `app/Models/` — Eloquent models: `Company`/`Contact` (clients),
  `Project`/`Task`/`Subtask`, `TimeEntry`, `Proposal`/`ProposalItem`,
  `Invoice`/`InvoiceItem`, `Expense`/`ExpenseCategory`/`Tax`,
  `Transaction`/`Payment` (bookkeeping), `Message`/`MessageParticipant`,
  `StudioProfile`, `User`.
- `app/Http/Controllers/` — JSON API controllers backing the app's own
  frontend (mounted under `routes/api.php`, `auth:sanctum`), plus
  `Web/*PageController` classes that render the Inertia pages
  (`routes/web.php`), plus `Portal/*` controllers for Client Hub mutations
  (`auth:client` guard).
- `app/Notifications/`, `app/Services/` — Stripe checkout integration,
  proposal/invoice notification emails.
- `resources/js/Pages/` — Inertia page components, organized by feature
  (Clients, Projects, Time, Invoices, Proposals, Bookkeeping, Expenses,
  Services, Users, Settings, Portal, Public, Auth).
- `database/migrations/` — full schema history; see this for the exact
  shape of every table.
- `tests/Feature/` — the bulk of test coverage, organized by feature area.

## Notes

- Roles are `manager` and `team_member` (`App\Models\User::ROLE_*`),
  enforced via the `role:` route middleware
  (`App\Http\Middleware\EnsureUserHasRole`) and per-model policies.
- The Client Hub uses a completely separate `client` auth guard from staff
  auth — see `App\Http\Controllers\Web\PortalAuthController` and
  `app/Models/Contact.php` / `ContactMagicLink.php`.
- Invoice payment happens through Stripe Checkout (Cashier); marking an
  invoice paid writes a matching row to `transactions` so the bookkeeping
  summary stays in sync automatically.
