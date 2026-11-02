# Deploying to Render (Docker) + Aiven (MySQL)

```
Browser ──HTTPS──▶ Render (TLS) ──▶ Docker container
                                     ├─ Apache + PHP 8.3   (web app, port $PORT)
                                     ├─ /ws → Ratchet WebSocket (127.0.0.1:8080)
                                     └─ email-queue loop   (every 30 s)
                                            │                    │
                              Aiven MySQL (TLS) ◀──┘     Cloudflare R2 (documents)
```

## 1. Aiven — create the database

1. Aiven Console → **Create service → MySQL**. Pick a region near Render's
   **Singapore** region (e.g. `google-asia-southeast1` or `aws-ap-southeast-1`).
2. From the service **Overview**, note **Host**, **Port** (not 3306), **User**
   (`avnadmin`), **Password**, and **download the CA certificate** (`ca.pem`).
3. Create the app database (Databases tab, or):
   ```sql
   CREATE DATABASE ched_compliance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

## 2. Move your data from XAMPP to Aiven

Export from your local machine:

```bash
mysqldump -u root -p --single-transaction --routines --triggers --no-tablespaces \
  ched_compliance > ched_compliance.sql
```

If your local server is **MariaDB** (XAMPP default), clean MariaDB-only bits first:

```bash
sed -i.bak -e 's/utf8mb4_uca1400_ai_ci/utf8mb4_unicode_ci/g' \
           -e 's/DEFINER=[^ ]* //g' ched_compliance.sql
```

Import into Aiven:

```bash
mysql --host=<AIVEN_HOST> --port=<AIVEN_PORT> --user=avnadmin --password \
  --ssl-mode=VERIFY_CA --ssl-ca=ca.pem ched_compliance < ched_compliance.sql
```

**If the import fails with "Unable to create or change a table without a primary
key"**: Aiven enables `sql_require_primary_key`. Either add a primary key to that
table, or in Aiven → Service → **Advanced configuration** set
`mysql.sql_require_primary_key = false`.

## 3. Render — deploy

1. Push this branch to GitHub.
2. Render Dashboard → **New → Blueprint** → choose the repo. Render reads
   `render.yaml` and asks for every secret (`DB_*`, `R2_*`, `SMTP_*`, `APP_HOST`).
   - `DB_HOST` / `DB_PORT` / `DB_USER` / `DB_PASS` → from Aiven
   - `APP_HOST` → your Render hostname, e.g. `ched-compliance.onrender.com`
     (no `https://`). You can fill it in after the first deploy and redeploy.
3. Service → **Environment → Secret Files → Add**: filename **`ca.pem`**, paste
   the Aiven CA certificate. (Alternative: env var `DB_SSL_CA_CERT` with the PEM text.)
4. Deploy. Health check: `https://<APP_HOST>/healthz.php` returns `ok`.
5. Optional: Aiven → Service → **Allowed IP addresses** — restrict to Render's
   outbound IPs (Render service → Connect → Outbound).

## 4. After the first deploy — check

- Log in; dashboards load (DB over TLS works).
- Upload + download a document (R2 works).
- Trigger a notification; it appears without refreshing (WebSocket via `/ws`).
- An email-producing action delivers within ~30 s (queue worker).
- Render **Logs** tab shows Apache, WebSocket and PHP errors.

## Environment variables added for this setup

| Variable | Purpose |
|---|---|
| `DB_PORT` | Aiven port (default 3306 locally) |
| `DB_SSL_CA` | Path to CA cert; enables TLS + server verification |
| `DB_SSL_VERIFY` | `true` (default). Only set `false` for debugging |
| `DB_SQL_MODE` | Overrides Aiven's ANSI default, which breaks `"..."` string literals in SQL |
| `WS_PUBLIC_PATH` | `/ws` → browser uses `wss://APP_HOST/ws` (Render exposes one port) |
| `EMAIL_DISABLE_SPAWN` | `1` in Docker; the supervised loop sends queued email instead |
| `EMAIL_QUEUE_INTERVAL` | Seconds between queue runs (default 30) |

Local XAMPP keeps working unchanged: leave these empty in your local `.env`.

## Known limitations on Render

- **Profile pictures** (`uploads/avatars`) are stored on local disk and reset on
  each deploy. Fix: attach a Render Disk at `/var/www/html/uploads` (paid plans),
  or move avatars to R2.
- **Sessions** are stored in the container; users are logged out on redeploy.
- Run **one instance** only — the WebSocket outbox and sessions are per-container.
- The **free** plan sleeps after 15 min idle (first request is slow).

## Build and run locally (optional)

```bash
docker build -t ched .
docker run --rm -p 10000:10000 --env-file .env -v "$PWD/ca.pem:/etc/secrets/ca.pem:ro" ched
# open http://localhost:10000
```
