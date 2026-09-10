# syntax=docker/dockerfile:1
#
# RentWise production image.
#
# Bakes in the three things that are currently per-machine setup steps and fail
# silently when missing (see CLAUDE.md "Known pitfalls"):
#   1. a PHP with OPcache AND pdo_mysql — the herd-lite php on the dev host has
#      neither, which is why dev requests take ~300ms;
#   2. Chromium + node + puppeteer for Browsershot — without them invoice PDFs
#      fall back to dompdf, which cannot shape Khmer script, with only a
#      warning in the log;
#   3. Khmer system fonts, so Chrome can render the script even if a template
#      ever stops inlining its own @font-face.
#
# Build:  docker compose build
# Target: `app` (default). The assets stage is build-only.

# ---------------------------------------------------------------------------
# Stage 1 — Front-end assets (Vite + Tailwind 4)
#
# Only the welcome/auth pages go through Vite. The Filament panels load their
# own precompiled CSS and the tenant portal uses the Tailwind CDN, so neither
# depends on this stage — but public/build must exist or @vite() throws.
# ---------------------------------------------------------------------------
FROM node:24-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json* ./

# Chrome comes from the distro in the app stage; puppeteer must not pull its
# own ~150MB copy here.
ENV PUPPETEER_SKIP_DOWNLOAD=true

RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public

RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2 — Runtime
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-bookworm AS app

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1

# - chromium ............ Browsershot's renderer (the dompdf fallback mangles Khmer)
# - fonts-khmeros ....... Khmer glyphs for Chrome
# - libpng/jpeg/freetype  gd, for spatie/laravel-medialibrary
# - libzip/libicu ....... zip + intl
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        chromium \
        curl \
        fonts-khmeros \
        fonts-noto-core \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        unzip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip

WORKDIR /var/www/html

# Node 24 from the official image, NOT Debian's nodejs (18). puppeteer v25 is
# ESM-only while Browsershot's bin/browser.cjs does `require('puppeteer')`, so
# on Node 18 every render dies with ERR_REQUIRE_ESM — and Browsershot swallows
# that into a warning plus a dompdf fallback that cannot shape Khmer. Node 22+
# supports require(esm). Verified in-container after build.
COPY --from=node:24-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

# Browsershot's node script needs the puppeteer module, but not its bundled
# Chrome — PUPPETEER_SKIP_DOWNLOAD keeps the image small and pins us to the
# distro chromium that the env vars below point at.
ENV PUPPETEER_SKIP_DOWNLOAD=true
RUN npm install --no-save --omit=dev puppeteer@^25 \
    && npm cache clean --force

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-rentwise.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependencies install HERE, not in a slimmer composer-only stage: this app
# requires ext-intl and ext-exif, which the composer image does not ship, and
# `--ignore-platform-reqs` would just hide a genuinely missing extension.
# composer.json/lock are copied first so a source-only edit reuses this layer.
#
# --no-scripts/--no-autoloader: artisan isn't present yet.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

COPY . .
COPY --from=assets /app/public/build ./public/build

# Autoloader + package discovery need the full source tree, so they run here
# rather than in the vendor stage.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi

# php-fpm runs as www-data; it must own what it writes.
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/exports \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Consumed by config/services.php's browsershot block.
ENV BROWSERSHOT_CHROME_PATH=/usr/bin/chromium \
    BROWSERSHOT_NODE_BINARY=/usr/local/bin/node \
    BROWSERSHOT_NPM_BINARY=/usr/local/bin/npm \
    BROWSERSHOT_NODE_MODULE_PATH=/var/www/html/node_modules

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Stage 3 — Web server
#
# nginx has to serve public/ itself. Copying it into a dedicated image (rather
# than sharing a named volume with the app container) means a rebuild always
# ships matching assets — a volume would keep serving the first build's
# public/build/manifest.json forever.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
