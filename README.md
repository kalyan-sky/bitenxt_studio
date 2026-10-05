# BiteNXT Studio — order messaging

Lets a Pro customer write to the studio about **a specific order** and upload files (scans, photos, PDFs)
after ordering, and lets **studio users** (admins who assign orders to designers, and the designers) read
those messages, download the files and reply. The customer sees the replies in the same order thread, from the
Pro site or the support chatbot.

It is a small PHP service that runs on **Google Cloud Run**, the same way as the support chatbot. One Cloud Run
URL serves everything:

| URL | Who uses it |
|---|---|
| `https://<service>/studio.html` | **The studio frontend.** Studio admins and designers sign in here: inbox, order threads, downloads, replies, assignment, team |
| `https://<service>/studio/api/...` | The same features as a JSON API, if you build your own studio frontend |
| `https://<service>/order-messages.js` | Script for the Pro frontend / chatbot: opens an order's message panel |
| `https://<service>/api/...` | Customer API the script (or the chatbot) calls with the customer's Magento token |
| `https://<service>/health` | Health check |

```
 Pro customer (Pro site or chatbot)                              Studio users
   │  Magento customer token                                      │  studio login (email + password)
   ▼                                                              ▼
 /api/orders/{order}/messages  ── verify token + order ──▶  MySQL: threads, messages, attachments
   files[] ───────────────────────────────────────────────▶  Cloud Storage bucket (mounted at /mnt/studio)
   │                                                              ▲
   └─ notify studio (log + Slack/Teams/Google Chat webhook) ──────┘  /studio.html  ◀── /studio/api/...
```

## Who sees what

- **Customers** only reach threads of their **own** orders. Every request carries the customer's Magento token;
  the service asks Magento who the customer is and whether the order is on their account (`customer { orders }`),
  so there is no order or customer ID to guess. "Not found" looks the same for missing and someone else's orders.
- **Studio admins** see every order thread, assign orders to designers, and manage the studio team.
- **Designers** see only the order threads assigned to them (messages and files).
- Customers see studio replies signed "BiteNXT Studio · <first name>".

## Guardrails

| Risk | Handling | Where |
|---|---|---|
| Reading another clinic's thread or files | Order ownership checked with Magento on every request; threads and files are bound to the customer who created them | `Service/CustomerMessaging.php` |
| Designer seeing other orders | Inbox, threads and downloads filtered by assignment for non-admins | `Service/StudioMessaging.php` |
| Dangerous uploads | Extension allow-list, size and count limits, stored under random names outside the web root, always served as `application/octet-stream` downloads with `Content-Disposition: attachment` and a sandbox CSP | `Files/AttachmentStorage.php`, `public/index.php` |
| Path traversal in file names | Names reduced to a clean basename; storage paths are generated and pattern-checked | `AttachmentStorage::cleanName/path` |
| Password guessing | bcrypt hashes, lockout after 8 failures per email / 30 per IP in 15 min, same timing for unknown emails | `StudioMessaging::login` |
| Stolen session tokens | Random 256-bit tokens, only SHA-256 hashes stored, expire after `STUDIO_SESSION_HOURS`; deactivating a user ends their sessions | `Store/StudioUserRepository.php` |
| XSS | Both frontends render every message and file name as text (`textContent`), never as HTML | `public/studio.html`, `src/Widget/order-messages.js` |
| Message floods | Per-customer messages per minute | `CUSTOMER_MESSAGES_PER_MINUTE` |
| Cross-site calls | CORS: `/api` only from `ALLOWED_ORIGINS` (Pro), `/studio/api` only from `STUDIO_ALLOWED_ORIGINS` or same origin | `public/index.php` |

## Run locally

```bash
composer install
cp .env.example .env                  # set MAGENTO_GRAPHQL_URL (UAT); SQLite in var/ is used by default
php bin/studio-user.php add you@bitenxt.com "Your Name" admin
composer test                         # offline tests with a fake Magento (two clinics)
composer serve                        # http://127.0.0.1:8090/studio.html
```

