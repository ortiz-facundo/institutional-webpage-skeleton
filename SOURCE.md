# Source lineage

- Infrastructure extracted from `symfony-backoffice-skeleton` at
  `32638c337622d9b9feafa57d4ba3cda5adf32400` (current source at initialization).
- Contact-mail behavior adapted from `web-nova-argentum` at
  `5568b5a` (clean source at inspection).
- Both source repositories were inspected read-only and retain their existing work.

The institutional repository preserves the backoffice history; removing the
backoffice files is an intentional extraction in the new repository's working
tree. Composer retains compatible existing locked versions while removing unused
backoffice dependencies and adding functional test tools. Nova marketing layouts,
company categories, branding, sender/recipient defaults and deployment installer
are not part of this foundation.

The reusable contact module lives under `src/{Controller,Form,Mailer}` and
`templates/contact`; no persistent Contact entity or website database is needed.

## Dependency baseline

Initialization preserves Vite 5 and Tailwind 3.4 compatibility with the existing
projects. Composer validation/audit passes. The npm audit currently reports
8 development-tool advisories (7 high, 1 moderate) involving Vite/esbuild and the
Tailwind/plugin glob dependencies; npm's suggested fixes include major version
changes. These build tools are not included in the production release. Evaluate
their upgrade as a shared skeleton maintenance task; no forced major upgrade was
applied during this extraction. The Vite dev server binds to loopback by default.
