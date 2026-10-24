# Deployment

The same admin UI reaches the API in one of two ways. Both are
first-class; pick per deployment.

| | Standalone | Hosted |
|---|---|---|
| Served by | Any static host, on its own origin | Your PHP application, at `/vector/ui` |
| Built with | `nuxt generate` | `vector:install-ui` installs the bundle `@kinetis/vector-ui` ships |
| Signs in with | A bearer token from `/auth/login` | Your application's session and login page |
| Writes carry | `Authorization: Bearer …` | The session cookie and `X-CSRF-Token` |
| Needs | CORS for the UI's origin | A login page, `VECTOR_LOGIN_URL` |

## Standalone

A Nuxt application extends `@kinetis/vector-ui`, points
`runtimeConfig.public.vector.apiBase` (or `NUXT_PUBLIC_VECTOR_API_BASE`)
at the API, and is generated as a static site:

```console
pnpm nuxt dev         # development
pnpm nuxt generate    # .output/public, for any web server
```

The API registers `CorsMiddleware` for the UI's origin; see
{doc}`authentication`.

## Hosted

The application serves the UI itself, behind its own session. The
bundle ships inside the `@kinetis/vector-ui` npm package, and
`kinetis/vector` pins the release it expects in `resources/ui.json`.
Install it into the served directory, `VECTOR_UI_DIR` or
`public/vector/ui`:

```console
vendor/bin/kinetis vector:install       # Vector's migrations
vendor/bin/kinetis migrate
vendor/bin/kinetis vector:install-ui    # the pinned @kinetis/vector-ui release
```

then restart the workers: a worker reads `index.html` once, when it
boots. Neither command needs Node.

### Upgrading

```console
composer update kinetis/vector
vendor/bin/kinetis vector:install
vendor/bin/kinetis migrate
vendor/bin/kinetis vector:install-ui
```

and restart the workers. `vector:install` names a served UI that is
missing, has no `vector-bundle.json`, or is not the pinned release.
`vendor/bin/kinetis vector:install-ui --check` verifies the served
directory against its manifest and the pin without the network: exit 0
in sync, 1 otherwise. Run it in CI or a deploy's health step.

### What `vector:install-ui` checks

| Option | Installs |
|---|---|
| none | The pinned release, from the registry |
| `--version=X.Y.Z` | Another published release |
| `--from=<file.tgz> [--integrity=sha512-…]` | A packed tarball, checked against the integrity when given |
| `--from=<dir>` | A built `dist/static` directory |
| `--force` | The pinned release again, though it is installed and verifies |
| `--check [--version=X.Y.Z]` | Nothing: verifies what is installed, offline |

Exit codes are 0 (done, or in sync), 1 (refused or failed) and 2 (a
usage error). When the pinned release is installed and verifies, the
command says so and fetches nothing.

From the registry the command reads the package's metadata and its
tarball over HTTPS only, redirects included, and refuses a release the
registry gives no sha512 `dist.integrity` for. The tarball is checked
against that integrity before anything is extracted, and extraction
takes regular files and directories under `package/dist/static/` only,
within fixed size and entry limits.

The bundle carries a manifest, `vector-bundle.json`: the package and
version that built it, the source commit, the descriptor version it
speaks and the SHA-256 of every file. The bundle is staged in a private
temporary directory and checked against it first; it is refused, with
exit 1 and nothing written to the served directory, when:

- the manifest is missing or malformed, or lists no `index.html`;
- it was built for another package or descriptor version;
- it is not the expected release (a development build, `dev-<short
  commit>`, is accepted only when `kinetis/vector` is itself a
  development install);
- a listed file is missing or changed, or a file is not listed.

Then every file is renamed into place, `index.html` and
`vector-bundle.json` last, and the files only the previous manifest
listed are removed. Nothing is written through a directory that is a
link or leads outside the served directory.

### Private registries and air-gapped installs

`VECTOR_UI_REGISTRY` names another npm registry (`https://` only;
`https://registry.npmjs.org` by default), and
`VECTOR_UI_REGISTRY_TOKEN` a bearer token for it. The token is sent only
to the registry's own host, never to a tarball served from another, and
never printed. `.npmrc` is not read.

Without network access, fetch the tarball and its integrity where there
is (`npm pack @kinetis/vector-ui@<version>` and `npm view
@kinetis/vector-ui@<version> dist.integrity`), copy both across, and
install from the file:

```console
vendor/bin/kinetis vector:install-ui --from=kinetis-vector-ui-0.11.0.tgz --integrity=sha512-…
```

