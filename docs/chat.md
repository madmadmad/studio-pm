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
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT` | Where the Reverb process itself listens. Defaults: `0.0.0.0` and `8080`. Only matters when you run Reverb yourself (locally). |
| `REVERB_ALLOWED_ORIGINS` | Optional. Comma-separated hosts allowed to open a socket, e.g. `staging.example.com`. Defaults to any. Every channel requires a signed-in staff member either way. |
| `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | The browser's copy of the values above. `.env.example` points them at the `REVERB_*` values. |

**The `VITE_REVERB_*` values are compiled into the JavaScript at build time.** After setting or changing them, run `npm run build` again (on Cloud, redeploy). Without them, Chat still works but nothing is live.

### Attachments (S3 or Cloudflare R2)

Chat files go on a private disk and are only ever handed out through an authorized controller. On S3 or R2 that controller redirects to a short-lived signed URL. Buckets are never public.

| Variable | What it is |
|---|---|
| `CHAT_ATTACHMENTS_DISK` | Optional. The disk for Chat files. Defaults to `FILESYSTEM_PRIVATE_DISK` (`private` on Cloud), which is also used for message attachments and avatars. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_DEFAULT_REGION` | The bucket's credentials |
| `AWS_ENDPOINT` | R2 only: `https://<account-id>.r2.cloudflarestorage.com` |
| `AWS_DEFAULT_REGION` | R2 only: `auto` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | R2 only: `true` |
| `CHAT_ATTACHMENTS_MAX_FILE_SIZE_KB` | Optional. Default `25600` (25 MB) per file. |
| `CHAT_ATTACHMENTS_MAX_FILES` | Optional. Default `10` per message. |

Keep the bucket private, with public access off. Allowed file types are the same list as project messages (`config/message_attachments.php`), checked against each file's actual contents rather than its name.

The `AWS_*` lines are only for a server that isn't on Laravel Cloud, where you'd point the s3 disk at a bucket yourself. On Cloud, the attached buckets are set up as disks with no variables to add.

### GIFs (GIPHY)

| Variable | What it is |
|---|---|
| `GIPHY_API_KEY` | A key from [developers.giphy.com](https://developers.giphy.com). Without one, the GIF button is hidden. |
| `GIPHY_RATING` | Optional. The highest content rating offered: `g`, `pg` (the default), `pg-13` or `r`. |

Searches go through the server (`/api/chat/gifs`), so the key never reaches a browser. A sent GIF is looked up on GIPHY by its ID, and GIPHY's own URL is saved, never one the browser supplied. The GIFs themselves load from GIPHY's servers. A new GIPHY key starts as a rate-limited beta key, which is plenty for the studio. Applying for a production key needs the app's GIPHY attribution ("Powered by GIPHY" in the picker) to be in place, and it is.

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

## Production on Laravel Cloud

studio-pm runs on Laravel Cloud (docs/laravel-cloud.md). Cloud runs Reverb for you as a managed WebSocket cluster, so nothing in the code changes:

1. Add a **WebSockets** cluster (Reverb) and a WebSocket application in it, attached to the production environment. Cloud injects the `REVERB_*` and `VITE_REVERB_*` variables and sets `BROADCAST_CONNECTION=reverb`. Don't set them by hand.
2. Deploy, so the build compiles in the `VITE_REVERB_*` values. A push to `main` deploys on its own.
3. Seed #general once: `php artisan db:seed --class=ChatSeeder --force` from Cloud's command runner.
4. Check in two browsers, signed in as two people.

Chat's files use the private bucket that's already attached for the rest of the app (disk `private`, through `FILESYSTEM_PRIVATE_DISK`), so there's nothing extra to set up for them. The queue worker that already runs makes Chat's image thumbnails too.

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

- **Nothing is live, but messages appear after a refresh.** Reverb isn't reachable, or the build has no `VITE_REVERB_*` values. Look in the browser console for a failed `wss://` connection, check the WebSocket application is attached to the environment, then redeploy.
- **The socket connects, but channels fail with 403.** The person isn't an active staff member in that conversation, or the session expired. Clients always get this, by design.
- **Uploads fail with "too large".** Raise PHP's and Nginx's upload limits (see above).
- **Images stay as full-size copies.** The queue worker isn't running, so thumbnails aren't being made.
