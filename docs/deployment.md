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
`public/vector/ui`. Run it again after every upgrade, and restart the
workers so they read the new page. An install from source (a path
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
- a listed file is missing or changed, or a file is not listed.

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

## See also

- {doc}`authentication` — the two credential modes.
- {doc}`customizing-the-ui` — extensions and your own build.
