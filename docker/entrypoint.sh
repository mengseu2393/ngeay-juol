#!/bin/sh
set -e

# Shared entrypoint for the app, queue and scheduler containers.
#
# Only the `app` container runs migrations and warms caches — the queue and
# scheduler start from the same image and would otherwise race it on boot.
# Set RENTWISE_ROLE=app on exactly one service.

role="${RENTWISE_ROLE:-app}"

wait_for_db() {
    echo "[entrypoint] waiting for the database…"
    for _ in $(seq 1 60); do
        if php -r '
            $host = getenv("DB_HOST") ?: "mysql";
            $port = getenv("DB_PORT") ?: "3306";
            exit(@fsockopen($host, (int) $port, $e, $s, 1) ? 0 : 1);
        '; then
            echo "[entrypoint] database is up"
            return 0
        fi
        sleep 2
    done

    echo "[entrypoint] database did not become reachable in time" >&2
    exit 1
}

wait_for_db

if [ "$role" = "app" ]; then
    php artisan migrate --force

    # storage:link is idempotent-ish but fails loudly if the link exists.
    php artisan storage:link 2>/dev/null || true

    # Config/route/view caches are correct here precisely because the image is
    # immutable and .env is injected at start — the stale-.env foot-gun that
    # makes these a bad idea during local dev does not apply.
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan icons:cache
    php artisan filament:cache-components
else
    # Workers still need the caches, but must not build them concurrently with
    # the app container; they only read what the shared image already has.
    php artisan config:clear >/dev/null 2>&1 || true
fi

exec "$@"
