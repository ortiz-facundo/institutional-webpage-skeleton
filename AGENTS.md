# AGENTS.md — Nova Argentum

> Project context for AI coding assistants (Claude Code, Aider, Cursor, etc.).
> Read this first. Update freely as the project evolves — this is the project's
> living rulebook, not a read-only artifact.

---

## TL;DR

- Symfony 7.4 institutional webpage for a land development and brokerage
  business.
- Stack: **PHP 8.4**, **Apache 2.4** (single container, no `php-fpm` split),
  **PostgreSQL 16**, **Vite 5**, **Tailwind 3.4**, **Doctrine ORM 3.6**,
  **Symfony Security 7.4** (form login + stateless CSRF + login throttling),
  **Symfony Form 7.4**, **Symfony Validator 7.4**.
- Three Docker services: `php` (Apache), `database` (Postgres), `composer`
  (sidecar, `profile=tools`, never auto-starts).
- Frontend assets built on the host with **Node 18+**; HMR via `npm run dev`.
- No host PHP, no host Composer, no host Postgres required — only Docker.
- An authenticated **backoffice** lives under `/admin`, gated by RBAC.
  Public homepage at `/`, login at `/login`, registration at `/register`.
- Test user in dev: `test@nova-argentum.local` / `test` (role: `ROLE_ADMIN`).

---

## Mandatory reading before doing anything

