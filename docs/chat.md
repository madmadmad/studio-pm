# Chat

Chat is the studio's internal messaging: channels anyone on staff can join, and direct messages between two or more people. It is staff only. Clients use project Messages in the Client Hub, which are separate and unaffected by Chat.

Live updates (new messages, edits, reactions, typing, who's online, unread badges) run over WebSockets through [Laravel Reverb](https://laravel.com/docs/reverb). The database is always the record. A broadcast only tells open tabs that something changed, and tabs fetch anything they missed when they reconnect or come back into view. If the socket is down, Chat still works: it re-checks every 15 seconds and on focus.

## What you need to configure

The code is the same on every environment. Only these settings differ.

### Reverb (WebSockets)

| Variable | What it is |
|---|---|
| `BROADCAST_CONNECTION` | `reverb` |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | The app's Reverb credentials. `php artisan reverb:install` generates a set. Keep the secret private. |
| `REVERB_HOST` | The hostname browsers connect to, e.g. `staging.example.com` |
| `REVERB_PORT` | The port browsers connect to: `443` behind TLS, `8080` locally |
| `REVERB_SCHEME` | `https` behind TLS, `http` locally |
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT` | Where the Reverb process itself listens. Defaults: `0.0.0.0` and `8080`. Only matters on Forge. |
| `REVERB_ALLOWED_ORIGINS` | Optional. Comma-separated hosts allowed to open a socket, e.g. `staging.example.com`. Defaults to any. Every channel requires a signed-in staff member either way. |
| `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | The browser's copy of the values above. `.env.example` points them at the `REVERB_*` values. |

**The `VITE_REVERB_*` values are compiled into the JavaScript at build time.** After setting or changing them, run `npm run build` again (on Forge, redeploy). Without them, Chat still works but nothing is live.

### Attachments (S3 or Cloudflare R2)

Chat files go on a private disk and are only ever handed out through an authorized controller. On S3 or R2 that controller redirects to a short-lived signed URL. Buckets are never public.

| Variable | What it is |
|---|---|
| `CHAT_ATTACHMENTS_DISK` | The disk for Chat files. Defaults to `FILESYSTEM_PRIVATE_DISK`, which is also used for message attachments and avatars. Use `s3` in production. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_DEFAULT_REGION` | The bucket's credentials |
| `AWS_ENDPOINT` | R2 only: `https://<account-id>.r2.cloudflarestorage.com` |
| `AWS_DEFAULT_REGION` | R2 only: `auto` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | R2 only: `true` |
| `CHAT_ATTACHMENTS_MAX_FILE_SIZE_KB` | Optional. Default `25600` (25 MB) per file. |
| `CHAT_ATTACHMENTS_MAX_FILES` | Optional. Default `10` per message. |

Keep the bucket private, with public access off. Allowed file types are the same list as project messages (`config/message_attachments.php`), checked against each file's actual contents rather than its name.

PHP and the web server must accept an upload as large as the limit. On Forge, set `upload_max_filesize` and `post_max_size` to at least `26M` (PHP settings) and `client_max_body_size` to at least `26M` (Nginx). Laravel Cloud's defaults are already above this.

### Queue

Real-time delivery does not use the queue: messages, edits, deletes and reactions broadcast during the request. Image thumbnails are queued, so a queue worker should be running, as it already is for invoices. Until a thumbnail is ready, the full image shows instead.

## First deploy, any environment

1. Set the variables above.
2. Deploy. `php artisan migrate --force` creates the Chat tables.
3. Create #general with every active staff member in it:
   ```bash
   php artisan db:seed --class=ChatSeeder --force
   ```
   It's safe to run again later. It only adds anyone who isn't in #general yet.
4. Open Chat in two browsers as two people. Each should see the other's messages and typing straight away, and a green dot for the other.

## Local development

`composer dev` starts Reverb alongside the server, queue, logs and Vite (`php artisan dev:list` shows them). After pulling Chat for the first time, run `php artisan reverb:install` if `.env` has no `REVERB_*` values yet, then restart `composer dev` so Reverb starts and Vite picks up the `VITE_REVERB_*` values.

Locally, Reverb listens on `ws://localhost:8080`, using `REVERB_HOST="localhost"`, `REVERB_PORT=8080` and `REVERB_SCHEME=http`.

## Staging on Laravel Forge

Reverb runs as a long-lived process on the server, kept alive by a Forge daemon. Browsers reach it through Nginx on the site's normal HTTPS domain.

1. **Environment** (site → Environment):
   ```env
   BROADCAST_CONNECTION=reverb
   REVERB_APP_ID=...
   REVERB_APP_KEY=...
   REVERB_APP_SECRET=...
   REVERB_HOST=staging.example.com
   REVERB_PORT=443
   REVERB_SCHEME=https
   REVERB_SERVER_HOST=0.0.0.0
   REVERB_SERVER_PORT=8080
   ```
   Keep the `VITE_REVERB_*` lines from `.env.example`.
2. **Daemon.** Either turn on Forge's Laravel Reverb integration for the site, which creates the daemon and the Nginx proxy for you, or add a daemon by hand (server → Daemons):
   - Command: `php artisan reverb:start --no-interaction`
   - Directory: the site's directory, e.g. `/home/forge/staging.example.com/current`, or the site root without zero-downtime deploys
   - User: `forge`
3. **Nginx.** Skip this if Forge's Reverb integration set it up. Otherwise add this to the site's Nginx config, inside the `server` block for the HTTPS domain, so `wss://staging.example.com/app/...` reaches Reverb:
   ```nginx
   location ~ ^/(app|apps)/ {
       proxy_http_version 1.1;
       proxy_set_header Host $http_host;
       proxy_set_header Scheme $scheme;
       proxy_set_header SERVER_PORT $server_port;
       proxy_set_header REMOTE_ADDR $remote_addr;
       proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "Upgrade";
       proxy_pass http://127.0.0.1:8080;
   }
   ```
4. **Deploy script.** Add this after the build, so the daemon runs the new code:
   ```bash
   php artisan reverb:restart
   ```
   `npm run build` must run after the environment has the `VITE_REVERB_*` values. Forge's deploy script reads the site's environment.
5. Deploy, seed #general (above), and check in two browsers.

Port 8080 doesn't need to be open in the firewall. Only Nginx talks to it.

## Production on Laravel Cloud

Cloud runs Reverb for you as a managed WebSocket cluster. Nothing in the code changes.

1. In the environment, add a **WebSockets** cluster (Reverb) and attach it. Cloud then injects the `REVERB_*` and `VITE_REVERB_*` variables and sets `BROADCAST_CONNECTION=reverb`. Don't set them by hand.
2. Attach a private **object storage** bucket. Cloud injects the `AWS_*` variables. Set `FILESYSTEM_PRIVATE_DISK=s3`, or `CHAT_ATTACHMENTS_DISK=s3` for Chat alone. R2 instead: set the `AWS_*` values from the table above yourself.
3. Redeploy, so the build compiles in the `VITE_REVERB_*` values Cloud just injected.
4. Seed #general (`php artisan db:seed --class=ChatSeeder --force` from Cloud's command runner), and check in two browsers.

A worker for the queue (thumbnails) is a separate Cloud setting, the same as for invoices.

## How it's kept staff only

Clients sign in on a separate guard (`client`, as `Contact` records) and can never reach Chat:

- **Routes.** The Chat pages require `auth:web`, the API requires `auth:sanctum` (web guard only), and both pass through the `staff` middleware (`EnsureUserIsStaff`): an active staff `User`, never a `Contact`.
- **Policies.** `ConversationPolicy` and `ChatMessagePolicy` refuse anyone who isn't a staff `User` before any rule runs. Within the staff, you see and post only in conversations you're in, and only an author can edit or delete their own message.
- **Broadcast channels.** `/broadcasting/auth` sits behind `web`, `auth:web` and `staff`. Every channel in `routes/channels.php` names the web guard and checks the same policies: `conversation.{id}` for its members, `chat.presence` for active staff, `App.Models.User.{id}` for that person alone.

`tests/Feature/ChatAccessTest.php` and `ChatChannelAuthTest.php` cover a signed-in client being refused at each layer.

## Where things are

- `app/Services/ChatService.php`: every change (post, edit, delete, react, join, read). It saves first, then broadcasts.
- `app/Services/ChatUnread.php`: unread and mention counts, from each person's `last_read_message_id`.
- `app/Events/`: `ChatMessagePosted`, `ChatMessageChanged`, `ChatActivity`. All are `ShouldBroadcastNow`.
- `app/Http/Controllers/Chat/`, `routes/api.php` (`/api/chat/...`), `routes/web.php` (`/chat`).
- `resources/js/lib/chatStore.js`: presence, unread counts and the socket, kept for the life of the tab.
- `resources/js/lib/useChatConversation.js`: an open conversation's messages, catch-up, typing and optimistic sends.
- `resources/js/Pages/Chat/Index.jsx`, `resources/js/Components/chat/`.
- `resources/scss/pages/_chat*.scss`, `components/_presence.scss`, `components/_mention-field.scss`.

Message bodies are stored as plain text, with each mention as a `<@user_id>` token. They're rendered as React elements (escaped text, mention chips, http(s) links, line breaks) and never as HTML.

## Troubleshooting

- **Nothing is live, but messages appear after a refresh.** Reverb isn't reachable, or the build has no `VITE_REVERB_*` values. Look in the browser console for a failed `wss://` connection, check the daemon is running (Forge) or the cluster is attached (Cloud), then rebuild.
- **The socket connects, but channels fail with 403.** The person isn't an active staff member in that conversation, or the session expired. Clients always get this, by design.
- **Uploads fail with "too large".** Raise PHP's and Nginx's upload limits (see above).
- **Images stay as full-size copies.** The queue worker isn't running, so thumbnails aren't being made.
