# BRANCHING.md — Skeleton workflow

> How the skeleton relates to the downstream projects and how generic
> improvements flow between them.

## Repositories

| Role | Repository | Purpose |
|---|---|---|
| Skeleton (upstream) | `symfony-backoffice-skeleton` | Common code: auth, RBAC, i18n, CRUD base, deploy tooling. No public homepage. |
| Downstream A | `web-nova-argentum` | Adds a public marketing site, a contact form, and the cPanel deploy tooling. |
| Downstream B | `sci_gestion` | General administration system for a textile company (Scachi — Indumentaria Institucional). Today ships the Catalog and Inventory modules; future modules will be added on the same architecture. |

The three repos share the commit `4f9d474b65b222dc1b591ff14b8962a77da11b24` ("feat(i18n): full bilingual sweep") as a real git ancestor. Every downstream's `main` is descended from this commit, so `git rebase skeleton/main` from a downstream is a normal rebase — no `--allow-unrelated-histories`, no `--onto`, no merge commits.

## Layout

```
symfony-backoffice-skeleton/        ← this repo (upstream)
web-nova-argentum/                  ← downstream A
sci_gestion/                        ← downstream B
```

All three live in the same parent folder. Each downstream registers the skeleton as a git remote named `skeleton` (already done in this workspace; verify with `git remote -v`).

## Daily workflow

### Generic improvement lands in the skeleton

```bash
cd symfony-backoffice-skeleton
git checkout -b feat/<short-name>
# ... edit, lint, test ...
git commit -m "feat(...): <description>"
git checkout main
git merge --no-ff feat/<short-name>
git push origin main
```

### Each downstream pulls it

```bash
cd ../web-nova-argentum     # or sci_gestion
git fetch skeleton
git rebase skeleton/main
# resolve conflicts if any, then:
git rebase --continue
docker compose exec php bin/console cache:clear
git push origin main
```

That's the whole cycle. No `--allow-unrelated-histories`, no merge commits, no integration branches.

### Downstream-only feature lands

```bash
cd ../web-nova-argentum     # or sci_gestion
git checkout -b feat/<short-name>
git commit ...
git checkout main
git merge --no-ff feat/<short-name>
git push origin main
```

If the feature is generic enough to belong in the skeleton, lift it:

```bash
cd ../symfony-backoffice-skeleton
git remote add nova git@github.com:ortiz-facundo/web-nova-argentum.git   # one-time, if not already there
git fetch nova
git cherry-pick <commit-sha>          # lift the generic bits
git push origin main
# now both downstreams can pull it via the daily rebase workflow
```

## Verifying after every rebase

```bash
git merge-base skeleton/main main      # from a downstream — should return the skeleton's tip
git log --oneline skeleton/main..main  # the commits the rebase added
docker compose exec php bin/console cache:clear
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction   # if migrations are involved
docker compose exec php bin/console lint:twig templates/
docker compose exec php bin/console lint:yaml translations/
docker compose exec php bin/console lint:translations --locale=en
docker compose exec php bin/console lint:translations --locale=es
```

Open the affected surfaces in the browser and confirm the expected behaviour:
- Skeleton (`http://localhost:8000`) — backoffice login, user CRUD, profile settings.
- web-nova-argentum (`http://localhost:8020`) — public homepage, contact form, backoffice.
- sci_gestion (`http://localhost:8010`) — backoffice, catalog, inventory.

## Conflict resolution

Conflicts during a rebase are normal — they mean the downstream edited the same lines as the skeleton improvement. Resolution rules:

- **Skeleton behaviour wins for shared code** (auth, RBAC, i18n, CRUD base, deploy tooling, profile settings, form theme).
- **Downstream brand wins for chrome** (project name in templates, brand colour in `tailwind.config.js` if added).
- **Downstream-only files** (e.g. `templates/home/`, `templates/contact/` in web-nova-argentum; `templates/catalog/`, `templates/inventory/` in sci_gestion) almost never conflict — accept either side.

If a conflict is hard to resolve, prefer `git merge skeleton/main` on a feature branch and rebase that branch onto `main` in small steps. Slower but more predictable.

## Watch-outs

1. **Skeleton's `main` is force-pushed only when the rescue is redone.** That has happened once (Aug 2026, "feat: bootstrap symfony-backoffice-skeleton from web-nova-argentum at 4f9d474") and is not a routine operation. Routine `main` updates on the skeleton are normal pushes.
2. **Dev environments use different host ports** so they can run concurrently:
   - Skeleton: `8000` (PHP), `33067` (DB), `1025`+`8025` (Mailpit)
   - web-nova-argentum: `8020` / `33076` / `1045`+`8045`
   - sci_gestion: `8010` / `33066` / `1035`+`8035`
3. **Vite assets in `public/build/` are gitignored.** After any change to a Twig template or `assets/scripts/*`, run `npm run build` (or keep `npm run dev` running for HMR). Stale built bundles are a common source of "the button doesn't work" reports.