1. This file (you're here).
2. `README.md` — full architecture, workflow, troubleshooting.
3. `.opencode/skills/frontend-design/SKILL.md` — full aesthetic guidance.
   **Required reading before any UI work.**
4. `INFRASTRUCTURE_PLAN.md` — rationale for the infrastructure decisions
   (only consult when "why" questions come up, e.g. why Vite 5 and not 6,
   why PHP 8.4 and not 8.3, why no named volumes for vendor/var).

---

## Hard rules

In priority order. If you find yourself about to violate one of these, stop.

1. **Never commit, never push, never amend history, never create branches.**
   The user commits manually. Don't run `git commit`, `git push`, `git
   checkout -b`, `git rebase`, or `git reset` unless the user explicitly asks
   for it. Exception: the user *can* ask, e.g. "commit this for me."
2. **Never run `composer` on the host.** Use
   `docker compose --profile tools run --rm composer …`. The host has no PHP
   and no Composer installed.
3. **Never run `npm` inside the php container.** It runs on the host.
4. **Never edit `vendor/`, `var/`, `node_modules/`, `public/build/`.** These
   are generated. If you need to change behavior, change the source of truth
   (`composer.json`, `assets/`, `vite.config.js`) and reinstall/rebuild.
5. **Never put `apache/` or `docker/` in `.dockerignore`.** The Dockerfile
   `COPY`s from both directories. `.dockerignore` is for build-context
   slimming only.
6. **Never use the `vite:` top-level config key in `config/packages/vite.yaml`.**
   The actual extension alias is `pentatrion_vite:`. Using `vite:` will fail
   with "no extension able to load the configuration for 'vite'".
7. **Always run `npm run build` after adding/changing Twig templates with new
   Tailwind classes** (or run `npm run dev` for HMR). Tailwind's JIT only
   emits classes that appear in `content` globs — if you write
   `bg-foo-500` in a template without rebuilding, the class won't be in
   `public/build/assets/app-*.css` and the page will render unstyled.
8. **Always use full vendor/package names** in `composer require`. `twig`
   fails — use `twig/twig`. Same for any other package without an
   auto-resolved alias.
9. **Never downgrade Vite below 5** without also upgrading Node. Vite 5 is
   the last line that supports Node 18. Vite 6+ requires Node 18.18+ but is
   increasingly Node 20+ focused; Vite 8 requires Node 20.19+. The host here
   is Node 18.19.
10. **Never downgrade `doctrine/doctrine-bundle` below 3.2 or
    `doctrine-migrations-bundle` below 4.0.** Both require PHP 8.4; the
    Dockerfile is pinned to `php:8.4-apache` because of this.
11. **Never name a Doctrine entity table `user` on Postgres.** `user` is a
    reserved word and breaks unquoted queries. Our User entity uses
    `#[ORM\Table(name: 'app_user')]`. The migration that creates the
    schema must use the same quoted/unquoted name.
12. **Never define a permission string anywhere except `App\Security\Permission`.**
    The catalog is the canonical source of truth. The seed migration and
    fixtures iterate `Permission::all()`; the voter matches by exact string
    equality. A typo anywhere breaks RBAC silently. To add a permission:
    append a constant + add to `all()` + ship a new migration that calls
    `Permission::all()` in its `up()`.

---

## Layout — only the dirs you'll touch

```
src/Controller/      Symfony controllers, PSR-4 App\Controller\
  ├── Admin/          Backoffice controllers. AbstractCrudController is the shared base.
  └── Security/       LoginController, RegistrationController, LogoutController.

src/Entity/           Doctrine entities, attribute mapping, PSR-4 App\Entity\
                      Current: User (table: app_user), Role, Permission.

src/Repository/       Custom repositories, PSR-4 App\Repository\
                      Current: UserRepository, RoleRepository, PermissionRepository.

src/Security/         Security-related non-controller code.
                      Permission.php     — the canonical permission catalog
                                           (PERMISSION_* constants, all(), choices(), isValid()).
                      PermissionVoter.php — grants is_granted(Permission::PERMISSION_*) for users
                                           whose role assignments include the permission.

src/Form/             Symfony form types. PSR-4 App\Form\
                      Current: RegistrationFormType, RoleType, UserRolesType.

src/DataFixtures/     Doctrine fixtures. Dependencies: PermissionFixtures → RoleFixtures
                      → UserFixtures → AppFixtures (orchestrator).

templates/            Twig. base.html.twig is the public-site layout; every page extends it.
  ├── form/
  │   └── theme.html.twig    Tailwind form theme (uses form_div_layout.html.twig).
  ├── security/              login.html.twig, register.html.twig (public auth pages).
  ├── admin/
  │   ├── base.html.twig     Two-column layout: sidebar + main content.
  │   ├── _sidebar.html.twig Permission-gated nav (uses is_granted).
  │   ├── crud/              SHARED CRUD PARTIALS — used by every entity that needs
  │   │   │                   list / new / edit / delete screens. See §"Shared Twig
  │   │   │                   CRUD base" below.
  │   │   ├── _list.html.twig
  │   │   ├── _form.html.twig
  │   │   ├── _delete_form.html.twig
  │   │   ├── _empty_state.html.twig
  │   │   └── _breadcrumbs.html.twig
  │   ├── dashboard/         Backoffice home (greeting + permission-gated tile grid).
  │   ├── role/              Role CRUD screens — thin wrappers around the CRUD partials.
  │   ├── user/              User list + edit-roles screen.
  │   └── permission/        Read-only permission catalog viewer.
  └── home/                  Public homepage.

assets/               Vite inputs: app.js + styles/app.css. New entries also live here.
config/packages/      Per-bundle configs. Symfony Flex writes via `###> pkg ###` markers —
                      preserve them so recipes stay idempotent on reinstall.
                      security.yaml  — firewall, providers, password hashers, access_control.
                      csrf.yaml      — token_id 'authenticate' is in stateless_token_ids.
                      twig.yaml      — registers form/themes.html.twig.
config/routes/        Route imports only. Controllers are auto-discovered via attributes.
migrations/           Doctrine migrations. Committed.
                      Version... schema migration creates the 5 tables.
                      Version... permission-seed migration inserts Permission::all() rows.
public/build/         Generated by `npm run build`; gitignored.
docker/               Dockerfile support files (opcache.ini, apcu.ini). NOT in .dockerignore.
apache/               vhost config. NOT in .dockerignore.
.opencode/skills/     Skills, including frontend-design aesthetic guidance.
```

Everything else (`bin/`, `vendor/`, `var/`, `public/index.php`,
`public/.htaccess`, `config/bundles.php`, `config/services.yaml`,
`config/routes.yaml`, `src/Kernel.php`) is generated or boilerplate — don't
edit unless you know why.

---

## Build & run — exact commands that work

```bash
# Start the stack (rebuilds the php image if Dockerfile changed)
docker compose up -d --build

# Stop (keeps the Postgres data volume)
docker compose down

# Reset everything including the database
docker compose down -v && docker compose up -d --build

# Symfony console
docker compose exec php bin/console <cmd>

# Composer (always via the sidecar)
docker compose --profile tools run --rm composer <cmd>

# HMR on the host (one terminal, leave running)
npm run dev

# Production build of frontend assets (writes to public/build/)
npm run build
```

URLs once running:

- App (public):  <http://localhost:8000>
- Login:         <http://localhost:8000/login>
- Register:      <http://localhost:8000/register>
- Backoffice:    <http://localhost:8000/admin>  (auth required)
- Vite:          <http://localhost:5173>  (only when `npm run dev` is running)
- DB:            `127.0.0.1:5432` from host, `database:5432` from inside containers

Test user (dev fixtures, after running the first-time seed):

- email:    `test@nova-argentum.local`
- password: `test`
- role:     `ROLE_ADMIN` (all four permissions)

---

## Authentication, RBAC, and authorization

### Authentication

- **Form login only** at `/login`. Username is email (`_email` form field);
  password is `_password`. CSRF via the framework's stateless mechanism
  (see "CSRF gotcha" in Known Landmines).
- **Logout** at `/logout` is intercepted by the firewall — the
  `LogoutController::logout()` action should never execute; if it does,
  it throws because something is misconfigured.
- **Registration** at `/register` creates a `User` with the default
  `ROLE_USER` role and a hashed password. No email verification, no captcha
  (out of scope for this iteration).
- **Login throttling**: 5 attempts per 15 minutes per IP, via
  `symfony/rate-limiter`. Configured in `config/packages/security.yaml`.

### RBAC: User → Role → Permission

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

- **Permission catalog** is hardcoded in `App\Security\Permission` as
  `public const string PERMISSION_*`. The current 4 are:
  - `PERMISSION_VIEW_BACKOFFICE`
  - `PERMISSION_MANAGE_USERS`
  - `PERMISSION_MANAGE_ROLES`
  - `PERMISSION_MANAGE_PERMISSIONS`
- **Roles** are rows in the `role` table. Baseline fixtures seed `ROLE_ADMIN`
  (all 4 perms) and `ROLE_USER` (view only).
- **Users** carry roles via the `user_role` join table. New registrations
  get `ROLE_USER` by default; admins reassign via `/admin/users/{id}/roles`.
- **The permission seed migration** runs `Permission::all()` and inserts one
  row per constant. The migration is idempotent (`ON CONFLICT DO NOTHING`).

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

1. `src/Security/Permission.php`: append a constant + add to `all()`.
2. Generate a new migration (it must call `Permission::all()` and INSERT
   the new constant name with `ON CONFLICT DO NOTHING`).
3. Re-run migrations in any environment that needs it.
4. Assign the new permission to roles via the backoffice.

---

## Shared Twig CRUD base

Every entity that needs full CRUD (list, new, edit, delete) reuses these
moving parts:

### `src/Controller/Admin/AbstractCrudController.php`

A reusable controller that implements `index`, `new`, `edit`, `delete`.
A concrete controller extends it and supplies a small contract:
`getEntityClass()`, `getFormType()`, `getTemplatePrefix()`,
`getRoutePrefix()`, `getListColumns()`, `getRequiredPermission()`,
`getEntityLabel()`, `getEntityDisplayName()`, `findEntity()`,
`newEntity()`.

`AbstractCrudController` itself is *not* permission-gated — each concrete
controller is gated by a class-level `#[IsGranted(Permission::...)]` and
the per-method calls inside the parent use
`denyAccessUnlessGranted($this->getRequiredPermission())` as a defence in
depth.

### `templates/admin/crud/_*.twig`

Reusable partials. Each takes a documented contract of variables. To add
a new entity with full CRUD you only need four thin templates:

- `templates/<entity>/index.html.twig` — wraps `_list.html.twig`
- `templates/<entity>/new.html.twig` — wraps `_form.html.twig`
- `templates/<entity>/edit.html.twig` — wraps `_form.html.twig`
- `templates/<entity>/view.html.twig` — read-only details page; uses the
  same `<dl>` grid pattern as the form's inputs, plus an Edit link in the
  page header

`AbstractCrudController` ships a `view()` route that renders
`{templatePrefix}/view.html.twig` and an abstract
`getEntityDisplayName(object $entity): string` hook the concrete
controller overrides to label the entity in breadcrumbs and titles
(e.g. User returns `name ?? email`; Role returns `name`).

See `templates/admin/role/{index,new,edit,view}.html.twig` for the
reference implementation. The `Role` entity is the canonical example
of this pattern.

### Form theme

`templates/form/theme.html.twig` overrides `form_div_layout.html.twig`
blocks to add Tailwind utility classes to the standard Symfony form
widgets. To add a new widget type, add a block override in this file
using the existing patterns (`form_label`, `form_widget_simple`,
`choice_widget_expanded`, `checkbox_widget`, `textarea_widget`,
`form_row`, `form_errors`, `form_label_content`).

**Important gotcha:** the default `form_div_layout.html.twig` does NOT
define a `text_widget` block. Text-like inputs (text, email, password,
tel, url, search, number, etc.) all go through `form_widget_simple`.
Override that block to restyle all of them in one go.

---

## Adding a new page

For a non-CRUD public page:

1. `src/Controller/FooController.php`:

   ```php
   <?php
   namespace App\Controller;

   use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
   use Symfony\Component\HttpFoundation\Response;
   use Symfony\Component\Routing\Attribute\Route;

   final class FooController extends AbstractController
   {
       #[Route('/foo', name: 'app_foo')]
       public function index(): Response
       {
           return $this->render('foo/index.html.twig');
       }
   }
   ```

2. `templates/foo/index.html.twig`:

   ```twig
   {% extends 'base.html.twig' %}

   {% block title %}Foo — Nova Argentum{% endblock %}

   {% block body %}
     <main class="min-h-screen px-6 py-16">
       <h1 class="text-4xl font-bold">Foo</h1>
     </main>
   {% endblock %}
   ```

3. If you added new Tailwind classes: `npm run build` (or have `npm run dev`
   running) so the CSS bundle picks them up.
4. Verify the route is registered: `docker compose exec php bin/console
   debug:router | grep app_foo`.

For a new entity with full CRUD, see "Shared Twig CRUD base" above and
follow the `Role` / `templates/admin/role/` pattern.

---

## Adding a new Vite entry

Edit `vite.config.js` `build.rollupOptions.input`:

```js
input: {
  app: './assets/app.js',
  admin: './assets/admin.js',
}
```

In the relevant template:

```twig
{{ vite_entry_link_tags('admin') }}
{{ vite_entry_script_tags('admin') }}
```

---

## Database operations

```bash
# New entity
docker compose exec php bin/console make:entity

# Generate a migration (review the generated file before committing)
docker compose exec php bin/console make:migration

# Apply pending migrations
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction

# Load fixtures (dev only — wipes data first)
docker compose exec php bin/console doctrine:fixtures:load

# Drop + recreate the database (dev only)
docker compose exec php bin/console doctrine:database:drop --force
docker compose exec php bin/console doctrine:database:create

# Inspect the database directly (Postgres)
docker compose exec php bin/console dbal:run-sql "SELECT id, name FROM role"
```

Entity mapping is by PHP 8 attribute (not XML/YAML). Identity generation on
Postgres is `identity` (configured in `config/packages/doctrine.yaml`).

---

## Conventions

- **Namespaces:** `App\*` PSR-4 from `src/`. `App\Tests\*` from `tests/`.
- **Doctrine:** attribute mapping. Column names use `naming_strategy:
  doctrine.orm.naming_strategy.underscore` (set in `config/packages/doctrine.yaml`).
- **Symfony Flex:** config files have `###> vendor/pkg ###` … `###<
  vendor/pkg ###` markers that delimit Flex-managed sections. **Preserve them**
  so recipes stay idempotent when packages are reinstalled.
- **Twig:** use blocks from `base.html.twig` (`title`, `body`, `stylesheets`,
  `javascripts`) for public pages. For backoffice pages, extend
  `admin/base.html.twig` (which itself extends `base.html.twig`).
- **Tailwind:** utility classes only. Don't add a CSS file outside
  `assets/styles/app.css`. Extend the theme in `tailwind.config.js`, not with
  custom CSS.
- **Routing:** PHP attributes on controller methods (`#[Route('/path',
  name: 'app_xxx')]`). `config/routes.yaml` is for imports only.
- **Twig imports:** if you need new entry tags, use the `pentatrion_vite`
  functions (`vite_entry_link_tags`, `vite_entry_script_tags`,
  `vite_entry_asset`, `vite_mode`).
- **Env vars:** add new ones to `.env` (committed defaults) and
  `.env.local` (personal overrides, gitignored).
- **Permission names:** UPPER_SNAKE_CASE prefixed with `PERMISSION_`.
  Role names: UPPER_SNAKE_CASE prefixed with `ROLE_`. Both names live in
  the `name` column of their respective tables; uniqueness is enforced
  by a unique index.

---

## Design guidance — the project's living rulebook

This is the project's evolving design rulebook. **Add new rules here as the
brand matures.** The full aesthetic philosophy is in
`.opencode/skills/frontend-design/SKILL.md` — read it before any UI work.

### Anti-patterns to avoid (from the skill)

- Generic sans-serifs (Inter, Roboto, Arial, system-ui defaults).
- Purple-on-white gradient clichés. (The home page's purple is a *placeholder
  smoke-test color*; do not treat it as a brand choice.)
- Cookie-cutter, predictable component shapes and layouts.

### Backoffice visual language

- **Layout**: two-column — fixed `w-64` sidebar on the left, scrollable
  content area on the right. The sidebar lives in
  `templates/admin/_sidebar.html.twig` and is shared across every
  backoffice page via `admin/base.html.twig`.
- **Sidebar items are permission-gated**: each link is wrapped in an
  `{% if is_granted(constant('App\\Security\\Permission::PERMISSION_X')) %}`
  check. A `ROLE_USER` (view only) sees only Dashboard + Sign out.
- **Palette**: stone (neutral grays) for chrome, amber-600/700 as the
  single accent. No purple, no blue, no gradients. Replace with the
  real brand palette when it's chosen.
- **Typography**: `font-serif` (Georgia) for page titles, default
  sans-serif for everything else. Replace when the brand picks a font pair.
- **Buttons**: `bg-stone-900` for primary, `text-rose-700` for destructive,
  `text-amber-700` for in-row edit links. All in `rounded-md`.

### Technical conventions

- **Tailwind utility-first.** No custom CSS files outside
  `assets/styles/app.css`. If you need a design token, add it to
  `tailwind.config.js` under `theme.extend`.
- **No dark mode configured yet.** Add via `darkMode: 'class'` in
  `tailwind.config.js` if/when needed.
- **No component library yet.** The home page is intentionally a single
  self-contained block — no premature extraction into shared components.

### Typography

_To be filled in after the first design pass. Pick a distinctive display +
body pair; document the choice here._

### Color palette

_Stone + amber placeholder (see "Backoffice visual language" above). To be
replaced after the first design pass with real brand colors, accent colors,
and a neutral scale. Register them in Tailwind's `theme.extend.colors`._

### Iconography

**Library: Heroicons 24/outline** (MIT). No JS dependency, no build step
beyond what Vite already does. Icons are inlined as SVG in the template
that uses them, sized `h-4 w-4` and color-tinted via `stroke="currentColor"`
plus a `text-*` utility. New CRUD entities should use the canonical row
actions in `templates/admin/crud/_row_actions.html.twig` — adding a new
per-row action means either (a) extending the partial or (b) overriding
the `crud_list_actions` block in the entity's `index.html.twig`. Don't
introduce a second icon set.

| Action | Icon            | Color (Tailwind)        | Used as                    |
|--------|-----------------|-------------------------|----------------------------|
| view   | `eye`           | `text-sky-700`          | inline `<a>` to view route |
| edit   | `pencil-square` | `text-amber-700`        | inline `<a>` to edit route |
| delete | `trash`         | `text-rose-700`         | inline `<form>` POST       |

The sidebar is currently glyphs (▣ ☺ ✐ ✓ ↩); port it to Heroicons on
the next sidebar revision.

---

## Known landmines

- **Vite 8 requires Node 20.19+.** Host is Node 18.19. Don't bump Vite past 5
  without also upgrading Node.
- **`composer` service runs as `${UID:-1000}:${GID:-1000}`.** The user can
  shell into it and edit files with the correct ownership. Don't try to
  change the volume layout to make vendor/ a named volume — that re-creates
  the root-owned-volume problem we already fixed.
- **Flex recipe state can desync** if `composer require` fails mid-install
  (during a flaky network connection, for example). If `bin/console`
  complains about a class for a package that's clearly in `vendor/`, see
  `INFRASTRUCTURE_PLAN.md` §"Step 2 deviations" for the full story and the
  recovery procedure.
- **Latest `doctrine/doctrine-bundle` and `doctrine-migrations-bundle`
  require PHP 8.4.** The Dockerfile is `php:8.4-apache` for this reason.
  Don't downgrade those packages.
- **`/docker/` and `/apache/` are in the build context.** They MUST NOT be
  in `.dockerignore`. This is the most easily-forgotten rule.
- **PostgreSQL DSN host differs by environment.** `database:5432` from
  inside containers (Docker service name), `127.0.0.1:5432` from the host.
  The DSN in `.env` uses `database` because Symfony only reads it from
  inside the container; from the host use `127.0.0.1`.
- **CSRF gotcha.** Symfony 7's `SameOriginCsrfTokenManager` is enabled
  by default (see `config/packages/csrf.yaml`). The HTML the server
  renders contains the literal string `value="csrf-token"` in the hidden
  CSRF input. **This is the placeholder, not a real token.** Real
  validation is done by checking the `Origin` / `Referer` headers and a
  cookie double-submit. If you write a curl test for a POST, you must
  send `-H "Origin: http://localhost:8000" -H "Referer: http://localhost:8000/login"`,
  otherwise the firewall will reject the request with a 302 to `/login`.
  A regular browser sends these headers automatically — this only
  affects CLI / curl / API tests.
- **`User` table is `app_user`, not `user`.** Postgres reserves `user`.
  Renaming the entity to use `user` later will break unquoted queries.
  See hard rule #11.
- **Form field `roles` on the User entity is shadowed by
  `User::getRoles()`.** `getRoles()` is required by `UserInterface` to
  return a `string[]` of role names; the underlying property is also
  called `roles` and is a `Collection<Role>`. If you bind a Symfony
  form to the User with a field named `roles` and let it use
  reflection-based getter resolution, the form will read the string
  array, not the collection, and the IdReader will choke. The current
  `UserRolesType` works around this by using a model transformer
  (`Collection` ↔ `array<Role>`) and a `getter` closure that points
  at `getRoleEntities()`. If you add a new form that binds to User's
  roles, follow the same pattern or set the `getter` explicitly.

---

## When the user asks you to "add a feature," default checklist

1. Is it a UI change? Read `.opencode/skills/frontend-design/SKILL.md` and
   the "Design guidance" section above first.
2. Is it a backend change? Identify the controller, entity, or service that
   owns the area. Add the route via `#[Route]` attribute.
3. Does it need a database change? Edit the entity, run `make:migration`,
   review the generated migration, run `migrations:migrate`.
4. Does it need a new Composer package? Use the sidecar, never the host.
5. Does it need a new permission? Edit `App\Security\Permission`, write a
   migration that inserts the new constant, run migrations, assign the
   permission to the relevant roles via the backoffice.
6. Does it add Tailwind classes the build doesn't know about? `npm run
   build` (or rely on `npm run dev` for HMR).
7. **Do not commit.** Stop, summarize what you did, and let the user commit.
