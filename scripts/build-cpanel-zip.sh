#!/usr/bin/env bash
#
# scripts/build-cpanel-zip.sh
#
# Build a deployable zip of this Symfony backoffice for cPanel shared
# hosting. The output (<project-dir-name>-cpanel.zip — the name tracks
# whatever directory this repo lives in, so a downstream fork gets a
# correctly-named zip with no edits) extracts at the document root of
# /home/<user>/html_public/ (or the subdomain's document root, whichever
# cPanel uses for your addon/subdomain).
#
# Constraints baked into this build:
#   - cPanel document root is FIXED at html_public/ — we cannot ask it
#     to point at public/. So the build restructures the Symfony public/
#     layout by moving every entry under public/ up one level, and
#     patching index.php's vendor/ autoload reference accordingly.
#   - No SSH on the server — the zip is self-contained and includes a
#     one-shot HTTP installer (deploy.php at the doc root) that runs
#     migrations, cache warmup, and seeds an initial backoffice admin,
#     gated behind a DEPLOY_KEY the operator embeds in .env.local.
#   - FTP only — there is no .git, no node_modules, no composer on the
#     server. Everything that would normally be a CLI step must happen
#     here, on the dev machine, before zipping.
#
# Usage:
#     scripts/build-cpanel-zip.sh
#
# Outputs:
#     dist/                          build staging area
#     <project-dir-name>-cpanel.zip  the deliverable
#
# Requires:
#     - bash, rsync, zip
#     - PHP CLI (for vendor/ re-install with --no-dev)
#     - npm (for the Vite build)
#     - Optional: docker compose for the canonical project workflow.
#       Without docker, set HOST_PHP_BIN to a PHP 8.4+ CLI on PATH.
#
# Idempotent: re-running starts from a clean dist/.

set -euo pipefail

# Resolve project root regardless of where this script is invoked from.
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
cd "${PROJECT_ROOT}"

DIST="${PROJECT_ROOT}/dist"
PROJECT_SLUG="$(basename "${PROJECT_ROOT}")"
ZIP_PATH="${PROJECT_ROOT}/${PROJECT_SLUG}-cpanel.zip"

# ---------------------------------------------------------------------------
# 1. Sanity checks
# ---------------------------------------------------------------------------

if ! command -v rsync >/dev/null 2>&1; then
    echo "ERROR: rsync is required. Install it and retry." >&2
    exit 1
fi
if ! command -v zip >/dev/null 2>&1; then
    echo "ERROR: zip is required. Install it and retry." >&2
    exit 1
fi

# We need PHP for `composer install` and `bin/console cache:*`. Prefer the
# host CLI (so we don't pay the docker volume round-trip), but fall back
# to the project's `php` container via docker compose.
HOST_PHP_BIN="${HOST_PHP_BIN:-}"
if [ -z "${HOST_PHP_BIN}" ] || ! command -v "${HOST_PHP_BIN}" >/dev/null 2>&1; then
    if command -v php >/dev/null 2>&1; then
        HOST_PHP_BIN="php"
    elif command -v docker >/dev/null 2>&1 && [ -f "${PROJECT_ROOT}/docker-compose.yml" ]; then
        HOST_PHP_BIN="docker-compose-exec"
    else
        echo "ERROR: no PHP CLI available. Set HOST_PHP_BIN or install php 8.4+." >&2
        exit 1
    fi
fi

echo "==> Using PHP: ${HOST_PHP_BIN}"

# Helper: run a PHP command either as the host binary or via docker exec.
run_php() {
    if [ "${HOST_PHP_BIN}" = "docker-compose-exec" ]; then
        docker compose -f "${PROJECT_ROOT}/docker-compose.yml" exec -T php "$@"
    else
        "${HOST_PHP_BIN}" "$@"
    fi
}

# ---------------------------------------------------------------------------
# 2. Build the frontend assets via npm
# ---------------------------------------------------------------------------

if [ ! -d "${PROJECT_ROOT}/node_modules" ]; then
    echo "==> Installing npm dependencies (npm ci)"
    npm ci
