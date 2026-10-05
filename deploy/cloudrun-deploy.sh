#!/usr/bin/env bash
# Deploys BiteNXT Studio messaging to Cloud Run. Safe to re-run: each step skips
# what already exists, and the last step rolls out a new revision.
#
#   PROJECT_ID=my-project REGION=asia-south1 ./deploy/cloudrun-deploy.sh
#   # with Cloud SQL:
#   PROJECT_ID=my-project REGION=asia-south1 CLOUDSQL_INSTANCE=my-project:asia-south1:studio-db ./deploy/cloudrun-deploy.sh
#
# Needs: gcloud (logged in as someone who can enable APIs, create service
# accounts, buckets and deploy), and deploy/env.yaml (copy env.example.yaml).
set -euo pipefail

: "${PROJECT_ID:?Set PROJECT_ID}"
: "${REGION:?Set REGION, e.g. asia-south1 (same region as the chatbot)}"
SERVICE="${SERVICE:-bitenxt-studio}"
SA_NAME="${SA_NAME:-bitenxt-studio}"
SA="${SA_NAME}@${PROJECT_ID}.iam.gserviceaccount.com"
BUCKET="${BUCKET:-${PROJECT_ID}-studio-attachments}"
CLOUDSQL_INSTANCE="${CLOUDSQL_INSTANCE:-}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${ENV_FILE:-$ROOT/deploy/env.yaml}"

[[ -f "$ENV_FILE" ]] || { echo "Missing $ENV_FILE - copy deploy/env.example.yaml and edit it."; exit 1; }
grep -Eq '^DATABASE_DSN: *"mysql:' "$ENV_FILE" || { echo "Set DATABASE_DSN to a MySQL DSN in $ENV_FILE (Cloud Run disks are temporary)."; exit 1; }

gcloud config set project "$PROJECT_ID" >/dev/null

echo "==> Enabling APIs"
gcloud services enable run.googleapis.com cloudbuild.googleapis.com artifactregistry.googleapis.com \
  storage.googleapis.com secretmanager.googleapis.com ${CLOUDSQL_INSTANCE:+sqladmin.googleapis.com}

echo "==> Service account"
if ! gcloud iam service-accounts describe "$SA" >/dev/null 2>&1; then
  gcloud iam service-accounts create "$SA_NAME" --display-name="BiteNXT studio messaging (Cloud Run)"
fi

echo "==> Attachment bucket gs://$BUCKET (private, mounted into the service)"
if ! gcloud storage buckets describe "gs://$BUCKET" >/dev/null 2>&1; then
  gcloud storage buckets create "gs://$BUCKET" --location="$REGION" --uniform-bucket-level-access --public-access-prevention
fi
gcloud storage buckets add-iam-policy-binding "gs://$BUCKET" --member="serviceAccount:$SA" \
  --role=roles/storage.objectUser >/dev/null

if [[ -n "$CLOUDSQL_INSTANCE" ]]; then
  echo "==> Cloud SQL access"
  gcloud projects add-iam-policy-binding "$PROJECT_ID" --member="serviceAccount:$SA" \
    --role=roles/cloudsql.client --condition=None >/dev/null
fi

echo "==> Database password in Secret Manager"
if ! gcloud secrets describe studio-db-password >/dev/null 2>&1; then
  read -r -s -p "Paste the MySQL password for the studio database user (input hidden): " PW; echo
  [[ -n "$PW" ]] || { echo "A database password is required."; exit 1; }
  printf '%s' "$PW" | gcloud secrets create studio-db-password --replication-policy=automatic --data-file=-
  unset PW
fi
gcloud secrets add-iam-policy-binding studio-db-password --member="serviceAccount:$SA" \
  --role=roles/secretmanager.secretAccessor >/dev/null

echo "==> Building and deploying (Cloud Build uses the Dockerfile)"
gcloud run deploy "$SERVICE" \
  --source "$ROOT" \
  --region "$REGION" \
  --service-account "$SA" \
  --env-vars-file "$ENV_FILE" \
  --set-secrets DATABASE_PASSWORD=studio-db-password:latest \
  --execution-environment gen2 \
  --add-volume "name=attachments,type=cloud-storage,bucket=$BUCKET" \
  --add-volume-mount "volume=attachments,mount-path=/mnt/studio" \
  ${CLOUDSQL_INSTANCE:+--add-cloudsql-instances "$CLOUDSQL_INSTANCE"} \
  --allow-unauthenticated \
  --port 8080 \
  --cpu 1 --memory 1Gi \
  --concurrency 20 --timeout 300 \
  --min-instances 0 --max-instances 5 \
  --cpu-boost

URL="$(gcloud run services describe "$SERVICE" --region "$REGION" --format='value(status.url)')"
cat <<MSG

Deployed: $URL
  Health check      : $URL/health
  Studio app (give this URL to the studio team): $URL/studio.html
  Studio API        : $URL/studio/api/...   (for a custom studio frontend; add its origin to STUDIO_ALLOWED_ORIGINS)
  Customer API      : $URL/api/...          (Pro frontend + chatbot)

Next steps:
  1. Set STUDIO_URL: "$URL" in deploy/env.yaml (links in notifications) and re-run this script.
  2. Create the first studio admin (see docs/DEPLOY.md, "Create studio users").
  3. Add to the Pro frontend:
       <script src="$URL/order-messages.js" data-token-key="customerToken"></script>
     then call BitenxtOrderMessages.open(orderNumber) from the order page or the chatbot.
MSG
