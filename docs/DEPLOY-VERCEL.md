# Deploying RBEL-CRM (demo) to Vercel with Git

Vercel does not run PHP or host MySQL by itself, so this setup uses:

| Part | Where it runs |
|---|---|
| Laravel (PHP 8.3) | Vercel serverless function, via the community runtime `vercel-php@0.7.4` (`api/index.php`) |
| React frontend | Built by Vercel (`npm run build`) and served as static files from `public/build` |
| MySQL 8 database | **Aiven for MySQL** (free plan: 1 CPU, 1 GB RAM, 1 GB disk, no time limit) |

The project already includes the files Vercel needs: `vercel.json`, `api/index.php` and `.vercelignore`.

> **Why Aiven?** The app needs real MySQL 8 with views and stored procedures. PlanetScale and TiDB don't support stored procedures, and XAMPP/MariaDB-style servers are not supported. Any MySQL 8 host that accepts outside connections also works (Railway, DigitalOcean, AWS RDS…).

### Limits of running on Vercel (fine for a demo)

- **Uploaded files are temporary.** Coverage documents, document templates and email images are saved in `/tmp` and disappear when Vercel starts a fresh instance (often within minutes). For permanent uploads you would need S3 or similar storage.
- **Automations don't run by themselves.** There is no always-on scheduler. Use **Run now** on the Automations page.
- **Email is off** (`MAIL_MAILER=log`). Sends are logged as failed.
- The first request after a quiet period is slower (cold start).

---

## Step 1: Create the MySQL database on Aiven

1. Sign up at **https://aiven.io** (free plan).
2. Click **Create service**, choose **MySQL**, version **8.4** (MySQL 8.0 reaches end of life on 31 Oct 2026; Aiven has no MySQL 9), plan **Free**, pick a region close to you (for example Singapore), and create it. Wait until the status shows **Running**.
3. On the service's **Overview** page, note the connection details:
   - **Host** (e.g. `mysql-rbel-xxxx.aivencloud.com`)
   - **Port** (e.g. `12345`, not 3306)
   - **User** `avnadmin`
   - **Password** (click the eye icon)
   - **Database** `defaultdb`
4. On the same page, download the **CA certificate** and save it in the project as:

   ```
   C:\wamp64\www\rbel-crm-demo\certs\aiven-ca.pem
   ```

   This certificate is public (it only proves the server is genuine), so it is safe to commit. The password is **not** committed. It goes into Vercel's settings in Step 4.

## Step 2: Load the tables and dummy data into Aiven (from your PC)

Open **PowerShell** and run the following, using your Aiven values. These variables last only for this window, and they take priority over your local `.env`.

```powershell
cd C:\wamp64\www\rbel-crm-demo
$env:DB_HOST="mysql-rbel-xxxx.aivencloud.com"
$env:DB_PORT="12345"
$env:DB_DATABASE="defaultdb"
$env:DB_USERNAME="avnadmin"
$env:DB_PASSWORD="your-aiven-password"
$env:MYSQL_ATTR_SSL_CA="certs/aiven-ca.pem"

php artisan migrate:fresh --seed --force
```

> ⚠️ `migrate:fresh` deletes all tables first. Check that `$env:DB_HOST` points to Aiven before you run it. Otherwise it resets your local demo database instead.

When it finishes, close this PowerShell window.

## Step 3: Push the project to GitHub

1. On **https://github.com/new**, create a repository named `rbel-crm-demo` (public or private), **without** a README or .gitignore.
2. In the project folder:

```powershell
cd C:\wamp64\www\rbel-crm-demo
git add .
git commit -m "RBEL-CRM demo with synthetic data and Vercel config"
git branch -M main
git remote add origin https://github.com/YOUR-USERNAME/rbel-crm-demo.git
git push -u origin main
```

Before pushing, check that `git status` does **not** list `.env`. It is ignored by `.gitignore` and must never be uploaded.

## Step 4: Import the repository into Vercel

1. Sign in at **https://vercel.com** with your GitHub account.
2. Click **Add New… → Project**, find `rbel-crm-demo` and click **Import**.
3. **Framework Preset:** `Other`. Leave the build and output settings alone (they come from `vercel.json`).
4. Open **Environment Variables** and add:

   | Name | Value |
   |---|---|
   | `APP_KEY` | output of `php artisan key:generate --show` (run it in the project folder; starts with `base64:`) |
   | `APP_URL` | `https://rbel-crm-demo.vercel.app` (adjust after the first deploy, see Step 5) |
   | `SANCTUM_STATEFUL_DOMAINS` | `rbel-crm-demo.vercel.app` (your Vercel domain, **without** `https://`) |
   | `DB_CONNECTION` | `mysql` |
   | `DB_HOST` | your Aiven host |
   | `DB_PORT` | your Aiven port |
   | `DB_DATABASE` | `defaultdb` |
   | `DB_USERNAME` | `avnadmin` |
   | `DB_PASSWORD` | your Aiven password |
   | `MYSQL_ATTR_SSL_CA` | `certs/aiven-ca.pem` |
   | `APP_TIMEZONE` | `Asia/Manila` |
   | `DB_TIMEZONE` | `+08:00` |

   The other production settings (debug off, `/tmp` cache paths, logging, sessions) are already in `vercel.json`.

5. Click **Deploy**. The first build takes a few minutes: Vercel installs the PHP packages (Composer) and builds the React app.

## Step 5: Fix the domain and redeploy

1. When the deploy finishes, note your site's address, for example `https://rbel-crm-demo-abc1.vercel.app`.
2. If it differs from what you entered, go to **Project → Settings → Environment Variables** and update:
   - `APP_URL` → `https://<your-domain>`
   - `SANCTUM_STATEFUL_DOMAINS` → `<your-domain>` (no `https://`)
3. Go to **Deployments**, open the latest one, choose **⋯ → Redeploy**.
4. Open the site and sign in with `admin@rbel-crm.test` / `password`.

> `SANCTUM_STATEFUL_DOMAINS` must match the address in the browser exactly. If it doesn't, login fails with **"Session store not set on request."** Vercel's *preview* deployments get different addresses, so log in on the main production domain.

## Updating the site later

Every `git push` to `main` deploys automatically:

```powershell
git add .
git commit -m "Describe the change"
git push
```

If a change adds a new migration, run it against Aiven from your PC the same way as in Step 2, but with `php artisan migrate --force` (not `migrate:fresh`, which wipes the data).

To reset the demo data on Aiven, repeat Step 2.

---

## Troubleshooting

| Problem | Fix |
|---|---|
| **"Session store not set on request"** or **419** when signing in | `SANCTUM_STATEFUL_DOMAINS` doesn't match the browser address. Update it and redeploy (Step 5). |
| **500 error** | Vercel → project → **Logs** shows the PHP error. A common cause is a missing `APP_KEY` or a wrong DB setting. |
| `SQLSTATE[HY000] [2002]` or SSL errors | Check `DB_HOST`/`DB_PORT`, and that `certs/aiven-ca.pem` is committed to Git and `MYSQL_ATTR_SSL_CA=certs/aiven-ca.pem` is set. |
| `Base table or view not found` | Step 2 wasn't run against Aiven, or it ran against a different database. |
| Page loads without styles, or `/build/...` returns 404 | Check the build log for `npm run build` errors. |
| Aiven service is "Powered off" | Free services are switched off after a long time unused. Power it back on in the Aiven console. |
