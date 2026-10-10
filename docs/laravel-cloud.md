# Laravel Cloud

studio-pm runs on [Laravel Cloud](https://cloud.laravel.com) at **madmadmad.studio**. It moved from the Forge site at studio-pm.madhouse.dev as a fresh install: no data or files came across.

Laravel Cloud's servers keep nothing on disk between deploys. So the database is Cloud's MySQL, every upload goes to a Cloud bucket, and logs go to Cloud's log viewer. The code is ready for that: there's nothing to change in it, only the settings below.

Dashboard wording may differ slightly from what's written here.

## 1. Before you start

- **Stripe webhook.** The app refuses to start in production without `STRIPE_WEBHOOK_SECRET`. In Stripe → Developers → Webhooks, add an endpoint:
  - URL: `https://madmadmad.studio/stripe/webhook`
  - Events: `checkout.session.completed` and `checkout.session.async_payment_succeeded`

  Copy its signing secret (`whsec_...`) for step 6. Stripe doesn't need the domain to be live yet.
- **Postmark.** Emails go out through Postmark. Make sure the address you send from (`MAIL_FROM_ADDRESS`) is a verified sender signature, or its domain is verified with DKIM and Return-Path, in Postmark → Sender Signatures. Proposal, invoice and invite emails won't send otherwise.
- **GitHub.** Cloud deploys from the repository. Connect your GitHub account to Cloud if you haven't already.

## 2. Create the application

1. Cloud → **New application** → pick the `studio-pm` repository and the **`main`** branch. Name it `studio-pm` and choose a region near you (e.g. US East).
2. Cloud creates a **production** environment. In its settings:
   - **PHP 8.4** or newer. The app needs 8.4.
   - **Node 22** or newer, for the asset build.
   - **Hibernation off.** The scheduler sends invoice reminders and syncs the bank feed, and Stripe's webhooks have to reach a running app.

## 3. Database

Environment → **Add resource** → **Database** → a new **Laravel MySQL** cluster (MySQL 8). Attach it to the production environment. Cloud fills in the `DB_*` settings itself.

## 4. Storage: two buckets

Environment → **Add resource** → **Object storage**:

| Bucket | Visibility | Disk name | Holds |
|---|---|---|---|
| `studio-pm-private` | **Private** | `private` | message and Chat attachments, avatars, bio photos, receipts, task files |
| `studio-pm-public` | **Public** | `public-assets` | the studio's logos (the header, emails, PDFs) |

The disk name is what the app refers to (step 6). Cloud sets each one up as a storage disk under that name. Private files are only ever handed out through the app, which checks who's asking and then redirects to a short-lived signed link. Never make the private bucket public.

## 5. Background processes

- **Scheduler:** turn it on (environment → App compute → Scheduler). It runs `schedule:run` every minute, for repeating invoices, scheduled sends, reminders and the Plaid sync.
- **Queue worker:** add a background process (environment → App compute → Background processes):
  ```
  php artisan queue:work --tries=3 --timeout=120
  ```
  It sends emails and makes image thumbnails. The queue uses the database, so nothing else is needed.

## 6. Environment variables

Environment → **Settings** → **Environment variables**. Cloud adds `DB_*`, the bucket disks, and its own variables. Add these:

```env
APP_NAME="Studio PM"
APP_ENV=production
APP_DEBUG=false
APP_KEY=                     # see below
APP_URL=https://<your-env>.laravel.cloud    # change to https://madmadmad.studio in step 9
APP_TIMEZONE=America/New_York

FILESYSTEM_PRIVATE_DISK=private
FILESYSTEM_PUBLIC_DISK=public-assets

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=postmark
POSTMARK_API_KEY=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="Madhouse Studio"

STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=       # from step 1

PLAID_CLIENT_ID=
PLAID_SECRET=
PLAID_ENV=sandbox

GIPHY_API_KEY=               # Chat's GIFs; once Chat is merged
```

- **`APP_KEY`:** make a new one for this install with `php artisan key:generate --show` on your Mac, and paste the output (`base64:...`). Keep it: changing it later signs everyone out and makes stored two-factor secrets unreadable.
- **`APP_TIMEZONE`:** the scheduler times (reminders at 9 AM, and so on) are New York time either way. This only sets how the app reads plain dates.

## 7. Deploy

Cloud's default commands already fit:

- **Build:** `composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build`
- **Deploy:** `php artisan migrate --force`

Click **Deploy**. Migrations create every table, and the chart of accounts is seeded by its migration.

## 8. First sign-in

Environment → **Commands**:

```bash
php artisan app:create-super-admin you@madmadmad.studio "Bill Sattler"
```

This prints a password once. Sign in at the `*.laravel.cloud` address with it, then change it under Profile. Invite everyone else from Team.

Then check:

- [ ] Sign in, sign out, password reset email
- [ ] Settings → upload a logo. It shows in the header, then in an invoice PDF.
- [ ] A client, a project, a task with a file; download the file
- [ ] A project message with an image (its thumbnail appears once the queue worker has run)
- [ ] An invoice: PDF, send it to yourself, the public link, Stripe test checkout (marks it paid, via the webhook)
- [ ] Client Hub: invite yourself as a contact, the magic link, see the project
- [ ] Logs (environment → Logs) show no errors

## 9. The domain

1. Environment → **Domains** → add `madmadmad.studio`, and `www.madmadmad.studio` redirecting to it if you want that.
2. At your domain registrar, add the DNS records Cloud shows you. Cloud issues the SSL certificate once they resolve.
3. Change `APP_URL` to `https://madmadmad.studio` and redeploy. Links in emails, the public invoice and proposal pages, and Stripe's return URLs all come from it.
4. Repeat the sign-in and invoice checks on the real domain.

## 10. Retire Forge

When Cloud's been fine for a few days, delete the site at studio-pm.madhouse.dev in Forge, and its server if nothing else is on it. Nothing there needs keeping.

## Later: Chat

Chat (the `chat` branch) needs a WebSocket cluster. Its setup is in `docs/chat.md` on that branch:

1. Add a **WebSockets** resource (Reverb) and attach it. Cloud fills in the `REVERB_*` and `VITE_REVERB_*` settings.
2. Merge `chat` into `main`. The deploy runs Chat's migrations, and its build picks up the `VITE_REVERB_*` settings.
3. `php artisan db:seed --class=ChatSeeder --force` in Commands, to create #general.
