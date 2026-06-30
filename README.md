# Nova Argentum

> Institutional webpage for a land development and brokerage business.

[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4)](https://www.php.net)
[![Symfony](https://img.shields.io/badge/Symfony-7.4-000000)](https://symfony.com)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791)](https://www.postgresql.org)
[![Vite](https://img.shields.io/badge/Vite-5-646CFF)](https://vitejs.dev)
[![Tailwind](https://img.shields.io/badge/Tailwind-3.4-06B6D4)](https://tailwindcss.com)
[![Apache](https://img.shields.io/badge/Apache-2.4-D22128)](https://httpd.apache.org)

This repository is the home of Nova Argentum's institutional web presence — a
Symfony-based site for a land development and brokerage business. It ships as a
**forkable skeleton**: the stack, infrastructure, build pipeline, and design
system are all in place so that future projects can fork this commit, replace
branding, and start adding pages.

---

## Table of contents

1. [Overview](#1-overview)
2. [Architecture](#2-architecture)
3. [Quick start](#3-quick-start)
4. [Project structure](#4-project-structure)
5. [Daily development workflow](#5-daily-development-workflow)
6. [Frontend pipeline](#6-frontend-pipeline)
7. [Database workflow](#7-database-workflow)
8. [Design system](#8-design-system)
9. [Infrastructure reference](#9-infrastructure-reference)
10. [Common tasks](#10-common-tasks)
11. [Authentication and backoffice](#11-authentication-and-backoffice)
12. [RBAC and the permission catalog](#12-rbac-and-the-permission-catalog)
13. [The shared Twig CRUD base](#13-the-shared-twig-crud-base)
14. [Troubleshooting](#14-troubleshooting)
15. [Command cheat sheet](#15-command-cheat-sheet)
16. [License](#16-license)

---

## 1. Overview

Nova Argentum is an institutional website for a real-estate development and
brokerage firm. The codebase is structured as a single Symfony application
serving both content and (eventually) interactive tools.

**Stack:**

- **Backend:** Symfony 7.4 LTS on PHP 8.4, Doctrine ORM 3.6, Twig 3
- **Auth & forms:** Symfony Security 7.4 (form login + stateless CSRF +
  login throttling), Symfony Form 7.4, Symfony Validator 7.4
- **Web server:** Apache 2.4 (in a single `php:8.4-apache` container — no
  separate `php-fpm` + `nginx` orchestration to keep ops simple)
- **Database:** PostgreSQL 16 in Docker
- **Frontend:** Vite 5 with `vite-plugin-symfony`, Tailwind 3.4 for styling
- **Dev tooling:** `symfony/maker-bundle` for scaffolding, Doctrine Migrations
  for schema changes, Doctrine Fixtures for seed data, Web Profiler for debug
  introspection in dev

**Surfaces:**

- **Public** — the institutional site at `/` (placeholder content pending
  brand work).
- **Backoffice** — an authenticated admin area at `/admin`, gated by an
  RBAC system: `User` → `Role` → `Permission`. See
  [§11 Authentication and backoffice](#11-authentication-and-backoffice)
  and [§12 RBAC and the permission catalog](#12-rbac-and-the-permission-catalog).

**Why this stack:** the institutional content is small but the visual
identity and the asset pipeline matter a lot. Vite + Tailwind give us HMR in
dev and a tight production build without hand-rolling a CSS pipeline, while
keeping the server side boring and maintainable.

---

## 2. Architecture

```mermaid
flowchart LR
    Browser[Browser<br/>:8000 / :5173]

    subgraph dockerHost[Docker host]
        subgraph composeStack[docker compose stack]
            Apache[php container<br/>php:8.4-apache<br/>:8000 mapped → 80<br/>DocumentRoot /var/www/html/public]
            PG[(PostgreSQL 16<br/>container<br/>:5432)]
        end
        Composer[composer sidecar<br/>composer:2<br/>profile=tools<br/>runs as host UID]
        ViteHost[Vite dev server<br/>npm run dev on host<br/>:5173]
    end

    Browser -->|HTTP :8000| Apache
    Browser -.->|HMR/WS :5173| ViteHost
    ViteHost -.->|serves<br/>built assets| Apache
    Apache -->|PDO/PostgreSQL| PG
    Composer -.->|bind-mount ./| Apache

    classDef container fill:#1f2937,stroke:#94a3b8,color:#fff
    class Apache,PG,Composer,ViteHost container
```

**Request flow at a glance:**

- **Static asset request in dev** — Vite dev server (host :5173) is reached
  directly by the browser via HMR; the Symfony app's rendered HTML still
  points to local Vite URLs via `vite_entry_*_tags()`.
- **Static asset request in production** — `vite build` writes
  fingerprinted assets into `public/build/`, Apache serves them directly.
- **App request** — always hits Apache (:8000 → container :80), which routes
  through `public/index.php` (front controller).

---

## 3. Quick start

### Prerequisites

| Tool | Version | Notes |
|---|---|---|
| Docker | 29+ | Docker Desktop or `docker-ce` |
| Docker Compose | v2 (bundled) | `docker compose` (not `docker-compose`) |
| Node.js | 18.19+ | Vite 5 is the last Vite line that supports Node 18; Vite 8+ requires Node 20.19+ |
| npm | 9+ | Bundled with Node 18 |
| Git | any | |

**Nothing else.** No host PHP, no host Composer — everything runs in containers.

### First-time setup

```bash
git clone git@github.com:ortiz-facundo/web-nova-argentum.git
cd web-nova-argentum
docker compose up -d --build    # builds the php image, starts php + db
```

The first build takes ~3 minutes (it installs system libs, builds PHP
extensions, pulls Postgres). Subsequent starts are under 10 seconds.

### One-time local Node setup (only if you want to rebuild assets)

```bash
npm install
```

This populates `node_modules/` for Vite, Tailwind, and PostCSS. The Symfony
container doesn't need this — it just serves the already-built `public/build/`
files.

### Verify it works

```bash
# 1. The home page renders
curl -sI http://localhost:8000/ | head -1
# → HTTP/1.1 200 OK

# 2. Vite-built CSS contains the expected Tailwind utilities
curl -s http://localhost:8000/build/assets/app-*.css | grep -c 'bg-purple-900'
# → 1

# 3. Postgres is reachable from the php container
docker compose exec php php bin/console doctrine:database:create --if-not-exists
# → Database "app" for connection named default already exists. Skipped.
```

Open <http://localhost:8000> to see the Nova Argentum home page (a purple
Tailwind card on a slate background) — this is the smoke test that confirms
Apache routing, Twig rendering, Vite asset emission, and Tailwind compilation
are all working together.

### Verifying the backoffice

```bash
# 1. Login page renders
curl -sI http://localhost:8000/login | head -1
# → HTTP/1.1 200 OK

# 2. Unauthenticated /admin redirects to /login
curl -sI http://localhost:8000/admin | head -2
# → HTTP/1.1 302 Found
# → Location: http://localhost:8000/login
```

The test user is created by the first-time seed:

| Field | Value |
|---|---|
| Email | `test@nova-argentum.local` |
| Password | `test` |
| Role | `ROLE_ADMIN` (all four permissions) |

The fixtures are idempotent (use `doctrine:fixtures:load --append` to keep
existing rows).

---

## 4. Project structure

```
.
├── apache/
│   └── nova-argentum.conf        # Apache vhost: DocumentRoot → public/, AllowOverride All
├── assets/                        # Vite inputs (the only frontend source code)
│   ├── app.js                    # JS entry — imports styles/app.css
│   └── styles/
│       └── app.css               # CSS entry — @tailwind base/components/utilities
├── bin/
│   └── console                   # Symfony console entry point
├── compose.override.yaml         # Dev-only override (DB port: random host port)
├── composer.json / .lock         # PHP deps (canonical source for backend versions)
├── config/
│   ├── bundles.php               # Bundle registry
│   ├── packages/                 # Per-bundle configs (twig.yaml, doctrine.yaml, vite.yaml, …)
│   ├── routes/                   # Routing imports (controllers auto-discovered)
│   └── services.yaml             # Service container config
├── docker/
│   └── php/
│       ├── opcache.ini           # Recommended opcache settings for dev
│       └── apcu.ini              # APCu config (128 MB)
├── docker-compose.yml            # php / database / composer services
├── Dockerfile                    # php:8.4-apache + intl/opcache/pdo_pgsql/zip + vhost
├── migrations/                   # Doctrine migration files (committed)
├── public/                       # Web root
│   ├── index.php                 # Symfony front controller
│   ├── .htaccess                 # From symfony/apache-pack (URL rewriting, security headers)
│   └── build/                    # Generated by vite build (gitignored)
├── src/
│   ├── Controller/
│   │   ├── HomeController.php    # Public homepage
│   │   ├── Admin/                # Backoffice controllers
│   │   │   ├── DashboardController.php
│   │   │   ├── AbstractCrudController.php   # Reusable CRUD base
│   │   │   ├── RoleController.php           # Extends AbstractCrudController
│   │   │   ├── UserController.php           # Custom (list + edit-roles, no full CRUD)
│   │   │   └── PermissionController.php     # Read-only catalog viewer
│   │   └── Security/             # Login, Registration, Logout
│   ├── Entity/                   # Doctrine entities (attribute mapping)
│   │   ├── User.php              # app_user table (Postgres 'user' is reserved)
│   │   ├── Role.php
│   │   └── Permission.php
│   ├── Form/                     # Symfony form types
│   │   ├── RegistrationFormType.php
│   │   ├── RoleType.php
│   │   └── UserRolesType.php
│   ├── Repository/               # Custom repositories
│   ├── Security/
│   │   ├── Permission.php        # PERMISSION_* constants + all()/choices()/isValid()
│   │   └── PermissionVoter.php   # is_granted() gate for PERMISSION_*
│   ├── DataFixtures/             # Doctrine fixtures
│   │   ├── PermissionFixtures.php
│   │   ├── RoleFixtures.php
│   │   ├── UserFixtures.php
│   │   └── AppFixtures.php       # Orchestrator (sets getDependencies())
│   └── Kernel.php                # App kernel
├── symfony.lock                  # Recipe lock for Symfony Flex
├── templates/                    # Twig templates
│   ├── base.html.twig            # Public layout
│   ├── form/
│   │   └── theme.html.twig       # Tailwind form theme (extends form_div_layout)
│   ├── security/                 # login.html.twig, confirm.html.twig, confirm_invalid.html.twig
│   ├── admin/
│   │   ├── base.html.twig        # Two-column: sidebar + content
│   │   ├── _sidebar.html.twig    # Permission-gated nav
│   │   ├── crud/                 # SHARED CRUD PARTIALS — see §13
│   │   │   ├── _list.html.twig
│   │   │   ├── _form.html.twig
│   │   │   ├── _row_actions.html.twig
│   │   │   ├── _delete_form.html.twig
│   │   │   ├── _empty_state.html.twig
│   │   │   └── _breadcrumbs.html.twig
│   │   ├── dashboard/
│   │   ├── role/                 # thin wrappers around admin/crud partials
│   │   ├── user/
│   │   └── permission/
│   └── emails/                   # HTML+TXT lifecycle email templates (translatable)
├── tests/                        # (Not yet populated; see AGENTS.md for conventions)
├── var/                          # Generated — cache and logs (gitignored)
├── vendor/                       # Generated — Composer deps (gitignored)
├── vite.config.js                # Vite config: vite-plugin-symfony, build → public/build
├── tailwind.config.js            # Tailwind config: content globs include templates/ + assets/
├── postcss.config.js             # PostCSS: tailwindcss + autoprefixer
├── package.json                  # Node deps (canonical source for frontend versions)
├── .editorconfig                 # Indent + EOL conventions
├── .dockerignore                 # Slim build context
├── .gitignore                    # Symfony + project-specific ignores
├── .env / .env.dev               # Symfony env files (DATABASE_URL etc.)
├── INFRASTRUCTURE_PLAN.md        # Why the infra looks the way it does (history of decisions)
├── AGENTS.md                     # Project context for AI coding assistants
└── README.md                     # ← you are here
```

---

## 5. Daily development workflow

### Editing PHP / Twig

```bash
# Edit any .php under src/ or any .twig under templates/
# Apache picks up the change on the next HTTP request — no restart needed
# (opcache.revalidate_freq=0 means opcache always re-reads file mtimes)
```

### Editing frontend assets (with HMR)

```bash
# Start Vite dev server on the host (one terminal, leave running)
npm run dev
# → http://localhost:5173 — Vite dev server
```

In dev, the `pentatrion/vite-bundle` Twig functions detect the running Vite
dev server and inject `<script type="module" src="http://localhost:5173/…">`
tags directly. CSS changes hot-replace; JS changes trigger a full page reload.

### Editing Twig templates (without a Vite dev server)

If you change a template and use a new Tailwind class that wasn't in the
previously-built CSS, run a one-shot production build so Tailwind's JIT picks
it up:

```bash
npm run build
```

Then reload the page. (Or use `npm run watch` for a continuous rebuild.)

### Adding Composer packages

```bash
# Always run composer through the sidecar, never on the host
docker compose --profile tools run --rm composer require vendor/package
docker compose --profile tools run --rm composer require --dev vendor/package
```

The `composer` service runs as `${UID:-1000}:${GID:-1000}` and bind-mounts
`./`, so files are written with the host's ownership.

### Scaffolding Symfony code

```bash
docker compose exec php bin/console make:controller FooController
docker compose exec php bin/console make:entity Property
docker compose exec php bin/console make:migration
```

---

## 6. Frontend pipeline

| Tool | Version | Role |
|---|---|---|
| Vite | 5.4 | Dev server + production bundler |
| `vite-plugin-symfony` | 8.2 | Bridges Vite to Symfony's `entrypoints.json` contract |
| Tailwind CSS | 3.4 | Utility-first CSS, JIT compilation |
| PostCSS | 8 | Required by Tailwind |
| Autoprefixer | 10 | Adds vendor prefixes |

**Single entry pair:**

- `assets/app.js` — JS entry. Currently just `import './styles/app.css'` and a
  console log.
- `assets/styles/app.css` — CSS entry. Contains the three `@tailwind`
  directives.

**How it gets into pages:** the layout file `templates/base.html.twig` includes:

```twig
{{ vite_entry_link_tags('app') }}      {# in <head> #}
{{ vite_entry_script_tags('app') }}    {# before </body> #}
```

These Twig functions come from `pentatrion/vite-bundle`. In dev they point at
the Vite dev server; in production they read the manifest in
`public/build/.vite/entrypoints.json`.

**Content globs (in `tailwind.config.js`):**

```js
content: [
  './templates/**/*.{html,twig,js,jsx,ts,tsx,vue}',
  './assets/**/*.{js,jsx,ts,tsx,vue}',
]
```

Any Tailwind class used inside a Twig template or a JS file is included in
the build. Classes not used are tree-shaken.

**Adding a new entry** (e.g. a separate admin bundle): see AGENTS.md.

---

## 7. Database workflow

**Connection** (set in `docker-compose.yml` and `.env`):

```
postgresql://app:!ChangeMe!@database:5432/app?serverVersion=16&charset=utf8
```

The hostname `database` is the Docker service name — works from inside the
php container. For external tools, use `127.0.0.1:5432` (the DB is mapped to
loopback only, never to `0.0.0.0`).

**Lifecycle:**

```bash
# Create / drop the schema (dev only — for prod use migrations)
docker compose exec php bin/console doctrine:database:create --if-not-exists
docker compose exec php bin/console doctrine:database:drop --force

# Schema changes
docker compose exec php bin/console make:entity      # edit src/Entity/…
docker compose exec php bin/console make:migration   # generate a migration
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction

# Seed data
docker compose exec php bin/console doctrine:fixtures:load
```

**Mapping:** Doctrine attribute mapping in PHP 8 (no XML/YAML mapping files).
Entity namespaces follow PSR-4 from `src/`.

---

## 8. Design system

The full aesthetic philosophy lives in
[`.opencode/skills/frontend-design/SKILL.md`](.opencode/skills/frontend-design/SKILL.md).
Read it before any UI work.

**Current technical conventions:**

- **Tailwind utility-first.** No custom CSS files outside
  `assets/styles/app.css`. If you need a design token (color, spacing, font
  family), add it to `tailwind.config.js` under `theme.extend`.
- **Typography.** No font is configured yet. When you pick a display + body
  pair, document it in `AGENTS.md` §"Design guidance" so future work is
  consistent.
- **Color palette.** No brand colors set yet. Same documentation rule.
- **No dark mode** is currently configured. Add `darkMode: 'class'` in
  `tailwind.config.js` if needed.
- **Components.** No component library yet. The home page is intentionally a
  single self-contained block — no premature extraction. The backoffice
  uses shared Twig partials in `templates/admin/crud/` (see
  [§13 The shared Twig CRUD base](#13-the-shared-twig-crud-base)).

**Backoffice visual language** (placeholder, pending brand work):

- **Layout**: two-column — fixed `w-64` sidebar on the left, scrollable
  content area on the right. The sidebar lives in
  `templates/admin/_sidebar.html.twig` and is shared across every
  backoffice page via `admin/base.html.twig`.
- **Sidebar items are permission-gated**: each link is wrapped in an
  `{% if is_granted(constant('App\\Security\\Permission::PERMISSION_X')) %}`
  check. A `ROLE_USER` (view only) sees only Dashboard + Sign out.
- **Palette**: stone (neutral grays) for chrome, amber-600/700 as the
  single accent. No purple, no blue, no gradients. Replace with the real
  brand palette when it's chosen.
- **Typography**: `font-serif` (Georgia) for page titles, default
  sans-serif for everything else. Replace when the brand picks a font pair.
- **Buttons**: `bg-stone-900` for primary, `text-rose-700` for destructive,
  `text-amber-700` for in-row edit links. All in `rounded-md`.

**Anti-patterns to avoid** (from the frontend-design skill):

- Generic sans-serifs (Inter, Roboto, Arial, system-ui defaults)
- Purple-on-white gradient clichés (the home page's purple is *intentionally*
  a placeholder smoke-test color — do not treat it as a brand choice)
- Cookie-cutter layouts and component shapes

---

## 9. Infrastructure reference

### Services (defined in `docker-compose.yml`)

| Service  | Image                | Port mapping     | Purpose | Auto-starts? |
|----------|----------------------|------------------|---------|---|
| `php`    | `php:8.4-apache` (built from `Dockerfile`) | `8000:80` (host→container) | Apache + PHP-FPM-less; serves the app | yes |
| `database` | `postgres:16-alpine` | `127.0.0.1:5432:5432` | Postgres 16 | yes |
| `composer` | `composer:2`        | none             | Composer sidecar (runs as host UID) | no — `profiles: ["tools"]` |

### Environment variables

| Var | Set in | Purpose |
|---|---|---|
| `APP_ENV` | `docker-compose.yml` | Symfony env (`dev`) |
| `APP_DEBUG` | `docker-compose.yml` | Enable debug bundles + Web Profiler |
| `DATABASE_URL` | `docker-compose.yml` (override) + `.env` | Postgres DSN |
| `APP_SECRET` | `.env` | Symfony app secret (generate before prod) |
| `POSTGRES_*` | `docker-compose.yml` | Container-internal DB credentials |

`.env.local` is gitignored — use it for personal secrets and overrides.

### Volumes

| Name | Type | Purpose |
|---|---|---|
| `db_data` | Named Docker volume | Persists Postgres data across `down`/`up` |
| `./` | Bind mount | Live source code into `/var/www/html` |

We deliberately do **not** use named volumes for `vendor/`, `var/`, or
`node_modules/` — on Linux the bind mount is just as fast and removes an
entire class of permission and stale-state bugs. (If you later develop on
macOS/Windows, revisit this — see INFRASTRUCTURE_PLAN.md for the rationale.)

### Apache vhost

`apache/nova-argentum.conf`:

- `DocumentRoot /var/www/html/public`
- `<Directory /var/www/html/public> AllowOverride All Require all granted</Directory>`
- `DirectoryIndex index.php`
- `ErrorLog` / `CustomLog` → `nova-argentum-{error,access}.log`

URL rewriting is handled by the `public/.htaccess` (from
`symfony/apache-pack`).

### PHP overrides

- `docker/php/opcache.ini` — `opcache.enable=1`, `validate_timestamps=1`,
  `revalidate_freq=0` (always re-reads file mtimes in dev, so PHP file
  changes don't need a container restart).
- `docker/php/apcu.ini` — 128 MB shared memory.

### Healthcheck

`database` has a `pg_isready` healthcheck; `php` waits for it via
`depends_on: condition: service_healthy`. So the app container only starts
once Postgres can accept connections.

---

## 10. Common tasks

### Add a new page

1. `src/Controller/AboutController.php` (extends `AbstractController`,
   has `#[Route('/about', name: 'app_about')]`) that renders the template.
2. `templates/about/index.html.twig` that extends `base.html.twig`.
3. If you introduced new Tailwind classes, run `npm run build` so the CSS
   bundle picks them up.

### Add a new entity with full CRUD (backoffice)

Follow the `Role` / `templates/admin/role/` pattern — see
[§13 The shared Twig CRUD base](#13-the-shared-twig-crud-base). The
concrete controller is ~40 lines of contract methods; the templates are
four thin wrappers around the shared partials (`_list`, `_form`, view,
plus reusing `_row_actions` for icons).

Steps: `make:entity` → `make:migration` → `migrations:migrate` →
controller + form type + four templates → optional sidebar link gated
by `is_granted(...)`.

### Add a new permission

1. `src/Security/Permission.php`: append a `public const string
   PERMISSION_NEW_THING` (constant and `all()` array).
2. `make:migration`, then edit it to call `Permission::all()` and INSERT
   each name with `ON CONFLICT (name) DO NOTHING`. (See the existing
   `Version...permission-seed` migration for the pattern.)
3. `migrations:migrate`.
4. Reassign roles via `/admin/roles/{id}/edit`.

Never inline a permission string anywhere else. `App\Security\Permission`
is the single source of truth.

### Add a new Vite entry

Edit `vite.config.js` `build.rollupOptions.input`:

```js
input: {
  app: './assets/app.js',
  admin: './assets/admin.js',
}
```

Then in the relevant template:

```twig
{{ vite_entry_link_tags('admin') }}
{{ vite_entry_script_tags('admin') }}
```

### Add a new Composer package

```bash
docker compose --profile tools run --rm composer require vendor/package
```

If a Flex recipe is registered for the package, follow any prompts (or
pass `--no-interaction` if you know what you're doing). After install,
clear the cache: `docker compose exec php bin/console cache:clear`.

### Reset the database

```bash
docker compose down -v            # -v removes the db_data named volume
docker compose up -d --build
docker compose exec php bin/console doctrine:database:create
```

### Clear Symfony cache

```bash
docker compose exec php bin/console cache:clear
```

---

## 11. Authentication and backoffice

The backoffice is a small admin UI for managing users, roles, and the
permission catalog. It's gated by Symfony's `IS_AUTHENTICATED_FULLY` on
the `/admin` prefix (see `config/packages/security.yaml`), and inside the
prefix each route is further gated by a `#[IsGranted(Permission::...)]`
attribute on its controller.

### Surfaces

| URL | Method | Auth | Notes |
|---|---|---|---|
| `/` | GET | public | Home (placeholder) |
| `/login` | GET, POST | public | Form login. `username_parameter: _email`, `password_parameter: _password` |
| `/logout` | POST | (intercepts) | Firewall intercepts — controller body should never execute |
| `/account/confirm` | GET, POST | public (token-gated) | Account confirmation link from email; sets password |
| `/admin` | GET | `PERMISSION_VIEW_BACKOFFICE` | Greeting + permission-gated tile grid |
| `/admin/users` | GET | `PERMISSION_MANAGE_USERS` | User list |
| `/admin/users/new` | GET, POST | `PERMISSION_MANAGE_USERS` | Create user (no password field; confirmation email sent) |
| `/admin/users/{id}` | GET | `PERMISSION_MANAGE_USERS` | View user |
| `/admin/users/{id}/edit` | GET, POST | `PERMISSION_MANAGE_USERS` | Edit user (incl. state) |
| `/admin/users/{id}/delete` | POST | `PERMISSION_MANAGE_USERS` | Delete user (CSRF + confirm) |
| `/admin/users/{id}/impersonate` | POST | `PERMISSION_IMPERSONATE_USERS` | "Log in as" (CSRF-gated; banners while active) |
| `/admin/users/{id}/resend-confirmation` | POST | `PERMISSION_MANAGE_USERS` | Resend the confirmation email |
| `/admin/roles` | GET | `PERMISSION_MANAGE_ROLES` | Role list (count of permissions per role) |
| `/admin/roles/new` | GET, POST | `PERMISSION_MANAGE_ROLES` | Create a role |
| `/admin/roles/{id}` | GET | `PERMISSION_MANAGE_ROLES` | View role |
| `/admin/roles/{id}/edit` | GET, POST | `PERMISSION_MANAGE_ROLES` | Edit a role (rename + reassign permissions) |
| `/admin/roles/{id}/delete` | POST | `PERMISSION_MANAGE_ROLES` | Delete a role (CSRF + confirm) |
| `/admin/permissions` | GET | `PERMISSION_MANAGE_PERMISSIONS` | Read-only view of the catalog |

### Login flow

1. `GET /login` renders `templates/security/login.html.twig`. The form
   posts to `app_login` with `_email`, `_password`, and `_csrf_token`
   (CSRF id `authenticate`).
2. Symfony's firewall (form_login authenticator) validates the
   credentials and on success authenticates the user.
3. `default_target_path: admin_dashboard` redirects to `/admin`.
4. Subsequent requests reuse the session.

`UserStateChecker::checkPreAuth` blocks Disabled users. Symfony's
`AuthenticatorManager` converts the resulting `DisabledException` into
a generic "Invalid credentials" message to prevent user enumeration —
that is intentional, not a bug.

### Account confirmation flow

There is **no public registration route.** Admins create users via
`/admin/users/new`; the new account is created in `USER_STATE_PENDING`
and a confirmation email is sent automatically.

1. Admin creates a user. `UserController::onCreateSuccess` calls
   `UserStateTransitionService::resendConfirmation()`, which issues a
   selector+secret token (`AccountConfirmationToken`, 2-day TTL,
   sha256-stored) and emails `APP_URL/account/confirm?selector=…&secret=…`.
2. User clicks the link → `AccountConfirmationController` (public route
   `/account/confirm`) renders `AccountPasswordFormType`. Submit calls
   `ChangeUserStateToActive::execute($user, $plainPassword)` which
   hashes the password, sets state to `USER_STATE_ACTIVE`, sends an
   "activated" notification, and consumes the token.
3. User logs in.

Email change while Pending: a `POST_SUBMIT` listener on `UserType`
detects the email change and re-runs `resendConfirmation()` to
invalidate old tokens and send a fresh link to the new address.

### Logout

`/logout` is intercepted by the firewall (CSRF-protected POST,
`csrf_token_id: logout`). The `LogoutController` action throws if the
interception is misconfigured — a failure is loud, not silent.

### Login throttling

`symfony/rate-limiter` enforces 5 failed attempts per 15 minutes per IP
on `/login`. On exceed, the firewall returns a `302` to `/login` with a
generic error; the user has to wait the throttle window out.

### Dev email capture (Mailpit)

Configured in `compose.override.yaml` as the `mailer` service. SMTP on
`mailer:1025`, web UI at `http://localhost:8025`. No credentials needed
(`MP_SMTP_AUTH_ACCEPT_ANY=1`).

- **Dev**: `.env.dev` defaults `MAILER_DSN=smtp://mailer:1025`. Every
  email stays in the local Mailpit UI — nothing leaves the dev machine.
- **Override per-developer** with a real SMTP (Mailgun, SES, etc.) in
  `.env.local` only when testing the actual delivery path.
- **Prod**: never commit SMTP credentials; set them in
  `.env.prod.local` (gitignored).
- **Smoke test**: `docker compose exec php bin/console app:dev:test-email you@example.com`
  (the command refuses to run in `APP_ENV=prod`).

### CSRF gotcha

Symfony's `SameOriginCsrfTokenManager` is enabled by default
(`config/packages/csrf.yaml`). The HTML the server renders for every form
contains the literal string `value="csrf-token"` in the hidden CSRF
input — **this is the placeholder, not a real token.** Real validation
is done by checking the `Origin` / `Referer` headers and a cookie
double-submit. A regular browser sends these headers automatically; this
only matters for curl / CLI / API tests. If you write a curl test for a
POST, send `-H "Origin: http://localhost:8000" -H "Referer: http://localhost:8000/login"`
or the firewall will reject the request.

---

## 12. RBAC and the permission catalog

### Data model

```
       PERMISSION_*  (string constants in App\Security\Permission)
            │
            │  many-to-many
            ▼
          ROLE_*    (Role entity; e.g. ROLE_ADMIN, ROLE_USER)
            │
            │  many-to-many
            ▼
          USER      (app_user table)
```

- **Permissions** live in the database as rows in the `permission`
  table, but their *names* are hardcoded as `public const string
  PERMISSION_*` in `src/Security/Permission`. The constant name and
  value are identical (`PERMISSION_EDIT_USER = 'PERMISSION_EDIT_USER'`),
  so a typo in either side fails fast.
- **Roles** are rows in the `role` table. Baseline fixtures seed
  `ROLE_ADMIN` (all 5 perms) and `ROLE_USER` (view only).
- **Users** carry roles via the `user_role` join table. Admins reassign
  roles via `/admin/users/{id}/edit`.

### Current permission catalog

| Constant | Grants |
|---|---|
| `PERMISSION_VIEW_BACKOFFICE` | See `/admin` and the dashboard |
| `PERMISSION_MANAGE_USERS` | List, create, edit users + resend confirmation |
| `PERMISSION_MANAGE_ROLES` | Create / edit / delete roles + their permission sets |
| `PERMISSION_MANAGE_PERMISSIONS` | View the permission catalog |
| `PERMISSION_IMPERSONATE_USERS` | "Log in as" from the user list (backoffice banner while active) |

The catalog is the canonical source of truth at
`src/Security/Permission.php` — `all()`, `choices()`, and `isValid()` are
derived from the same constant set.

### How `is_granted(Permission::PERMISSION_*)` works

Two layers, by design:

1. **`User::getRoles()`** flattens: it returns
   `['ROLE_USER', <role names>, <permission names from each role>]`.
   This makes the built-in `RoleVoter` work — it sees the permission
   strings in the user's role set and grants.
2. **`App\Security\PermissionVoter`** is a backup that handles attributes
   starting with `PERMISSION_`. It checks `User::getRoles()`. (Without
   the voter, the built-in `RoleVoter` would ABSTAIN on `PERMISSION_*`
   attributes because they don't start with `ROLE_`, and the request
   would 403.)

The two layers are intentional redundancy. If you change `getRoles()` to
return only role names, the voter still saves you. If you remove the
voter, `getRoles()` still saves you. Either alone is correct.

### Adding a new permission

1. **`src/Security/Permission.php`**: append a constant + add to `all()`.
2. **Generate a new migration.** It must call `Permission::all()` and
   INSERT the new constant name with `ON CONFLICT DO NOTHING`. See the
   existing `Version...permission-seed` migration for the pattern.
3. **Run migrations** in any environment that needs it.
4. **Assign the new permission to roles** via the backoffice
   (`/admin/roles/{id}/edit`).

Never inline a permission string anywhere else. A typo anywhere breaks
RBAC silently.

---

## 13. The shared Twig CRUD base

Every entity that needs full CRUD (list, new, edit, delete, view)
reuses the same moving parts. To add a new entity you only need a small
controller and four thin templates — the form widgets, list table,
empty state, breadcrumbs, delete button, and impersonation row action
are all shared. See `templates/admin/role/{index,new,edit,view}.html.twig`
for the reference implementation (the `Role` entity).

### `src/Controller/Admin/AbstractCrudController.php`

A reusable controller that implements `index`, `new`, `edit`, `view`,
`delete`. A concrete controller extends it and supplies a small
contract: `getEntityClass()`, `getFormType()`, `getTemplatePrefix()`,
`getRoutePrefix()`, `getListColumns()`, `getRequiredPermission()`,
`getEntityLabel()`, `getEntityDisplayName()`, `findEntity()`,
`newEntity()`.

`AbstractCrudController` itself is *not* permission-gated — each
concrete controller is gated by a class-level
`#[IsGranted(Permission::...)]`, and the per-method calls inside the
parent use `denyAccessUnlessGranted($this->getRequiredPermission())` as
defence in depth.

### `templates/admin/crud/_*.twig`

| Partial | Purpose |
|---|---|
| `_list.html.twig` | Page header, optional "New" button, styled table, empty-state fallback |
| `_form.html.twig` | Card with title, Symfony form, submit, optional delete button |
| `_row_actions.html.twig` | Per-row icon links: view / edit / impersonate / delete (form); row-scoped CSRF for each |
| `_delete_form.html.twig` | CSRF + method=POST delete form |
| `_empty_state.html.twig` | "No items yet, create one" callout |
| `_breadcrumbs.html.twig` | Crumb renderer |

The theme is registered globally in `config/packages/twig.yaml` via
`form_themes: ['form/theme.html.twig']`.

### Reference implementation

`Role` is the canonical example of the pattern:

- Controller: `src/Controller/Admin/RoleController.php` (~40 lines of
  contract methods; the heavy lifting is in the parent).
- Templates: `templates/admin/role/{index,new,edit}.html.twig` — each
  one is a thin wrapper that sets variables and includes the CRUD
  partials.

`UserController` deliberately does *not* extend `AbstractCrudController`
— it only needs list + edit-roles, not full CRUD. The list still uses
`@admin/crud/_breadcrumbs.html.twig` (and a hand-rolled table for the
user-specific columns).

---

## 14. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| New Tailwind class has no effect | Vite build is stale (Tailwind JIT didn't see the class because the home template was added after the last build) | `npm run build` to rebuild the CSS |
| `bin/console` complains about a class that exists in `vendor/` | Flex recipe state desync — happened when a previous `composer require` failed mid-install | See INFRASTRUCTURE_PLAN.md §"Step 2 deviations" for the full story; the fix is usually `composer require <pkg> --no-update` to re-trigger Flex |
| Permission denied on `vendor/` after a composer run | Someone ran composer on the host instead of through the sidecar | `sudo chown -R $(id -u):$(id -g) vendor` (rare) — but really, always use the sidecar |
| `500 Internal Server Error` referencing a missing extension | PHP container was built before the Dockerfile was updated | `docker compose up -d --build` to rebuild the image |
| Hot reload not happening | Vite dev server isn't running, or `host` in `vite.config.js` is not `0.0.0.0` | `npm run dev` in another terminal; verify the config |
| `twig` package not found in composer | Bare `twig` is not a valid package name; it's `twig/twig` | Use the full `vendor/package` name |
| Container can't resolve `database` hostname | You're trying to use Postgres from the host using the DSN from `.env` | Use `127.0.0.1:5432` from the host, `database:5432` from inside containers |
| `is_granted(Permission::PERMISSION_*)` returns false for a user that should have the permission | The user's role assignments in the DB don't include the permission — check the join table and the role's permission set | Inspect via the `/admin/permissions` view; reassign via `/admin/roles/{id}/edit` |
| Form posts to `/login` and silently redirects to `/login` again | CSRF: the request didn't carry `Origin` / `Referer` headers that match the app's origin | For curl / API tests, add `-H "Origin: http://localhost:8000" -H "Referer: http://localhost:8000/login"` |
| `column "t0.id" does not exist` on a Postgres query | A Doctrine entity is using a name that Postgres treats as a reserved word — most commonly `user` | Rename the table (we use `app_user`). The `User` entity has `#[ORM\Table(name: 'app_user')]` and the migration matches |

---

## 15. Command cheat sheet

### Docker

```bash
docker compose up -d --build      # start the stack (rebuild php image)
docker compose down               # stop (keeps db_data volume)
docker compose down -v            # stop and drop the db_data volume
docker compose ps                 # list running services
docker compose logs -f php        # tail php container logs
docker compose exec php bash      # shell into the php container
```

### Symfony console (inside the php container)

```bash
docker compose exec php bin/console cache:clear
docker compose exec php bin/console debug:router
docker compose exec php bin/console debug:container
docker compose exec php bin/console debug:twig
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:fixtures:load --append
docker compose exec php bin/console dbal:run-sql "SELECT id, name FROM role"
```

### Composer (via sidecar)

```bash
docker compose --profile tools run --rm composer install
docker compose --profile tools run --rm composer require vendor/package
docker compose --profile tools run --rm composer require --dev vendor/package
docker compose --profile tools run --rm composer remove vendor/package
docker compose --profile tools run --rm composer update
docker compose --profile tools run --rm composer recipes
```

### Node / Vite (on the host)

```bash
npm install                       # one-time, after clone
npm run dev                       # Vite dev server with HMR (port 5173)
npm run build                     # production build (writes public/build/)
npm run watch                     # continuous production build
npm run preview                   # preview the production build locally
```

### Git

```bash
git status
git diff
git add README.md AGENTS.md
git commit -m "docs: add README and AGENTS context"
```

---

## 16. License

`composer.json` declares `"license": "proprietary"`. Add a top-level
`LICENSE` file before making the repository public.