fi

echo "==> Building Vite production assets"
npm run build

# ---------------------------------------------------------------------------
# 3. Refresh vendor/ in a temporary staging tree with --no-dev
# ---------------------------------------------------------------------------
#
# Rationale: the source vendor/ has dev bundles (maker, web-profiler,
# doctrine-fixtures) that we don't want to ship. Re-installing --no-dev
# in-place would corrupt the dev workflow, so we do it in dist/.

echo "==> Cleaning dist/ and creating skeleton"
rm -rf "${DIST}"
mkdir -p "${DIST}"

# Use rsync to copy everything except the explicitly-excluded artefacts.
# We deliberately exclude the entire public/ directory — its contents are
# re-copied in a separate, explicit step below so we can lift them up to
# the zip root in a controlled way.
echo "==> Copying source tree (excluding dev/host artefacts)"
rsync -a \
    --exclude='/.git/' \
    --exclude='/.gitignore' \
    --exclude='/.editorconfig' \
    --exclude='/.env' \
    --exclude='/.env.dev' \
    --exclude='/.env.test' \
    --exclude='/.env.local' \
    --exclude='/.env.*.local' \
    --exclude='/.env.local.php' \
    --exclude='/.idea/' \
    --exclude='/.vscode/' \
    --exclude='/.opencode/' \
    --exclude='/.claude/' \
    --exclude='/.aider*' \
    --exclude='/.cursor/' \
    --exclude='/.continue/' \
    --exclude='/.test-deploy/' \
    --exclude='/td/' \
    --exclude='/.dockerignore' \
    --exclude='/docker/' \
    --exclude='/apache/' \
    --exclude='/Dockerfile' \
    --exclude='/docker-compose.yml' \
    --exclude='/compose.override.yaml' \
    --exclude='/compose.yaml' \
    --exclude='/node_modules/' \
    --exclude='/package.json' \
    --exclude='/package-lock.json' \
    --exclude='/tailwind.config.js' \
    --exclude='/postcss.config.js' \
    --exclude='/vite.config.js' \
    --exclude='/assets/' \
    --exclude='/vendor/' \
    --exclude='/var/' \
    --exclude='/dist/' \
    --exclude='/public/bundles/' \
    --exclude='/tests/' \
    --exclude='/phpunit.xml' \
    --exclude='/phpunit.xml.dist' \
    --exclude='*.zip' \
    --exclude='*.sqlite*' \
    --exclude='/README.md' \
    --exclude='/AGENTS.md' \
    --exclude='/BRANCHING.md' \
    --exclude='/INFRASTRUCTURE_PLAN.md' \
    --exclude='/README_DEPLOY.md' \
    --exclude='/.env.local.template' \
    --exclude='/scripts/' \
    "${PROJECT_ROOT}/" "${DIST}/"

# Pull only the front-controller bits out of public/ and into the zip
# root. We deliberately LEAVE the rest of public/ in place (build/,
# uploads/, favicon.svg) — the top-level .htaccess aliases /build/ and
# /uploads/ to their public/ paths. (Vite bundle and asset
# mapper both default to a public/ base, so keeping that structure
# intact avoids any code change to Symfony's URL generation.)
#
# Concretely: we drop public/index.php and public/.htaccess from dist/
# (we replace them with patched versions at the zip root) and keep
# public/build, public/uploads, and public/favicon.svg.
echo "==> Removing dist/public/index.php and dist/public/.htaccess"
rm -f "${DIST}/public/index.php"
rm -f "${DIST}/public/.htaccess"

# bin/deploy.php from the source tree is not shipped — the installer
# lives at the doc root (/deploy.php) because WNPower-class cPanel/nginX
# hosts have WAF rules that 403 any /bin/* path. Keep the source under
# bin/ for dev (where it can be run from bin/console), but exclude it
# from the deployable zip.
rm -f "${DIST}/bin/deploy.php"

