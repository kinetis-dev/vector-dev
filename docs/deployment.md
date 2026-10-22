# Deployment

The same admin UI reaches the API in one of two ways. Both are
first-class; pick per deployment.

| | Standalone | Hosted |
|---|---|---|
| Served by | Any static host, on its own origin | Your PHP application, at `/vector/ui` |
| Built with | `nuxt generate` | `vector:install` publishes the bundle |
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

The application serves the UI itself, behind its own session:

```console
vendor/bin/kinetis vector:install
```

publishes the bundle a released `kinetis/vector` package ships to
`public/vector/ui`. Run it again after every upgrade. A worker reads
`index.html` once, when it boots, so restart the workers after
publishing. An install from source (a path
repository or a branch) has no bundle; the command says so and exits
successfully, since the migrations and the API need none, and the hosted
admin is then served only when `VECTOR_UI_DIR` points at a separately
built bundle.

The bundle carries a manifest, `vector-bundle.json`: the package and
version that built it, the source commit, the descriptor version it
speaks and the SHA-256 of every file. `vector:install` checks the bundle
against it before writing anything, and refuses it with exit 1, one line
naming the cause and the remedy, and no migration or file written, when:

- the manifest is missing or malformed, or lists no `index.html`;
- it was built for another package or descriptor version;
- on a released package, it was built for another version;
- a listed file is missing or changed, or a file is not listed;
- `resources/dist` holds a manifest but no `index.html`: a damaged
  bundle, refused rather than treated as absent.

On a released package, reinstall `kinetis/vector` to restore the bundle
it shipped. In a checkout of the Vector repository, `pnpm
generate:hosted` builds the bundle into `packages/vector/resources/dist`
with a development version, `dev-<short commit>`, which `vector:install`
publishes and names; delete the directory to install without one.

Vector's `ShellController` then answers `GET /vector/ui` and every deep
link under it with the bundle's `index.html` plus one inline script,
`window.__VECTOR__`, carrying the configuration: the API base, the base
path, cookie mode, the login and logout URLs, the session's CSRF token,
the response's script nonce, branding and extension URLs. An anonymous
visitor is redirected to `VECTOR_LOGIN_URL`; until a bundle is
published, the page answers `503`.

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
(see [Caching](https://kinetis.dev/docs/caching.html)). Run a queue
worker for Vector's jobs ({doc}`jobs`).

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
  `GET /vector/r/{resource}/{id}/files/{field}`, which checks access
  ({doc}`files`).

### Known limits

- Replaying a redeemed refresh token revokes its whole family, with no
  grace period: a duplicated browser tab that renews with the same token
  signs both tabs out. Revocation lives in the application cache: if
  the cache loses those records, a later replay is refused but revokes
  nothing. Access tokens already issued run until they expire.
- An impersonation token is never renewed. In bearer mode it expires no
  later than the token that started it; in cookie mode it lasts as long
  as the session.
- The standalone UI keeps its tokens in `sessionStorage`, which any
  script running on the admin's origin can read. A strict CSP is what
  keeps other script off that origin.
- Uploading to a field needs only list (`viewAny`) permission on the
  resource. A file that no record ends up using is not deleted.
- A download serves any stored key outside `vector/` — keys your
  application writes. A key under `vector/` is served only from that
  field's own `vector/{resource}/{field}/`.

## See also

- {doc}`authentication` — the two credential modes.
- {doc}`customizing-the-ui` — extensions and your own build.
