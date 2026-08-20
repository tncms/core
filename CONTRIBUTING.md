# Contributing to TNCMS Core

Thanks for your interest in improving TNCMS Core. This repository is the **Core**
CMS only (engine + `plugins/hello-world` + `themes/default`). Extensions such as
Page Builder, Ecommerce, and Knowledge Library are developed and distributed
separately — please do not add them here.

## Scope

- **Core PRs must stay Core-only.** Do not bundle unrelated plugin or theme work
  into a Core change.
- Match the existing architecture and authorities (installer, upgrade, and
  distribution tooling under `tools/distribution/`). Don't introduce a second
  mechanism where one already exists.

## Workflow

1. Fork the repository and create a focused branch.
2. Make a small, coherent change that traces to a single purpose.
3. Add or update tests for your change.
4. Run the relevant test suites (for example the Core distribution and upgrade
   suites under `tests/Feature/`).
5. Check your diff:
   ```bash
   git diff --check
   git status --short
   ```
6. Keep commits narrow and stage exact paths — never `git add .`.
7. Open a pull request describing **what** changed, **why**, and how to
   reproduce/verify it.

## Ground rules

- No secrets, credentials, tokens, or private data in commits, tests, or docs.
- No unrelated formatting or refactoring in a functional change.
- Follow the coding style already present in the files you touch.
- Prefer many small, cohesive files over large ones.

## Reporting bugs & requesting features

See [SUPPORT.md](SUPPORT.md) for where to file bugs and feature requests. For
security issues, follow [SECURITY.md](SECURITY.md) — do not open a public issue.

## License

By contributing, you agree that your contributions are licensed under the
project's [MIT License](LICENSE).