# Patch the moved index.php: in the source layout it lives at public/index.php
# and uses dirname(__DIR__).'/vendor/...' to find vendor/. In the deploy
# layout it lives at the project root next to vendor/, so the reference
# becomes __DIR__.'/vendor/...'.
echo "==> Writing patched index.php at zip root"
cat > "${DIST}/index.php" <<'PHP'
<?php

use App\Kernel;

require_once __DIR__.'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
PHP

# Write the production-hardened top-level .htaccess.
#
# The cPanel document root is fixed at the project root, so this file is
# what Apache reads first. It aliases the static assets that the Vite
# bundle and upload URLs expect under /build/, /uploads/, and /favicon.svg — these live under
# public/ in this layout because Vite's manifest defaults to a public/
# base. The alias below redirects /build/ → public/build/ so application
# code emits canonical URLs and Apache serves the physical files.
#
# Hardening layered on top of Symfony's stock rules:
#
#   1. Block direct access to files with sensitive extensions
#      (.env, .yaml, .twig, .md, etc.).
#   2. Block direct access to any .php file other than index.php and
#      deploy.php at the root.
#   3. Block direct hits to /public/, /src/, /vendor/, /var/, /config/,
#      /migrations/, /translations/, /templates/ in URLs (defence in
#      depth — catches static YAML / Twig / .md files).
echo "==> Writing production-hardened top-level .htaccess"
cat > "${DIST}/.htaccess" <<'HTACCESS'
# Backoffice — production top-level .htaccess.
#
# This file lives at the cPanel document root (/home/<user>/html_public/).
# In the source repo an .htaccess lives at public/.htaccess; we lifted
# the rewrite rules up here because the cPanel doc root is fixed and we
# cannot point it at public/.
#
# The dist layout keeps public/build/, public/uploads/, and public/favicon.svg
# intact and uses Apache rewrite aliases below to resolve their canonical URLs.

Options -Indexes +FollowSymLinks +MultiViews
RewriteEngine On
DirectoryIndex index.php

# 1. Alias /build/, /uploads/, and /favicon.svg to public/. The L flag stops
#    processing here so these serve as static files without recursing
#    into the front-controller rule below.
RewriteRule ^build/(.*)$ public/build/$1 [L]
RewriteRule ^uploads/(.*)$ public/uploads/$1 [L]
RewriteRule ^favicon\.svg$ public/favicon.svg [L]
RewriteRule ^sw\.js$ public/sw.js [L]
RewriteRule ^skeleton-logo\.png$ public/skeleton-logo.png [L]

<IfModule mod_headers.c>
    <Files "sw.js">
        Header set Cache-Control "no-cache"
    </Files>
</IfModule>

# 2. Real files served as-is. We deliberately do NOT match directories
#    here — matching `-d` on the doc root would short-circuit rule 7
#    and Apache would refuse to serve the directory listing (with
#    `Options -Indexes` that's a 403, not the index.php we'd want).
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ - [L]

# 3. Block direct access to sensitive file extensions.
RewriteCond %{REQUEST_URI} \.(env|env\.local|json|lock|yaml|yml|md|twig|dist|map|gitignore|sql|sqlite.*)$ [NC]
RewriteRule ^ - [F,L]

# 4. Block direct access to any .php file other than the front
#    controller and the deploy installer. Each block that matches a
#    given file grants it; the catch-all <FilesMatch> at the bottom
#    denies everything else. Order matters: Apache's "last match
#    wins" rule means the catch-all deny must come FIRST so the
#    explicit grants can override it.
<FilesMatch "\.php$">
    Require all denied
</FilesMatch>
<Files "index.php">
    Require all granted
</Files>
<Files "deploy.php">
    Require all granted
</Files>

# 5. Block direct hits to private directories by URL pattern. Defence
#    in depth — the FilesMatch above already blocks .php inside these
#    directories, but explicit denials also catch static YAML / Twig /
#    .md files served from there. /bin/ is intentionally NOT in this
#    list because the deploy installer lives there.
RewriteRule ^(src|vendor|var|config|migrations|translations|templates)(/|$) - [F,L]

# 6. Hardening headers.
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
    Header set X-XSS-Protection "1; mode=block"
    # HSTS is intentionally disabled — re-enable once AutoSSL is in place.
    # Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
