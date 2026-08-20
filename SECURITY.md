# Security Policy

## Supported versions

TNCMS Core is currently in **beta** (`1.0.0-beta.x`). Security fixes target the
most recent released version. Older beta builds are not separately maintained.

## What counts as a security report

Report privately (do **not** open a public issue) anything that could compromise
a TNCMS site or its users, for example:

- authentication or authorization bypass;
- remote code execution or arbitrary file read/write;
- SQL injection, stored/reflected XSS, or CSRF on state-changing endpoints;
- insecure file upload / path traversal;
- exposure of secrets, credentials, or other users' data.

Vulnerabilities in third-party dependencies should generally be reported to the
upstream project; if a TNCMS default configuration makes a dependency issue
exploitable, please tell us as well.

## How to report

Please report vulnerabilities **privately**. Do not disclose exploitable
details in public issues, pull requests, or discussions until a fix is
available.

> Publication-time configuration: private vulnerability reporting for this
> repository will be handled through GitHub's **Security → Report a
> vulnerability** (Private Vulnerability Reporting) once the repository is
> published and that feature is enabled. Until then, use the project channels
> listed at <https://tncms.org>.

When reporting, please include:

- affected version(s) and environment;
- a clear description and, where possible, a minimal reproduction;
- the impact you observed.

## Disclosure

We aim to acknowledge valid reports and work on a fix before public disclosure.
Because this is a volunteer-driven beta project, we do not offer a guaranteed
response time or a paid bug-bounty program.
