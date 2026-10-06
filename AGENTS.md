# Institutional website agent instructions

Read `PROJECT.md` if present, then `README.md`, `BRANCHING.md` and `SOURCE.md`.
This project is public-only: blank homepage and unmounted reusable contact partial.
Keep company presentation in `config/site.yaml`, `assets/brand.js`, `public/images`
and downstream layouts. No homepage design is authorized during initialization.

- Preserve existing user work. Never stage, commit, push, amend, create branches
  or run history-reset operations unless explicitly requested.
- PHP/Composer run in Docker; npm runs on the host. Use the commands in README.
- Do not edit vendor, node_modules, var, public/build or generated lockfiles by hand.
- Keep Apache/docker support directories in the Docker build context.
- Vite bundle configuration uses `pentatrion_vite`, never `vite`.
- Rebuild frontend assets after template/Tailwind changes.
- Contact inquiries remain request-scoped; no website CMS or database by default.
- Keep sender/recipient configuration out of controllers and templates; visitor
  email is Reply-To, never the site sender or a caller-selected recipient.
- Do not weaken CSRF, validation, rate limiting, honeypots or escaping while
  customizing markup. Keep test-only routes absent from dev/prod.
- Never copy local secrets, generated files or inherited backoffice modules into
  new downstreams. Review inherited code and establish the Git tag baseline.
- Keep en/es contact translation catalogs aligned.
- Shared improvements belong in the institutional skeleton. Company-specific
  work stays downstream; tell the owner when a shared fix needs propagation.

## Mandatory Git and Engram procedure

- This is the public upstream, using SCI Gestión's maintenance model. The
  backoffice skeleton is a historical ancestor, not the websites' direct parent.
- Follow `BRANCHING.md`: downstreams use remote `skeleton`, tag `skeleton-sync`
  and oldest-first bounded cherry-picks. Never merge/rebase full history.
  Record applied/omitted SHAs, verify, then advance to the reviewed endpoint.
  Cherry-picks and tag mutations require explicit authorization like commits.
- `SKELETON_BASELINE.json` is legacy initializer output, not sync state.
- Reusable downstream contact/runtime/frontend/deployment changes are proposed
  in Engram under their originating project. Do not propose company branding,
  business data or changes received from this skeleton.
- Proposal: type `proposal`, title `Skeleton proposal: <feature>`, topic key
  `skeleton-proposal/<slug>`. Content: `**State**: pending`, `**What**`,
  `**Why generic**` (target `institutional-webpage-skeleton`), `**Commits**`
  (SHAs or `uncommitted`), `**Where**`. BRANCHING supplies the full template.
- Search proposals across projects, read full observations and filter pending
  items by institutional target. Review decisions with the owner; inspect the
  source, generalize accepted changes, verify and include
  `Source: <originating-downstream>` in the authorized upstream commit.
- Use mem_update on the original proposal: `implemented (skeleton <sha>)` only
  after a verified commit exists, or `discarded (<reason>)` after rejection.
  Uncommitted implementation stays pending; add source SHAs when available.
- Tell the owner which websites need propagation. Source downstreams verify and
  omit represented originals; others review the new upstream commit. Do not
  automatically sync downstreams. Record outcomes in Engram before delivery.