</IfModule>

# 7. Front controller fallback for everything else. Note: directories
#    (not matched by rule 2's `-f` check) hit this rule, get rewritten
#    to /index.php, which Apache then resolves — combined with
#    DirectoryIndex, requests for / and /admin/ etc. land on the
#    front controller.
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]

# php -- BEGIN cPanel-generated handler, do not edit
# Set the "ea-php84" package as the default "PHP" programming language.
<IfModule mime_module>
    AddHandler application/x-httpd-ea-php84___lsphp .php .php8 .phtml
</IfModule>
# php -- END cPanel-generated handler, do not edit
HTACCESS

# Drop an empty sentinel at public/.htaccess so future deploys that
# somehow include it don't shadow our top-level rules.
echo "" > "${DIST}/public/.htaccess"

# ---------------------------------------------------------------------------
# 4. Composer install --no-dev --optimize-autoloader inside dist/
# ---------------------------------------------------------------------------

echo "==> Running composer install --no-dev --optimize-autoloader (in dist/)"
# Run composer inside dist/, NOT in the source tree, so the dev vendor/
# stays intact for ongoing local development. We use the composer sidecar
# (mounting dist/ via an override) when running via docker, or the host
# composer binary if available.
if [ "${HOST_PHP_BIN}" = "docker-compose-exec" ]; then
    # Generate a temporary compose override that mounts dist/ at /app so
    # composer sees only the deploy tree (and writes vendor/ there).
    COMPOSER_OVERRIDE="$(mktemp)"
    cat > "${COMPOSER_OVERRIDE}" <<EOF
services:
  composer:
    volumes:
      - "${DIST}:/app"
    working_dir: /app
EOF
    docker compose -f "${PROJECT_ROOT}/docker-compose.yml" -f "${COMPOSER_OVERRIDE}" run --rm --user "$(id -u):$(id -g)" composer \
        install \
        --no-dev \
        --optimize-autoloader \
        --no-scripts \
        --no-interaction \
        --no-progress
    rm -f "${COMPOSER_OVERRIDE}"
else
    (cd "${DIST}" && \
        composer install \
            --no-dev \
            --optimize-autoloader \
            --no-scripts \
            --no-interaction \
            --no-progress)
fi

# ---------------------------------------------------------------------------
# 5. Drop in deploy-time artefacts (templates, README, the patched deploy.php)
# ---------------------------------------------------------------------------

echo "==> Copying deploy-time artefacts"
cp "${PROJECT_ROOT}/.env.local.template"   "${DIST}/.env.local.template"
cp "${PROJECT_ROOT}/README_DEPLOY.md"      "${DIST}/README_DEPLOY.md"

# The HTTP installer lives at the doc root, NOT under bin/. WNPower-class
# cPanel/nginX hosts have WAF rules that 403 any /bin/* path with their
# own branded error page, so bin/deploy.php is unreachable there. nginx
# also doesn't read .htaccess, so we can't whitelist /bin/ with the
# shipped file. Putting deploy.php at /deploy.php sidesteps both: nginx
# passes it to PHP-FPM, and /deploy.php is not in any host's default
# block list.
cp "${PROJECT_ROOT}/bin/deploy.php" "${DIST}/deploy.php"
chmod 0644 "${DIST}/deploy.php"

# Generate a fresh prod-leaning .env. We DO NOT copy the source .env
# because it points at the dev database. The shipped .env is intentionally
# safe-by-default: invalid creds + APP_SECRET blank + null mailer, so an
# operator who forgets to fill in .env.local gets loud failures rather
# than silently doing nothing.
cat > "${DIST}/.env" <<'ENV'
# Backoffice — production defaults.
#
# IMPORTANT: every value here is a deliberate no-op. Real credentials
# live in .env.local (gitignored). If .env.local is missing, the app
# will fail to boot — that is intentional.

APP_ENV=prod
APP_DEBUG=0
APP_SECRET=

DEFAULT_URI=https://CHANGE_ME_DOMAIN

