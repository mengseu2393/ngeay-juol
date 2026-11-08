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
| Creating invoices by hand from `/m2` | Profile / ID photos **without** the R2 vars below — `/tmp` is per-instance and wiped |
| Profile and ID photos, once the R2 vars are set | |
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

# Photos (profile, tenant ID cards) — Cloudflare R2 through the S3 driver. See "Photos (R2)" below.
MEDIA_DISK=s3
AWS_ACCESS_KEY_ID=...         # R2 → Manage API tokens → Object Read & Write, this bucket only
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=auto
AWS_BUCKET=rentwise-m2
AWS_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_URL=https://pub-<hash>.r2.dev   # the bucket's public URL (r2.dev or your custom domain), no trailing /

M2_ENABLED=false              # keep /m2 tester-only
M2_TESTERS=you@example.com
M2_DEFAULT=true               # testers land on /m2 after sign-in and at "/"
```

Defaults already set by `api/index.php` (override in the dashboard if needed):
`LOG_CHANNEL=stderr`, `TRUSTED_PROXIES=*`, `LARAVEL_STORAGE_PATH=/tmp/storage`,
`APP_*_CACHE=/tmp/bootstrap/*.php`.

## Photos (R2)

Vercel's disk is per-instance and wiped, so a photo saved there disappears (a broken image
on Home / Settings). With `MEDIA_DISK=s3` Media Library writes photos to the bucket and
their URLs point at `AWS_URL`; `M2Shell::mediaUrl()` leaves such URLs whole (it only strips
the host from this app's own `/storage/…` URLs).

1. Cloudflare → R2 → Create bucket (e.g. `rentwise-m2`, location: Asia-Pacific).
2. Bucket → Settings → Public access: enable the `r2.dev` subdomain (or connect a custom
   domain) and put that URL in `AWS_URL`. Photos are served from it directly.
   Anyone with a photo's URL can open it — the same as `/storage/…` on the local server.
3. R2 → Manage API tokens → Create token, "Object Read & Write", limited to this bucket.
   The Access Key ID / Secret go in `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`; the
   S3 endpoint shown there is `AWS_ENDPOINT`.
4. Add the vars, redeploy, upload a profile photo in `/m2/settings/profile` and check
   it shows on Home and appears in the bucket.

Photos uploaded before this was set up were lost with the instance — upload them again.

Only the saved photo goes to R2. Livewire's in-between upload (`livewire-tmp/`) stays on
the instance's `/tmp` (`FILESYSTEM_DISK` unset): the save request normally reaches the
same warm instance. If saving a photo ever fails with a "file not found" error, set
`FILESYSTEM_DISK=s3` too — Livewire then uploads straight from the browser to the bucket
— which needs a CORS rule on the bucket (allowed origin: the Vercel URL; methods: PUT,
GET; headers: `*`) and a lifecycle rule deleting `livewire-tmp/` after 1 day. The upload
code already reads files as streams (`App\Support\MediaUpload`), so it works either way;
whether R2 accepts the `x-amz-acl` header Livewire signs into that upload is untested.

The local server is unchanged: without `MEDIA_DISK` photos stay on the `public` disk.

## Deploy

1. Create the database, then from your machine point `.env` at it and run
   `php artisan migrate --seed` once (Vercel can't run artisan).
2. `npx vercel` (first time: link the project, set "Other" framework), add the env vars above, then `npx vercel --prod`.
3. Open `https://<project>.vercel.app`, sign in as a tester — `M2_DEFAULT=true` lands you on `/m2`.

If a page 500s, check the function logs in the Vercel dashboard (logs go to stderr).
