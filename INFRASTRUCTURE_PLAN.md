# Symfony Backoffice Skeleton — Infrastructure Plan

> The current state of the skeleton. "Why" questions should consult this
> file. "How" questions should consult `README.md` and `AGENTS.md`.

## Stack (confirmed)

- **App layout:** Symfony at repo root (`/home/aku/dev/symfony-backoffice-skeleton`)
- **PHP 8.4 / Symfony 7.4 / Twig / Maker / Profiler / symfony/apache-pack**
- **Doctrine ORM** (`symfony/orm-pack`) — adds `doctrine/orm`,
  `doctrine-bundle`, `doctrine-migrations-bundle`
- **MariaDB 10.11** in Docker (chosen for parity with the cPanel
  shared-hosting MariaDB used by the downstream projects)
- **pentatrion/vite-bundle 8.x** + **Vite 5** + **Tailwind 3.4** + **PostCSS 8** + **Autoprefixer 10**
- **Composer via `composer:2` service in Docker** (no host PHP)
- **Apache port: 8000** (single container, no `php-fpm` + `nginx`)

## Container shape

Three services in `docker-compose.yml`:

- `php` — `php:8.4-apache`. Image is rebuilt whenever the `Dockerfile`
  changes. Mounts `./` to `/var/www/html`. Exposes `8000:80`.
- `database` — `mariadb:10.11`. Healthcheck on `healthcheck.sh
  --connect --innodb_initialized`. Loopback-mapped to `127.0.0.1:33067`
  from the host (`compose.override.yaml`) so a host MariaDB on `:3306`
  won't collide.
- `composer` — `composer:2`. `profiles: ['tools']`, never auto-starts.
  Runs as `${UID:-1000}:${GID:-1000}` so generated `vendor/` files are
  owned by the host user.
- `mailer` — `axllent/mailpit`. Defined in `compose.override.yaml` so
  the prod compose file doesn't carry it. Web UI on
  `http://localhost:8025`, SMTP on `mailer:1025`.

## Why MariaDB

The downstream projects both deploy to cPanel shared hosting, which
exposes MariaDB 10.11 (`MariaDB1010Platform`). Running the same version
in dev means Doctrine migrations, query syntax, and platform-specific
behaviour (AUTO_INCREMENT, `TIMESTAMP(0)`, integer types) match
exactly. PostgreSQL was the original target (visible in the
`4f9d474` migration SQL); the conversion to MariaDB happened in the
`web-nova-argentum` history and is folded into the skeleton here.

## Why no `vendor/` named volume

Earlier iterations tested `vendor:`, `var:`, and `node_modules:` as
named volumes to avoid the host/container UID mismatch. The chosen
approach is:

- **Bind-mount `./` to `/var/www/html`** for the entire project. This
  means source code edits on the host are visible inside the container
  immediately (no rebuild).
- **`composer` service runs as host UID** so `vendor/` is owned by the
  host user. No root-owned-volume problem.
