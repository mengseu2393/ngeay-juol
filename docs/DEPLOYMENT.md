# Deployment

RentWise is a standard Laravel app with extra operational requirements for queues, scheduler, Chrome/Browsershot, writable storage, and built frontend assets.

## Server Requirements

- PHP 8.2+
- Composer
- Node and npm
- Database supported by Laravel
- Google Chrome or Chromium
- Supervisor or another process manager for queue workers
- Cron for Laravel scheduler
- Writable `storage/` and `bootstrap/cache/`
- Redis, recommended for session, cache, and queue storage under concurrency

## Build Steps

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Run the base seed only when bootstrapping a new environment:

```bash
php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder --force
php artisan db:seed --class=Database\\Seeders\\AdminUserSeeder --force
```

Set `RENTWISE_ADMIN_EMAIL` and `RENTWISE_ADMIN_PASSWORD` before seeding.

## Environment

Production should set:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
BROWSERSHOT_CHROME_PATH=/usr/bin/google-chrome
```

Use a real mailer before enabling notification workflows. The default local mailer logs messages.

The database drivers above are the safe default and work everywhere. Move session, cache, and queue to Redis once the deployment carries real concurrency — see the next section.

## Redis

The database session driver runs a `SELECT` plus an `UPDATE` against MySQL on every request, and concurrent Livewire requests from one browser contend on the same session row. Redis removes that contention and speeds up cache and queue work at the same time.

`predis/predis` is a Composer dependency, so the app can talk to Redis with no PHP extension to compile. Keep `REDIS_CLIENT=predis` unless the `phpredis` C extension is installed on the host, in which case `REDIS_CLIENT=phpredis` is faster.

Install and enable Redis natively on Debian/Ubuntu:

```bash
sudo apt-get update
sudo apt-get install -y redis-server
sudo systemctl enable --now redis-server
redis-cli ping   # expect: PONG
```

Or run it as a container, if the deployment is container based:

```bash
docker run -d --name rentwise-redis \
  --restart unless-stopped \
  -p 127.0.0.1:6379:6379 \
  redis:7-alpine redis-server --appendonly yes
docker exec rentwise-redis redis-cli ping   # expect: PONG
```

Either way, keep the port bound to localhost or a private network, and set `REDIS_PASSWORD` if Redis is reachable from anywhere else. Enable persistence (`--appendonly yes`, or the RDB snapshots the Debian package enables by default) so a Redis restart does not log every landlord out. Leave `maxmemory-policy` at `noeviction`: an LRU policy would let Redis silently discard sessions and queued jobs under memory pressure.

Then flip the drivers in `.env`:

```dotenv
SESSION_DRIVER=redis
SESSION_CONNECTION=session
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
```

`SESSION_CONNECTION=session` points sessions at their own Redis database (`REDIS_SESSION_DB`, default `2`), separate from the queue and locks on database `0` and the cache on database `1`. Without it, sessions land on database `0` next to the queue, where routine queue maintenance can wipe them. When staging and production share one Redis instance, also set a distinct `REDIS_PREFIX` per environment — both otherwise derive it from `APP_NAME`.

Drain the old database queue before switching, or jobs already in the `jobs` table are stranded:

```bash
php artisan queue:work database --stop-when-empty
```

Apply the change and restart the workers. Switching the session driver logs everyone out once, so do it during a quiet window:

```bash
php artisan config:clear
php artisan config:cache
php artisan queue:restart
```

## HTTPS and Cookies

Production must be served over HTTPS. `config/session.php` marks the session cookie `Secure` automatically when `APP_ENV=production`, keeps `HttpOnly` on, and uses `SameSite=Lax`; the same values seed the "remember me" cookie and anything built with the `cookie()` helper. Local HTTP development is unaffected because `APP_ENV=local`, and the test suite because `APP_ENV=testing`.

No `.env` entry is needed for the normal case. Override only for an unusual one:

```dotenv
# Only if a production host is deliberately served over plain HTTP.
SESSION_SECURE_COOKIE=false
```

`SameSite=Lax` is correct for this app: the admin panel, landlord panel, and tenant portal are all same-site, and `Lax` still allows top-level redirects back from an external payment or email link. Do not raise it to `strict` without checking those return flows. `SESSION_SAME_SITE=none` would additionally require `SESSION_SECURE_COOKIE=true` and `SESSION_PARTITIONED_COOKIE=true` to be accepted by browsers.

Behind a reverse proxy or load balancer that terminates TLS, the app sees plain HTTP unless the proxy is trusted. Register it in `bootstrap/app.php` so `X-Forwarded-Proto` is honoured, otherwise `url()` and `asset()` emit `http://` links and mixed-content warnings:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '10.0.0.0/8');   // or the proxy's IP, or '*' for a single trusted hop
})
```

Trust a specific proxy IP or CIDR wherever possible; `'*'` is only safe when nothing but the proxy can reach the PHP process. Set `APP_URL` to the `https://` origin in every case — queued mail, notifications, and PDF rendering build absolute URLs from it, with no request to infer the scheme from.

