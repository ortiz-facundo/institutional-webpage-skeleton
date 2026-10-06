# Institutional skeleton — Git and Engram workflow

## Ownership

Repository: `git@github.com:ortiz-facundo/institutional-webpage-skeleton.git`
(`origin`). Local checkout: `/home/aku/dev/institutional-webpage-skeleton`.

This public-only foundation is the direct upstream of institutional websites,
including Scachi's `sci_webpage`. `symfony-backoffice-skeleton` is the historical
infrastructure ancestor, not the websites' direct synchronization parent.
Use the same tag-bounded cherry-pick and Engram proposal model as SCI Gestión.

Shared contact behavior, runtime, reusable frontend utilities and deployment
tooling belong here. Company copy, home layouts, palette, logos, recipient
configuration and business-specific behavior stay downstream.

## Two-way flow

```text
Skeleton commit → downstream review → cherry-pick/omit → verify → skeleton-sync

Downstream improvement → Engram proposal → skeleton review
                           ├→ discarded (reason)
                           └→ generalize → verify → commit + Source trailer
                                             └→ implemented (skeleton SHA)
                                             └→ available to other downstreams
```

## Forward flow: tag-bounded cherry-picks

Each downstream has a `skeleton` remote and a local `skeleton-sync` tag pointing
to the last **upstream** commit accounted for, including intentionally omitted
commits. It need not be an ancestor of downstream `main` after cherry-picking.
Never merge/rebase the whole skeleton history into a company website.

From the downstream, after obtaining authorization and checking for a clean tree:

```bash
git fetch skeleton
git rev-parse skeleton-sync
git rev-parse skeleton/main
git log --reverse --format=full skeleton-sync..skeleton/main
```

Capture the old tag and exact upstream endpoint. Review that fixed range oldest
first. Cherry-pick applicable atomic improvements with `git cherry-pick <sha>`.
Record intentionally omitted SHAs and reasons. For a matching
`Source: <this-downstream>` trailer, verify the original functionality is already
present and omit the generalized version to avoid a feedback loop.

Preserve company branding/configuration while incorporating shared fixes.
Use `git cherry-pick --continue` for resolved active conflicts; `--skip` is only
for an active cherry-pick, not for a commit omitted during review.

Run relevant README checks: frontend build for template/asset changes,
container/YAML/Twig lints, PHPUnit, initializer tests and SMTP smoke as affected.
After all commits through the reviewed endpoint are accounted for and checks pass:

```bash
git tag -f skeleton-sync <reviewed-upstream-sha>
git log skeleton-sync..<reviewed-upstream-sha> --oneline
```

Keep the applied/omitted list and verification results in the sync summary;
an empty range after moving the tag is not by itself proof of application.
Publishing main or creating/updating a remote tag requires an explicit request.
Do not automatically force-push a moved tag.

### Initial watermark

Compare shared source before creating `skeleton-sync` at the upstream commit
already represented downstream. Do not point it at the backoffice ancestor or
blindly at latest HEAD. The committed institutional foundation is
`9a1d9070d7d3cc6d379113da4286fe6130776655`; Scachi's initialization is `e22b2b8`.
Scachi's initial tag still needs reviewed, owner-authorized setup.

`SKELETON_BASELINE.json` is legacy initializer output, not synchronization state.
The current initializer still emits it; changing that generator is separate
tooling work. Git review and the tag are the maintenance mechanism.

## Reverse flow: structured Engram proposals

After finishing a reusable downstream improvement, save it in the originating
project, never as a proposal for work received from this skeleton:

- `type`: `proposal`
- `topic_key`: `skeleton-proposal/<kebab-case-feature>`
- `title`: `Skeleton proposal: <short feature name>`
- `project`: originating downstream, e.g. `sci_webpage`

```markdown
**State**: pending
**What**: What the change does.
**Why generic**: Which reusable behavior/tool it touches and why others benefit. Target: institutional-webpage-skeleton.
**Commits**: <downstream SHA(s), or "uncommitted">
**Where**: Main files.
```

Generic contact protection/delivery is shared here; company-specific form fields
and contact details are not. Reuse the topic key and update committed SHAs when
available. Store code facts, never secrets or business/customer data.

### AI review procedure

1. Read this repository's instructions and inspect existing work.
2. Search Engram across projects for `Skeleton proposal` with type `proposal`.
   Read full observations, retaining only pending proposals targeting the
   institutional skeleton. Backoffice-only proposals belong to its sibling.
3. Inspect source changes and review acceptance/rejection with the owner.
4. Generalize accepted behavior and verify it. Cherry-pick an atomic generic
   source commit when suitable; otherwise port only the reusable portion.
5. With explicit authorization, commit the generalized work with a trailer:

   ```text
   Source: <originating-downstream>
   ```

   Include the trailer before committing; do not amend without permission.
6. Update the original proposal via `mem_update`: `implemented (skeleton <sha>)`
   only after a verified upstream commit exists, or `discarded (<reason>)` after
   rejection. Verified but uncommitted work stays `pending` with a progress note.
7. Tell the owner which downstreams need sync. Their owner chooses when to apply
   it. The source downstream omits its represented original; others review it.

## Agent boundaries

Documentation of commands is not permission to execute them. Staging, commits,
pushes, amends, branch creation, cherry-picks and tag mutations need explicit
authorization. Preserve user work and record proposal decisions/sync outcomes in
Engram. Report affected repositories, applied/omitted SHAs, checks, tag status and
remaining steps.