The bundle must still be the pinned release (or `--version`'s).

Vector's `ShellController` then answers `GET /vector/ui` and every deep
link under it with the bundle's `index.html` plus one inline script,
`window.__VECTOR__`, carrying the configuration: the API base, the base
path, cookie mode, the login and logout URLs, the session's CSRF token,
the response's script nonce, branding and extension URLs. An anonymous
visitor is redirected to `VECTOR_LOGIN_URL`; until a bundle is
installed, the page answers `503`.

To host your own build of the UI ({doc}`customizing-the-ui`), generate
it with `NUXT_APP_BASE_URL=/vector/ui/` and point `VECTOR_UI_DIR` at the
generated directory.

## Content Security Policy

The hosted shell runs under a strict policy with no exceptions. Every
bundle file is same-origin, every inline script tag carries the
response's nonce (the bundle's own, the config script, the deployer's
`VECTOR_SHELL_HEAD`), Vuetify's runtime style element carries it too,
and the icon font is bundled.

The nonce is the application's. Put `{nonce}` in the framework's
`SECURITY_CSP` and its security headers put a fresh nonce on every
request, the shell writes that nonce on its tags, and the application's
one policy covers the shell like any other page. The demo's
`.env.example` ships:

```text
SECURITY_CSP="default-src 'self'; script-src 'self' 'nonce-{nonce}'; style-src 'self' 'nonce-{nonce}'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'"
```

The rich-text editor works under it too: its style element carries the
nonce, and the admin shows images it fetches as `data:` URLs, which
`img-src 'self' data:` admits (a `blob:` URL would need `blob:` in the
policy).

`VECTOR_SHELL_CSP` gives the shell a policy of its own instead, sent in
place of the application's, with `{nonce}` replaced by the same nonce;
set it only when the shell needs a different policy. With neither set,
the page draws a nonce of its own and carries no policy.
`VECTOR_SHELL_HEAD` appends a raw fragment before `</head>` — deployment
configuration, and the only way to add script to the page.

## Production

Set `APP_ENV=production`. In any other environment an unhandled error
answers `500` with the exception class, its message and the file and
line it came from.

Build the registry into the release, as for any Kinetis application:

```console
composer install --no-dev --optimize-autoloader
php vendor/bin/kinetis build
```

In production the descriptor is not re-read from source on each boot;
rebuild after changing a resource. Build the compiled cache inside the
release or image itself: an existing valid `.kinetis-cache/compiled.php`
is used as it is, without comparing it to the source that produced it
(see [Caching](inv:kinetis:std:doc#caching)). Run a queue
worker for Vector's jobs ({doc}`jobs`), and schedule
`kinetis vector:jobs:reap` and `kinetis vector:files:prune`
({doc}`files`).

## Security checklist

- **`APP_ENV=production`**, as above.
- **Cookie mode:** `SESSION_SECURE=true` (the default; `false` is for
  plain-HTTP development). A `SESSION_COOKIE` name starting with
  `__Host-` makes the browser enforce it: the cookie must be Secure,
  `Path=/` and carry no Domain. `kinetis/session` writes every cookie
  with `Path=/` and no Domain, and refuses to start with a `__Host-` or
  `__Secure-` name while `SESSION_SECURE` is off.
- **`JWT_SECRET`** (the demo's, for bearer tokens) must be at least
  32 bytes for HS256; a shorter key stops the boot. Changing it
  invalidates every access and impersonation token already issued.
  Refresh tokens are random values kept in the cache, not signed with
  it: they survive a rotation and renew into tokens signed with the new
  key. To sign everyone out, clear the cache's refresh tokens as well.
- **Bearer mode:** `VECTOR_UI_ORIGIN` must be the UI's exact origin —
  scheme, host and port, no path. It is the only origin CORS allows.
- **CSP:** `SECURITY_CSP` must contain `{nonce}` (or set
  `VECTOR_SHELL_CSP`); a policy without it blocks the shell's inline
  scripts. `VECTOR_SHELL_HEAD` and `VECTOR_UI_EXTENSIONS` put script on
  the admin page: set them in deployment configuration only, never from
  anything a user can edit.
- **Uploads:** keep the storage root private, outside the web root
  (`public/`). Files are meant to be served only through
  `GET /vector/r/{resource}/{id}/files/{field}`, which checks access.
  Schedule `kinetis vector:files:prune` to delete uploads no record
  uses. {doc}`files` covers both, and who may upload.

### Known limits

- Replaying a redeemed refresh token revokes its whole family, with no
  grace period ({doc}`authentication`). Revocation lives in the
  application cache: if the cache loses those records, a later replay is
  refused but revokes nothing.
- An impersonation token is never renewed. In bearer mode it expires no
  later than the token that started it; in cookie mode it lasts as long
  as the session.
- The standalone UI keeps its tokens in `sessionStorage`, which any
  script running on the admin's origin can read. A strict CSP is what
  keeps other script off that origin.
- An unused upload stays in storage until `vector:files:prune` runs and
  its grace period (24 hours by default) has passed; one an audit entry
  holds stays as long as that entry.
- A download serves any stored key outside `vector/` — keys your
  application writes. A key under `vector/` is served only from that
  field's own `vector/{resource}/{field}/`.

## See also

- {doc}`authentication` — the two credential modes.
- {doc}`customizing-the-ui` — extensions and your own build.
