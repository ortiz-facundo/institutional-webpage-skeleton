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
  new downstreams. Use the initializer and inspect its source-baseline metadata.
- Keep en/es contact translation catalogs aligned.
- Shared improvements belong in the institutional skeleton. Company-specific
  work stays downstream; tell the owner when a shared fix needs propagation.
