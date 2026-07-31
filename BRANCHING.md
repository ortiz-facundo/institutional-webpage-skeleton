# BRANCHING.md — Skeleton workflow

> How this skeleton relates to the downstream projects and how generic
> improvements flow back and forth.

## Repositories

| Role | Repository | Purpose |
|---|---|---|
| Skeleton (upstream) | `symfony-backoffice-skeleton` | Common code: auth, RBAC, i18n, CRUD base, deploy tooling. No public homepage. |
| Downstream A | `web-nova-argentum` | Adds a public marketing site, a contact form, and the cPanel deploy tooling. |
| Downstream B | `sci_gestion` | General administration system for a textile company (Scachi — Indumentaria Institucional). Today ships the Catalog and Inventory modules; future modules will be added on the same architecture. |

The skeleton is the **ancestor** of the two downstreams. Generic improvements
land in the skeleton first and then propagate to the downstreams. The
downstreams add domain-specific work on top — public homepage, contact form,
catalog, inventory, etc. — that does not get pushed back.

## Layout

```
symfony-backoffice-skeleton/        ← this repo (upstream)
web-nova-argentum/                  ← downstream A
sci_gestion/                        ← downstream B
```

Both downstreams live in the same parent folder as this skeleton. Each
downstream registers this skeleton as a git remote named `skeleton`.

## Adding the skeleton remote (one-time, in each downstream)

```bash
cd web-nova-argentum          # or: cd sci_gestion
git remote add skeleton git@github.com:ortiz-facundo/symfony-backoffice-skeleton.git
git fetch skeleton
```

Verify with `git remote -v` — you should see `skeleton` listed alongside the
project's own `origin`.

## One-time rebase: aligning history with the skeleton

Before the daily workflow can take advantage of `git rebase skeleton/main`,
each downstream's `main` needs to be rebased onto the skeleton. Two
path-specific commands exist; pick the one that matches the downstream.

### `web-nova-argentum`

The 4f9d474 commit is the fork point. The downstream added 8 commits on top:
homepage work, contact form, MariaDB parity, autofill fix, and the cPanel
deploy tooling. Of those, the homepage and contact-form commits drop during
the rebase (the skeleton has no public site); the MariaDB and deploy
commits reapply cleanly.

```bash
cd web-nova-argentum
git checkout -b rebase/skeleton-upstream
git rebase --onto skeleton/main 4f9d474 main
```

Resolve any conflicts. If the rebase is clean, the resulting `main` will
look like:

```
* (skeleton/main)  ← everything from the skeleton
* (deploy commit)  ← MariaDB parity + cPanel deploy tooling
* (skeleton/main)  ← MariaDB parity + cPanel deploy tooling
```

…with the homepage and contact-form commits dropped.

### `sci_gestion`

The 4f9d474 commit is also the fork point. The downstream added 20 commits
on top: brand scrub, port shift, CI/FTP workflow, cPanel deploy, MariaDB
parity, and the catalog and inventory domain work. The brand-scrub, port-shift,
CI-rename, and migrations-conversion commits drop during the rebase; the
catalog/inventory, deploy, and MariaDB commits reapply.

```bash
cd sci_gestion
git checkout -b rebase/skeleton-upstream
git rebase --onto skeleton/main 4f9d474 main
```

### Validating the rebase

After the rebase, in each downstream:

```bash
docker compose down -v && docker compose up -d --build
docker compose exec php bin/console doctrine:database:create --if-not-exists
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:fixtures:load --append
docker compose exec php bin/console cache:clear
npm install && npm run build
curl -sI http://localhost:8000/ | head -2   # should be 302 → /login
```

Open the backoffice and confirm the test user can log in.

## Daily workflow

### Pushing a generic improvement to the skeleton

Generic improvements (RBAC, auth, i18n, CRUD base, profile settings, deploy
tooling) go to the skeleton first. The downstream projects pull them in
after.

```bash
# 1. Develop and commit in the skeleton
cd symfony-backoffice-skeleton
git checkout -b feat/<short-name>
# ... work ...
git commit -m "feat(backoffice): <description>"
git push skeleton feat/<short-name>

# 2. Merge to main in the skeleton (or via a PR if the project uses one)
git checkout main
git merge --no-ff feat/<short-name>
git push skeleton main

# 3. Pull into each downstream
cd ../web-nova-argentum
git fetch skeleton
git rebase skeleton/main

cd ../sci_gestion
git fetch skeleton
git rebase skeleton/main
```

### Cherry-picking a single skeleton commit

If a downstream cannot rebase (e.g. its `main` has diverged further), use
`git cherry-pick`:

```bash
git fetch skeleton
git cherry-pick <commit-sha>
```

### Lifting a downstream improvement into the skeleton

Some downstream work is generic enough to belong in the skeleton (e.g. a
new CRUD widget, a new RBAC pattern). The flow is reversed:

```bash
# 1. In the downstream, identify the commit(s) to lift
cd ../web-nova-argentum
git log --oneline -- <path-that-is-generic>

# 2. In the skeleton, cherry-pick them
cd ../symfony-backoffice-skeleton
git fetch web-nova-argentum   # assumes it's also a remote in the skeleton
git cherry-pick <commit-sha>

# 3. Then drop the cherry-picked commit from the downstream's feature
# branch and rebase onto the new skeleton state
cd ../web-nova-argentum
git rebase skeleton/main
```

### Branch strategy

In each downstream:

- `main` — always deployable, never rebased after the one-time rebase.
- `rebase/skeleton-upstream` (long-lived) — the integration branch. New
  skeleton commits are rebase-merged here first, validated, then
  fast-forwarded into `main`.
- `feature/*` — normal work. Merged into `main` first, then propagated to
  the skeleton via the reverse-flow above.

This keeps `main` simple and avoids the "skeleton pulled in some random
feature" surprise.

## Conflict resolution

Most conflict-driven rebases happen in the auto-generated files:

- `composer.lock` — discard the skeleton's lock and run
  `docker compose --profile tools run --rm composer update --lock` to
  regenerate.
- `public/build/assets/*` — never committed; safe to discard.
- `var/` — never committed; safe to discard.
- `tailwind.config.js` — domain-specific extensions (Nova Argentum adds
  brand purple; sci_gestion adds its textile-industry palette). Resolve by
  keeping the downstream's extensions and re-applying the skeleton's
  structural changes.

For migration files, the renumbering in the skeleton sometimes collides with
domain migrations in the downstream. Renumber the skeleton's migration to be
chronologically later than the downstream's highest migration, then re-run
`doctrine:migrations:migrate --no-interaction` to confirm it applies cleanly.

## Verifying after every rebase

```bash
docker compose down -v && docker compose up -d --build
docker compose exec php bin/console doctrine:database:create --if-not-exists
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:fixtures:load --append
docker compose exec php bin/console cache:clear
npm install && npm run build
docker compose exec php bin/console lint:twig templates/
docker compose exec php bin/console lint:yaml translations/
docker compose exec php bin/console lint:translations --locale=en
docker compose exec php bin/console lint:translations --locale=es
```

Skim the backoffice for 5 minutes. Confirm login, profile, RBAC, and any
domain-specific page that was touched.

## When the rebase is too painful

If the rebase resolves to more than ~50 conflicts, prefer `git merge
skeleton/main` on a feature branch and then rebase that branch onto `main`
in small steps. This is slower but more predictable.

If the rebase keeps breaking because the downstream has truly diverged
(e.g. a parallel implementation of the same module), consider whether the
downstream should drop its parallel implementation and adopt the skeleton's.
The skeleton is the canonical source for cross-cutting code.