- **`var/` is bind-mounted** (because it's already under `./`). The
  container can write to it; the host sees the cache and log files.
- **`node_modules/` is bind-mounted** alongside the project. `npm run
  build` runs on the host (since `npm run dev` is also on the host for
  HMR); the container only serves the already-built `public/build/`
  files.

## Why `php:8.4-apache` (single container)

The cPanel shared-hosting production target runs Apache + mod_php. To
match dev/prod behaviour, the docker container also runs Apache + mod_php.
A `php-fpm` + `nginx` setup would be more performant but would require
keeping the prod nginx config in sync manually, which is a footgun for
a small project.

The `php:8.4-apache` image is pinned to 8.4 because `doctrine/doctrine-bundle`
3.2 and `doctrine-migrations-bundle` 4.0 require PHP 8.4. Don't downgrade
those packages.

## Why Vite 5

Vite 5 is the last Vite line that supports Node 18 (the host's installed
Node). Vite 6+ requires Node 18.18+ but is increasingly Node 20+ focused;
Vite 8 requires Node 20.19+. The host is Node 18.19, so Vite 5 is the
correct pinning. Don't bump Vite past 5 without also upgrading Node.

The `pentatrion/vite-bundle` extension is the Symfony integration. Its
config alias is `pentatrion_vite:`, not `vite:` — using `vite:` will fail
with "no extension able to load the configuration for 'vite'".

## Why no Symfony UX

The skeleton ships only what is needed for the backoffice. Adding
`symfony/ux-twig-component`, `symfony/ux-translator`, or similar UX
packages is the call of downstream projects when they need a
campaign-specific homepage. The skeleton keeps the surface area small.

## Why the `app` translation domain is empty

The skeleton ships without a public homepage. The `app` translation
domain (`translations/app/messages.<locale>.yaml`) is reserved for
downstream projects that ship a public homepage (e.g.
`web-nova-argentum` uses it for the institutional landing copy). In
the skeleton, the `app` domain is intentionally absent — see
`AGENTS.md` §"Translation catalogs".

## Why the redirect at `/`

The skeleton's `HomeController` redirects `/` to `/login`. This makes
the "no public homepage" intent explicit and gives unauthenticated
visitors somewhere useful to land. Downstream projects that want a
public homepage override `HomeController` and add a
`templates/home/` directory.

## Files that the skeleton ships

| Path | Purpose |
|---|---|
| `Dockerfile` | `php:8.4-apache` + intl/opcache/pdo_mysql/zip + vhost |
| `docker-compose.yml` | php / database / composer services |
| `compose.override.yaml` | Dev-only override (DB port + Mailpit) |
| `composer.json` / `composer.lock` / `symfony.lock` | PHP deps |
| `bin/console` | Symfony CLI |
| `config/` (bundles, packages, routes, services) | Symfony config |
| `apache/skeleton.conf` | DocumentRoot → `public/`, AllowOverride All |
| `docker/php/{opcache,apcu}.ini` | PHP runtime tuning |
| `src/` | App code (modular: Account, Administration, Backoffice, Security, Shared) |
| `templates/` | Twig templates (mirrors the module split) |
| `translations/` | Per-module subfolder translations (en + es) |
| `migrations/` | Doctrine migrations (initial schema + permission seed + state machine + locale) |
| `assets/` | Vite inputs (`app.js` + `styles/app.css`) |
| `vite.config.js` / `tailwind.config.js` / `postcss.config.js` | Frontend pipeline |
| `bin/deploy.php` / `scripts/build-cpanel-zip.sh` / `README_DEPLOY.md` / `src/Command/Deploy/SeedProdCommand.php` | cPanel/FTP deploy tooling |
| `BRANCHING.md` | Downstream tag-bounded cherry-pick sync workflow |
| `AGENTS.md` / `README.md` / `INFRASTRUCTURE_PLAN.md` | Documentation |

## Migration ordering

The skeleton's migrations are ordered by ID and apply cleanly to a fresh
MariaDB 10.11 database:

1. `Version20260619042333` — initial schema (permission, role, app_user,
   join tables). MariaDB-flavoured.
2. `Version20260619042400` — seed the permission catalog from
   `App\Security\Permission`.
3. `Version20260625001659` — re-seed any new permission entries (idempotent).
4. `Version20260626000000` — `app_user.state` + `account_confirmation_token`
   table.
5. `Version20260626000100` — `app_user.password` nullable.
6. `Version20260701000000` — `app_user.locale` (nullable).

The PostgreSQL-specific `BIGSERIAL` and `INT GENERATED BY DEFAULT AS
IDENTITY` from the original snapshot have been rewritten to MariaDB's
`BIGINT AUTO_INCREMENT` and `INT AUTO_INCREMENT`. The `TIMESTAMP(0)
WITHOUT TIME ZONE` columns have been rewritten to `DATETIME` (MariaDB
flavour).

## What the skeleton does NOT ship

- **Public homepage.** Downstream projects add their own.
- **Contact form / mailer.** Downstream projects that need a contact
  form add `ContactController`, `ContactFormType`, `ContactMailer`, and
  a `templates/contact/` directory.
- **Domain entities** (catalog, inventory, production, sales, listings,
  leads, etc.).
  These are added per downstream project.
- **Custom markdown / docs renderer.** Only the `README.md`,
  `AGENTS.md`, `BRANCHING.md`, `INFRASTRUCTURE_PLAN.md`, and
  `README_DEPLOY.md` files are shipped.
- **OAuth / SSO.** Symfon's form login is the only auth path. Adding
  OAuth is a downstream project's call.
- **Tests.** The skeleton ships `tests/` from Symfony Flex but no test
  code. Downstream projects add coverage as needed.
- **CI configuration** (`.github/workflows/`). The skeleton does not
  carry GitHub Actions files. Downstream projects add their own.
