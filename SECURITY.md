# Security Policy

## Reporting a Vulnerability

Please report security vulnerabilities privately — **not** as a public
GitHub issue.

Go to the [Security tab](https://github.com/kinetis-dev/vector-dev/security/advisories/new)
and click "Report a vulnerability." This opens a private advisory
thread visible only to you and the maintainers, with:

- A description of the vulnerability and its impact
- Steps to reproduce, or a proof-of-concept
- The affected package(s) and version(s) — see "Scope" below
- Whether you're aware of it being exploited in the wild

You'll get an acknowledgment within **3 business days**. We'll keep you
updated as we investigate and fix the issue, and credit you in the
advisory and release notes unless you'd prefer to stay anonymous.

## Scope

Vector is delivered as two packages released together under one
version: `kinetis/vector` on Packagist (the PHP package: the resource
registry, the REST admin API, background jobs and MCP tools) and
`@kinetis/vector-ui` on npm (the admin UI). A report against either is
in scope, and so is the hosted admin bundle `kinetis/vector` ships. If
you're not sure which side a bug lives in, report it anyway — we'll
route it internally.

The demo application in `demo/` exists to develop and test the
packages; it is not deployed software. A finding there is in scope when
it shows a flaw in the packages themselves.

A vulnerability in Kinetis itself (`kinetis/framework` and the other
`kinetis/*` packages Vector depends on) belongs to
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis/security/advisories/new).

## Supported Versions

Only the **latest released minor** receives security fixes; expect the
fix as a new patch release on that line, not backported indefinitely.
Both packages share the version, so a fix ships in both. No version
number is written here on purpose — it would be wrong after the next
release;
[packagist.org/packages/kinetis/vector](https://packagist.org/packages/kinetis/vector)
always shows what is current.

## Disclosure

We follow coordinated disclosure: once a fix is released, we publish a
GitHub Security Advisory describing the issue, affected versions, and
the fixed version. We ask reporters not to disclose publicly until a
fix has shipped, and we aim to ship within **90 days** of a confirmed
report (usually much sooner for anything with a working exploit).

## What's Out of Scope

- Vulnerabilities in third-party dependencies — report those upstream;
  we'll track and update once a fix is available.
- Findings that require an already-compromised environment, or that
  only let an admin do what the application's own policies already
  allow them. Vector is an admin platform: an admin getting past a
  resource policy, row scope or field visibility *is* in scope.
