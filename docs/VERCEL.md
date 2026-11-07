# Vercel test deploy (/m2 only)

A throwaway deploy for testing the `/m2` phone app. **Not** for the full app or
production — see `DEPLOYMENT.md` for that. Files: `vercel.json`, `api/index.php`,
`.vercelignore`.

Runs in Vercel's Singapore region (`sin1`, next to the TiDB database — the default
`iad1` adds ~200ms per query) on the community `vercel-php@0.7.4` runtime (PHP 8.3). Every request goes to
`api/index.php`, which moves Laravel's `storage/` and `bootstrap/cache` files to
`/tmp` (the only writable path) and trusts Vercel's proxy headers. Static files in
`public/` (incl. `css/m2.css`, Filament assets, Vite's `public/build`) are served directly.

## What works / what doesn't

| Works | Doesn't (on Vercel) |
|---|---|
| All `/m2` screens, login, Livewire | Slip **PDF / Share** — no Chrome, falls back to dompdf → mangled Khmer |
| Creating invoices by hand from `/m2` | ID photo uploads — `/tmp` is per-instance and wiped (needs S3/R2) |
| | Scheduler (auto rent invoices, overdue, subscriptions) and queued jobs |

## Env vars (Vercel → Project → Settings → Environment Variables)

```
APP_NAME=RentWise
APP_ENV=production
APP_KEY=base64:...            # php artisan key:generate --show
APP_DEBUG=false               # true briefly if you need to see an error page
APP_URL=https://<project>.vercel.app
APP_LOCALE=km

DB_CONNECTION=mysql
DB_HOST=...                   # hosted MySQL (Aiven / TiDB Serverless / Railway)
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
# MYSQL_ATTR_SSL_CA=/etc/pki/tls/certs/ca-bundle.crt   # if the host requires TLS

SESSION_DRIVER=database       # no Redis on Vercel
CACHE_STORE=database
QUEUE_CONNECTION=sync
MAIL_MAILER=log

M2_ENABLED=false              # keep /m2 tester-only
M2_TESTERS=you@example.com
M2_DEFAULT=true               # testers land on /m2 after sign-in and at "/"
```

Defaults already set by `api/index.php` (override in the dashboard if needed):
`LOG_CHANNEL=stderr`, `TRUSTED_PROXIES=*`, `LARAVEL_STORAGE_PATH=/tmp/storage`,
`APP_*_CACHE=/tmp/bootstrap/*.php`.

## Deploy

1. Create the database, then from your machine point `.env` at it and run
   `php artisan migrate --seed` once (Vercel can't run artisan).
2. `npx vercel` (first time: link the project, set "Other" framework), add the env vars above, then `npx vercel --prod`.
3. Open `https://<project>.vercel.app`, sign in as a tester — `M2_DEFAULT=true` lands you on `/m2`.

If a page 500s, check the function logs in the Vercel dashboard (logs go to stderr).