# Doctrine — fails closed. Replace in .env.local with the real cPanel DSN.
DATABASE_URL="mysql://CHANGE_ME_DB_USER:CHANGE_ME_DB_PASSWORD@127.0.0.1:3306/CHANGE_ME_DB?serverVersion=10.11.18-MariaDB-cll-lve-log&charset=utf8mb4"

# Mailer — null transport. The backoffice's confirmation emails will
# be silently dropped until .env.local supplies a real DSN.
MAILER_DSN=null://null
MAILER_FROM=no-reply@CHANGE_ME_DOMAIN
MAILER_FROM_NAME="CHANGE_ME_APP_NAME"

# Public base URL — used in confirmation emails (admin account
# confirmation links). Replace in .env.local.
APP_URL=https://CHANGE_ME_DOMAIN

# Push delivery is opt-in; real, persistent VAPID values belong in .env.local.
NOTIFICATION_PUSH_ENABLED=0
NOTIFICATION_VAPID_PUBLIC_KEY=
NOTIFICATION_VAPID_PRIVATE_KEY=
NOTIFICATION_VAPID_SUBJECT=mailto:no-reply@CHANGE_ME_DOMAIN
ENV

# Stamp a unique deploy key. Operators MUST replace this in .env.local
# with their own value — the one we ship here is just a placeholder so
# the file at least parses cleanly if someone forgets.
DEPLOY_KEY_GENERATED=$(php -r 'echo bin2hex(random_bytes(16));' 2>/dev/null || openssl rand -hex 16)
echo
echo "==================================================================="
echo "Generated DEPLOY_KEY (placeholder): ${DEPLOY_KEY_GENERATED}"
echo "Operators MUST replace this in .env.local with their own random"
echo "string before running the installer. The installer refuses access"
echo "unless ?key=<DEPLOY_KEY> matches the .env.local value exactly."
echo "==================================================================="
echo

# bin/console stays as-is (the source copy). deploy.php lives at the
# doc root, not in bin/, so nginx-based cPanel hosts can reach it.
chmod +x "${DIST}/bin/console" 2>/dev/null || true

# ---------------------------------------------------------------------------
# 6. Final sweep — drop var/ cache (rebuilt by deploy.php on server)
# ---------------------------------------------------------------------------

echo "==> Cleaning var/ — cache is rebuilt by deploy.php"
rm -rf "${DIST}/var/cache" "${DIST}/var/log"
mkdir -p "${DIST}/var/cache" "${DIST}/var/log"

# ---------------------------------------------------------------------------
# 7. Build the zip
# ---------------------------------------------------------------------------

echo "==> Building zip: ${ZIP_PATH}"
rm -f "${ZIP_PATH}"

# Use zip -r to recurse. -X strips extra attributes (UID/GID); useful for
# cPanel which sometimes chokes on long-running inode metadata.
(cd "${DIST}" && zip -r -X -q "${ZIP_PATH}" .)

echo
echo "=== Done ==="
echo "  Zip:    ${ZIP_PATH}"
echo "  Size:   $(du -h "${ZIP_PATH}" | cut -f1)"
echo "  Files:  $(unzip -l "${ZIP_PATH}" | tail -1 | awk '{print $2}')"
echo
echo "Next steps:"
echo "  1. Upload ${ZIP_PATH} to /home/<user>/html_public/ via cPanel File Manager."
echo "  2. Right-click → Extract in place (no nested directory)."
echo "  3. Copy .env.local.template to .env.local and fill in real values."
echo "  4. Pick a strong DEPLOY_KEY (e.g. openssl rand -hex 32) and paste it in .env.local."
echo "  5. Set PHP 8.4 for this folder in MultiPHP Manager."
echo "  6. Visit https://yourdomain.tld/deploy.php?key=<DEPLOY_KEY>"
echo "  7. The installer runs migrations, warms the cache, seeds ROLE_ADMIN and ROLE_USER,"
echo "     creates your initial backoffice admin, and self-deletes deploy.php."
echo
echo "Full walkthrough is in README_DEPLOY.md inside the zip."
