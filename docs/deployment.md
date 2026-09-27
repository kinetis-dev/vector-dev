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
pnpm nuxt generate    # .output/public, for any web server
```

The API registers `CorsMiddleware` for the UI's origin; see
{doc}`authentication`.

## Hosted

The application serves the UI itself, behind its own session:

```console
vendor/bin/kinetis vector:install
```

publishes the bundle to `public/vector/ui` (`VECTOR_UI_DIR`). Vector
then answers `GET /vector/ui` and every deep link under it with the
bundle's page and one inline script carrying the configuration: the API
base, the login and logout URLs, the session's CSRF token, branding and
extension URLs. An anonymous visitor is redirected to `VECTOR_LOGIN_URL`;
until a bundle is published, the page answers `503`.

To host your own build of the UI, generate it with
`NUXT_APP_BASE_URL=/vector/ui/` and point `VECTOR_UI_DIR` at it.

## Content Security Policy

The hosted shell runs under a strict policy with no exceptions. Every
file is same-origin, every inline script carries the response's nonce,
Vuetify's style element carries it too, and the icon font is bundled.

Put `{nonce}` in the framework's `SECURITY_CSP` and the shell uses the
nonce of each request:

```text
SECURITY_CSP="default-src 'self'; script-src 'self' 'nonce-{nonce}'; style-src 'self' 'nonce-{nonce}'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'"
```

`VECTOR_SHELL_CSP` gives the shell a policy of its own instead, with the
same `{nonce}` placeholder. `VECTOR_SHELL_HEAD` appends a raw fragment
before `</head>` — the deployer's way, and the only way, to add script
to the page.

## Production

Build the registry into the release, as for any Kinetis application:

```console
composer install --no-dev --optimize-autoloader
php vendor/bin/kinetis build
```

In production the descriptor is not re-read from source on each boot;
rebuild after changing a resource. Run a queue worker for Vector's jobs
({doc}`jobs`).

## See also

- {doc}`authentication` — the two credential modes.
- {doc}`customizing-the-ui` — extensions and your own build.