## Queue

Run a queue worker under Supervisor or systemd:

```bash
php artisan queue:work --tries=3 --timeout=60
```

The worker timeout must stay below the connection's `retry_after` (90 seconds by default on both the database and redis connections), or a job that is still running gets released and picked up a second time. If a long Browsershot export needs more than 60 seconds, raise `DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER` first and keep `--timeout` under it.

Restart workers after deployments:

```bash
php artisan queue:restart
```

## Scheduler

Install one cron entry:

```cron
* * * * * cd /path/to/rentwise && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler handles rental status updates, monthly rent invoice generation, and subscription lifecycle processing.

## Storage

Create the public storage link if public uploads are used:

```bash
php artisan storage:link
```

Ensure the web user can write to:

- `storage/`
- `bootstrap/cache/`

## PDF Rendering

Install Chrome/Chromium and keep `BROWSERSHOT_CHROME_PATH` accurate. Invoice PDFs rely on Browsershot for correct Khmer shaping and use Dompdf only as a fallback.

## Docker

The whole stack — app, web, MySQL, Redis, **and the two processes that are easy
to forget when deploying by hand** (`queue`, `scheduler`) — is defined in
`compose.yaml`. The image bakes in the three per-machine setup steps that
otherwise fail silently: OPcache + `pdo_mysql`, Chromium/Node/puppeteer for
Browsershot, and Khmer system fonts.

```bash
cp .env.docker.example .env.docker          # then fill in the secrets
docker compose run --rm app php artisan key:generate --show   # paste into APP_KEY
docker compose build
docker compose up -d
```

The app is served on `http://localhost:8080`. Put TLS on a reverse proxy in
front of it and set `TRUSTED_PROXIES` to that proxy's address — otherwise the
login rate limiter keys on the proxy's IP for every client, and one attacker
locks out everybody.

`.env.docker` is gitignored; `.env.docker.example` is the tracked template.

On boot the `app` container waits for MySQL, runs `migrate --force`, and warms
the config/route/view/icon/Filament caches. Caching config here is safe (and
wanted) precisely because the image is immutable and `.env` is injected at
start — the stale-`.env` foot-gun that makes it a bad idea during local dev
does not apply. Only `app` does this; `queue` and `scheduler` set
`RENTWISE_ROLE=worker` so they do not race it.

### Node version is load-bearing

The image installs **Node 24 from the official image, not Debian's `nodejs`
(18)**. puppeteer v25 is ESM-only while Browsershot's `bin/browser.cjs` calls
`require('puppeteer')`, so on Node 18 every render dies with
`ERR_REQUIRE_ESM` — which Browsershot swallows into a log warning and a
dompdf fallback that cannot shape Khmer. Do not "simplify" that COPY back to
`apt-get install nodejs`.

Verify a render after any change to the image:

```bash
docker compose exec app php -r '
require "/var/www/html/vendor/autoload.php";
$pdf = \Spatie\Browsershot\Browsershot::html("<h1>អគ្គិសនី</h1>")
    ->setChromePath(getenv("BROWSERSHOT_CHROME_PATH"))
    ->setNodeBinary(getenv("BROWSERSHOT_NODE_BINARY"))
    ->setNodeModulePath(getenv("BROWSERSHOT_NODE_MODULE_PATH"))
    ->noSandbox()->pdf();
file_put_contents("/tmp/khmer.pdf", $pdf);'
docker compose cp app:/tmp/khmer.pdf . && pdffonts khmer.pdf   # expect KhmerOS embedded
```

A PDF being produced is not proof: dompdf produces one too. `pdffonts` showing
an embedded `KhmerOS` face is.

### Persistence

Named volumes: `mysql`, `redis` (sessions live in db 2 — without AOF a restart
signs every landlord out), `storage` (uploads and generated exports) and
`logs`. `public/` is deliberately **copied into the nginx image** rather than
shared through a volume, so a rebuild can never serve the previous build's
`manifest.json`.

The native dev setup (`PHP_CLI_SERVER_WORKERS=6 /usr/bin/php8.3 artisan serve`)
is unaffected by any of this — nothing in the compose stack touches the host's
MySQL or Redis.
