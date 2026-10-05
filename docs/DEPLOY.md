# Deploying to Cloud Run

This follows the same pattern as the support chatbot (same project and region recommended, same Artifact
Registry repository). The service needs two things that outlive a Cloud Run container:

1. **A MySQL database** for threads, messages and studio users. Pick one:
   - **A: the MySQL server Magento already uses.** Create a database and user there (no extra cost). Cloud Run must
     be able to reach it: a public IP restricted by firewall, or a private IP through a
     [VPC connector / Direct VPC egress](https://cloud.google.com/run/docs/configuring/vpc-direct-vpc).
   - **B: Cloud SQL for MySQL.** Smallest shared-core instance; connected through the Cloud SQL socket.

   ```sql
   CREATE DATABASE bitenxt_studio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'bitenxt_studio'@'%' IDENTIFIED BY '<strong password>';
   GRANT ALL PRIVILEGES ON bitenxt_studio.* TO 'bitenxt_studio'@'%';
   ```
   Tables are created automatically on the first request.

2. **A Cloud Storage bucket** for attachments. It is mounted into the container at `/mnt/studio`
   (Cloud Run volume mount, gen2), so files are just written to disk. The bucket stays private.

## Option 1: one command (gcloud CLI)

```bash
cp deploy/env.example.yaml deploy/env.yaml   # set MAGENTO_GRAPHQL_URL, DATABASE_DSN, ALLOWED_ORIGINS
# first deploy only: add the first admin to env.yaml (the password can stay; it is ignored once users exist)
#   STUDIO_ADMIN_EMAIL: "studio-admin@bitenxt.com"
#   STUDIO_ADMIN_PASSWORD: "<temporary password, change it after signing in>"
gcloud auth login
PROJECT_ID=your-project REGION=asia-south1 ./deploy/cloudrun-deploy.sh
# with Cloud SQL:
PROJECT_ID=your-project REGION=asia-south1 CLOUDSQL_INSTANCE=your-project:asia-south1:studio-db ./deploy/cloudrun-deploy.sh
```

The script enables the APIs, creates the service account, the private bucket, the `studio-db-password` secret
(it asks for the password once), and deploys. At the end it prints the URLs, for example:

```
Studio app (give this URL to the studio team): https://bitenxt-studio-abc123-el.a.run.app/studio.html
```

Then set `STUDIO_URL` in `deploy/env.yaml` to the service URL and run the script again (notification links).

## Option 2: Cloud Build trigger (console, like the chatbot)

1. **One-time setup** (Cloud Console):
   - Create the bucket (Cloud Storage → Create; same region; *Enforce public access prevention* on).
   - Create the service account `bitenxt-studio`; give it **Storage Object User** on the bucket, **Secret Manager
     Secret Accessor** on the secret below, and **Cloud SQL Client** if you use Cloud SQL.
   - Secret Manager → create `studio-db-password` with the MySQL password.
   - The Cloud Build service account needs **Cloud Run Admin** and **Service Account User** on `bitenxt-studio`
     (already granted if you did this for the chatbot's trigger).
2. **Trigger:** Cloud Build → Triggers → Create, repository `kalyan-sky/bitenxt_studio`, branch `^main$`,
   configuration `cloudbuild.yaml`. Substitution variables: `_BUCKET` (bucket name), `_CLOUDSQL_INSTANCE`
   (Cloud SQL only), and `_REGION` / `_SERVICE` if different.
3. **Run it once**, then in Cloud Run → `bitenxt-studio` → Edit & deploy new revision → Variables, set the
   variables from `deploy/env.example.yaml` (`MAGENTO_GRAPHQL_URL`, `DATABASE_DSN`, `DATABASE_USER`,
   `ALLOWED_ORIGINS`, `STUDIO_URL`, and for the first deploy `STUDIO_ADMIN_EMAIL` / `STUDIO_ADMIN_PASSWORD`).
   They are kept on every later deploy.

Every push to `main` then runs the tests and deploys a new revision; failing tests stop the deploy.

## Create studio users

- **First admin:** `STUDIO_ADMIN_EMAIL` + `STUDIO_ADMIN_PASSWORD` (above). It is created on the first request,
  only when no studio user exists yet. Sign in at `/studio.html`.
- **Everyone else:** an admin opens **Team** in the studio app and adds designers and other admins
  (each gets a temporary password to share with them). Deactivating someone signs them out immediately.
- From a terminal with database access: `php bin/studio-user.php add|password|disable|enable|list`.

## Check it

```bash
curl https://<service>/health                    # {"ok":true}
open https://<service>/studio.html               # sign in as the first admin
```

From a Pro page (UAT) with a logged-in customer, open the browser console:

```js
BitenxtOrderMessages.open('000001234')           // an order of that customer
```

Send a message with a file; it appears in the studio inbox (and in Slack/Teams if `STUDIO_WEBHOOK_URL` is set).

## Logs

Cloud Run → Logs. Events: `customer_message` (order, file count, no message text), `webhook_failed`, and
PHP errors as `[bitenxt-studio] ...`.