## Deploy to Cloud Run

See **[docs/DEPLOY.md](docs/DEPLOY.md)**: one script (`deploy/cloudrun-deploy.sh`) or a Cloud Build trigger
(`cloudbuild.yaml`) like the chatbot. After deploying, give the studio team `https://<service>/studio.html`.

## Connect the Pro site and the chatbot

See **[docs/INTEGRATION.md](docs/INTEGRATION.md)**.

## Settings

| Variable | Default | |
|---|---|---|
| `MAGENTO_GRAPHQL_URL` | — | Required. Same as the chatbot |
| `DATABASE_DSN` | SQLite in `var/` | **MySQL on Cloud Run**, e.g. `mysql:host=…;dbname=bitenxt_studio;charset=utf8mb4` |
| `DATABASE_USER` / `DATABASE_PASSWORD` | — | Password from Secret Manager on Cloud Run |
| `STORAGE_DIR` | `var/` (`/mnt/studio` in the image) | Attachments go to `$STORAGE_DIR/attachments` |
| `ALLOWED_ORIGINS` | — | Pro frontend origins allowed to call `/api` |
| `STUDIO_ALLOWED_ORIGINS` | — | Only for a separate studio frontend calling `/studio/api` |
| `STUDIO_URL` | — | Public URL of this service, used in notification links |
| `STUDIO_WEBHOOK_URL` | — | Slack / Teams / Google Chat incoming webhook for new customer messages |
| `STUDIO_ADMIN_EMAIL` / `STUDIO_ADMIN_PASSWORD` | — | Creates the first admin when no studio users exist (then ignored) |
| `MAX_FILE_MB` / `MAX_FILES_PER_MESSAGE` | 25 / 5 | One message must stay under Cloud Run's 32 MB request limit |
| `ALLOWED_FILE_EXTENSIONS` | `stl,ply,obj,dcm,zip,7z,pdf,jpg,jpeg,png,heic,webp,txt` | |
| `MAX_MESSAGE_CHARS` | 4000 | |
| `CUSTOMER_MESSAGES_PER_MINUTE` | 10 | |
| `STUDIO_SESSION_HOURS` | 12 | |
| `TRUSTED_PROXY_HOPS` | 0 (1 in the image) | 1 on Cloud Run, 2 behind an external load balancer |

## Layout

```
public/index.php                 HTTP entry: CORS, uploads, downloads, JSON in/out, no error output
public/studio.html               Studio web app (inbox, threads, replies, assignment, team)
src/Widget/order-messages.js     Customer panel for the Pro site / chatbot, served at /order-messages.js
src/Http/Api.php                 Routes
src/Service/                     CustomerMessaging (Pro side), StudioMessaging (studio side)
src/Store/                       PDO database + schema (MySQL / SQLite), threads, studio users
src/Files/AttachmentStorage.php  Upload checks and storage
src/Magento/                     Customer + own-order lookups via the customer's token
src/Notify/StudioNotifier.php    Event log + webhook
bin/studio-user.php              CLI: add / password / disable / enable / list studio users
Dockerfile, docker/              Cloud Run image (PHP 8.3 + Apache on $PORT)
cloudbuild.yaml, deploy/         Cloud Build pipeline, CLI deploy script, env template
```

## Limits to know

- **Upload size:** Cloud Run caps a request at 32 MB, so files are limited to 25 MB each and one message must fit
  in 32 MB in total. Larger intraoral scan archives need direct-to-bucket uploads (signed URLs). That is not built yet.
- **Updates are polled** (customer panel every 20 s, studio inbox every 15 s), not pushed.
- **Notifications:** studio users get the webhook message; customers see replies when they open the panel or via
  `BitenxtOrderMessages.unread()` (for a badge). Email to the customer is not sent.
