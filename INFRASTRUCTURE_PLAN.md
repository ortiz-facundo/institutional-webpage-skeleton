# Nova Argentum — Symfony Infrastructure Plan

## Stack (confirmed)
- **App layout:** Symfony at repo root (`/home/aku/dev/web-nova-argentum`)
- **PHP 8.3 / Symfony 7.2 / Twig / Maker / Profiler / symfony/apache-pack**
- **Doctrine ORM** (`symfony/orm-pack`) — adds `doctrine/orm`, `doctrine-bundle`, `doctrine-migrations-bundle`
- **PostgreSQL 16** in Docker
- **pentatrion/vite-bundle 8.x** + **Vite 5** + **Tailwind 3.4** + **PostCSS 8** + **Autoprefixer 10**
- **Composer via `composer:2` service in Docker** (no host PHP)
- **Apache port: 8000** (8080 is taken by WordPress but will be freed in Step 0)

---

## Step 0 — Free the workspace

> **Scope:** read-only stop of the two competing containers. Nothing in this project is touched.

```bash
docker stop skeleton-web-wp-woo-wordpress-1 skeleton-web-wp-woo-mysql-1
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
```

After this: port 8080 is free, port 3306 is free (we won't reuse either — Symfony on 8000, Postgres on 5432). Their data persists in Docker volumes; this is a stop, not a remove.

**Result:** Symfony infra has the machine to itself until you decide to restart the WordPress project.

---

## Step 1 — Docker infrastructure (Apache + PHP + Postgres + Composer sidecar)

**Files to create at repo root:**

### `Dockerfile` (PHP 8.3 + Apache)
- Base: `php:8.3-apache`
- `apt-get install` system libs: `libicu-dev` (intl), `libpq-dev` (pdo_pgsql), `libzip-dev` (zip), `unzip`, `git`
- `docker-php-ext-install intl opcache pdo_pgsql zip`
- `pecl install apcu && docker-php-ext-enable apcu`
- `a2enmod rewrite` and `a2dissite 000-default`
- Drop a custom Apache vhost `nova-argentum.conf` → `DocumentRoot /var/www/html/public`, `<Directory /var/www/html/public> AllowOverride All Require all granted</Directory>`
- `docker-php-ext-enable opcache` + a `zz-opcache.ini` (recommended settings for dev)
- `chown -R www-data:www-data /var/www/html` for bind-mount friendliness
- `EXPOSE 80`

### `docker-compose.yml`
```yaml
name: nova-argentum
services:
  php:
    build: .
    container_name: nova-argentum-php
    ports: ["8000:80"]
    volumes:
      - ./:/var/www/html
      - vendor:/var/www/html/vendor
      - var:/var/www/html/var
      - node_modules:/var/www/html/node_modules
    depends_on:
      database:
        condition: service_healthy
    environment:
      DATABASE_URL: "postgresql://app:!ChangeMe!@database:5432/app?serverVersion=16&charset=utf8"
  database:
    image: postgres:16-alpine
    container_name: nova-argentum-db
    environment:
      POSTGRES_DB: app
      POSTGRES_USER: app
      POSTGRES_PASSWORD: "!ChangeMe!"
    volumes:
      - db_data:/var/lib/postgresql/data
    ports: ["127.0.0.1:5432:5432"]
    healthcheck:
      test: ["CMD", "pg_isready", "-U", "app", "-d", "app"]
      interval: 5s
      timeout: 5s
      retries: 10
  composer:
    image: composer:2
    container_name: nova-argentum-composer
    volumes:
      - ./:/app
      - vendor:/app/vendor
    working_dir: /app
    profiles: ["tools"]   # only started when explicitly requested
volumes:
  vendor:
  var:
  node_modules:
  db_data:
```
- Bind-mount `./:/var/www/html` for hot-reload of source code
- Named volumes for `vendor/`, `var/`, `node_modules/` (avoids host/container perf/sync issues)
- `composer` service uses `profiles: [tools]` so it doesn't start with `docker compose up` — only when needed via `docker compose run --rm --profile tools composer ...`

### `.dockerignore`
Excludes `.git`, `node_modules`, `vendor`, `var`, `public/build`, `.env.local`, `*.log`, `.opencode`, `Logo*.jpeg` (no need to copy assets into the build context).

---

## Step 2 — Symfony initialization

**Approach:** since the repo is non-empty, scaffold in a temp dir and `rsync` into the root (handles dotfiles cleanly, never overwrites `Logo*.jpeg` or `.opencode/`).

```bash
TMP=$(mktemp -d)
docker compose run --rm --profile tools composer create-project symfony/skeleton:^7.2 "$TMP" --no-interaction
rsync -a "$TMP"/ /home/aku/dev/web-nova-argentum/
rm -rf "$TMP"
docker compose run --rm --profile tools composer require \
  twig \
  symfony/orm-pack \
  symfony/maker-bundle:^1 \
  symfony/profiler-pack \
  symfony/apache-pack \
  pentatrion/vite-bundle
docker compose run --rm --profile tools composer require --dev doctrine/doctrine-fixtures-bundle
```

What this produces at repo root (in addition to existing `Logo1.jpeg`, `Logo2.jpeg`, `.opencode/`):
- `bin/console`, `composer.json`, `composer.lock`, `symfony.lock`
- `config/` (bundles.php, packages/, routes/, services.yaml)
- `public/index.php` (+ `.htaccess` from apache-pack)
- `src/Kernel.php`, `src/Controller/.gitignore`, `src/Entity/.gitignore`
- `templates/` (base.html.twig from twig recipe)
- `var/`, `vendor/` (named volumes, not in git)
- `.env`, `.env.dev`, `.env.test`, `.env.test.example`
- `assets/app.js` (entry, from vite-bundle recipe)
- `config/packages/vite.yaml` (from vite-bundle recipe)
- Updated `templates/base.html.twig` (vite-bundle recipe injects the entry tags)
- `migrations/` (doctrine-migrations)
- Updated root `.gitignore` (Symfony recipe will add `var/`, `vendor/`, `public/build/`, etc. — I will merge it with the existing 4-line file rather than clobbering it)

**Will not touch:** `Logo1.jpeg`, `Logo2.jpeg`, `.opencode/`, `.git/`, the existing root `.gitignore` entries (`node_modules/`, `.DS_Store`, `.vscode/`, `.idea/`).

**Manual inputs:** none — Symfony Flex recipes run automatically and don't prompt in non-interactive mode.

---

## Step 3 — Vite + Tailwind + PostCSS + Autoprefixer

Host already has Node 18.19 and npm 9.2 (sufficient for Vite 5, Tailwind 3.4).

```bash
# These are run on the HOST (not in Docker) so HMR is instant and we keep host performance
npm init -y >/dev/null  # only if pentatrion's recipe didn't already create package.json
npm install --save-dev vite@^5 @symfony/vite-dev-runtime tailwindcss@^3.4 postcss@^8 autoprefixer@^10
npx tailwindcss init -p
```

If `pentatrion/vite-bundle`'s recipe already wrote `package.json` (likely), I'll review it and merge dependencies rather than `npm init -y`. The `npm install` will be adjusted accordingly.

### `vite.config.js` (only if recipe didn't already write a usable one)
```js
import { defineConfig } from 'vite';
import symfonyVite from '@symfony/vite-dev-runtime';

export default defineConfig({
  plugins: [symfonyVite()],
  server: { host: '0.0.0.0', port: 5173, strictPort: true },
  build: {
    rollupOptions: {
      input: { app: './assets/app.js' },
    },
  },
});
```

### `tailwind.config.js` (overwritten by `tailwindcss init -p`)
```js
/** @type {import('tailwindcss').Config} */
export default {
  content: ['./templates/**/*.{html,twig,js}', './assets/**/*.{js,ts}'],
  theme: { extend: {} },
  plugins: [],
};
```
I'll edit the `content` globs to include Twig templates.

### `postcss.config.js`
```js
export default {
  plugins: { tailwindcss: {}, autoprefixer: {} },
};
```

---

## Step 4 — Tailwind & Twig integration

**`assets/app.css`** (created):
```css
@tailwind base;
@tailwind components;
@tailwind utilities;
```

**`assets/app.js`** (verify the recipe-created one imports the css):
```js
import './app.css';
console.log('Nova Argentum entry loaded');
```

**`.env.local`** (create, gitignored) for Vite HMR config:
```
VITE_SERVER_URL=http://localhost:5173
```

**`templates/base.html.twig`** (review + add explicit Vite tags if recipe didn't). Final form:
```twig
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{% block title %}Nova Argentum{% endblock %}</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🪙</text></svg>">
    {{ vite_entry_link_tags('app') }}
    {% block stylesheets %}{% endblock %}
</head>
<body>
    {% block body %}{% endblock %}
    {{ vite_entry_script_tags('app') }}
    {% block javascripts %}{% endblock %}
</body>
</html>
```

---

## Step 5 — Verification: HomeController + styled landing

```bash
docker compose run --rm --profile tools composer exec php bin/console make:controller HomeController
```

This creates:
- `src/Controller/HomeController.php` → `#[Route('/', name: 'home')] public function index(): Response { return $this->render('home/index.html.twig'); }`
- `templates/home/index.html.twig` (extends `base.html.twig`)

I'll overwrite `templates/home/index.html.twig` with:
```twig
{% extends 'base.html.twig' %}
{% block title %}Nova Argentum — Home{% endblock %}
{% block body %}
<main class="min-h-screen flex items-center justify-center bg-slate-100">
  <div class="bg-purple-900 text-white font-bold p-10 rounded-2xl shadow-2xl text-center max-w-xl">
    <h1 class="text-4xl mb-3">Nova Argentum</h1>
    <p class="text-lg font-normal opacity-90">
      Infrastructure online — Apache ✓ Twig ✓ Vite HMR ✓ Tailwind ✓
    </p>
  </div>
</main>
{% endblock %}
```

**End-to-end smoke test** (only if you want me to run it; otherwise the verification is for you to do):

```bash
docker compose up -d --build
# wait ~10s for postgres healthcheck
docker compose run --rm --profile tools composer exec php bin/console doctrine:database:create --if-not-exists
# start vite dev server in another terminal
npm run dev
# open http://localhost:8000 — should show the purple card
```

If any of those fail, **I stop and ask per your instruction**.

---

## Files that will be created or modified (summary)

| Path | Created/Modified | Purpose |
|---|---|---|
| `Dockerfile` | created | PHP 8.3 + Apache + extensions + vhost |
| `docker-compose.yml` | created | php / database / composer services |
| `.dockerignore` | created | slim build context |
| `.gitignore` | **merged** (not overwritten) | adds Symfony entries to existing 4-line file |
| `composer.json` / `composer.lock` / `symfony.lock` | created | Symfony deps |
| `bin/console` | created | Symfony CLI |
| `config/` (bundles, packages, routes, services) | created | Symfony config |
| `public/index.php` + `public/.htaccess` | created | Front controller + Apache routing |
| `src/Kernel.php`, `src/Controller/`, `src/Entity/` | created | App code skeleton |
| `templates/base.html.twig`, `templates/home/index.html.twig` | created/edited | Twig + Vite + Tailwind demo |
| `assets/app.js`, `assets/app.css` | created | Vite entry + Tailwind directives |
| `vite.config.js`, `tailwind.config.js`, `postcss.config.js` | created/edited | Build configs |
| `package.json` | created/merged | Node deps |
| `migrations/` | created | Doctrine migrations |
| `.env`, `.env.dev` | created | Symfony env |
| `.env.local` | created (gitignored) | Vite HMR URL |
| `src/Controller/HomeController.php` | created | Verification route |

**Preserved untouched:** `Logo1.jpeg`, `Logo2.jpeg`, `.opencode/`, `.git/`.

---

## Confirmation needed before I execute

Please confirm with a single "go" (or specify changes) and I'll execute Steps 0 → 5 in order, stopping at the first failure per your instruction. If you'd rather approve each step individually, say so and I'll pause after each.
