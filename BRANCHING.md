# Institutional website maintenance

## Relationships

`symfony-backoffice-skeleton` supplied the initial infrastructure. The
institutional skeleton is now a separate **public-only** foundation. Its local
`backoffice` remote is for provenance and deliberately has no branch tracking.
Do not merge backoffice history wholesale into public websites.

Company websites register the institutional skeleton as `skeleton`. Their `main`
has no automatic upstream tracking; `origin` should point to their own hosted
repository when the owner supplies its URL. No hosted remotes are invented.

Shared improvements (contact behavior, runtime/build/deployment tooling) belong
in the institutional skeleton. Company copy, layouts, palette and brand assets
belong in company repositories. Keep shared fixes atomic and mark generalized
downstream fixes with `Source: <company-project>` when appropriate.

## Initial uncommitted extraction

This initialization intentionally makes **no commits**. Both new repositories
retain the backoffice ancestor, and institutional files/deletions are pending
working-tree changes. Therefore `skeleton/main` initially still refers to the
backoffice ancestor, not to a committed institutional release.

`SKELETON_BASELINE.json` in a generated company project records the source HEAD,
whether uncommitted work was included, and per-file SHA-256 hashes **before**
company customization. A null `institutional_baseline_commit` means there is not
yet a committed institutional baseline. Do not sync or tag the old backoffice
HEAD as an institutional baseline.

When the owner commits the institutional foundation, review that commit against
the company's source snapshot; its code is already represented in the company
working tree. After committing the company initialization, fetch the skeleton
and set the company's `skeleton-sync` tag to the reviewed institutional commit.
Update `institutional_baseline_commit` in the metadata at that time. Do not replay
the whole extraction onto a company that already contains it.

## Subsequent syncs (owner-controlled)

With clean working trees and an established `skeleton-sync` tag:

```bash
git fetch skeleton
git log --reverse --oneline skeleton-sync..skeleton/main
```

Review each commit in order. Cherry-pick applicable shared improvements, resolve
company configuration in favor of the company, and explicitly skip inapplicable
changes or commits generalized from that same company. Advance `skeleton-sync`
only after all commits are accounted for and verification passes.

Run `npm run build`, container/YAML/Twig lints, PHPUnit and SMTP smoke checks after
a relevant sync. Tags, commits, staging and pushes are performed only when the
owner requests them. A local skeleton remote can later be changed to a hosted URL.
