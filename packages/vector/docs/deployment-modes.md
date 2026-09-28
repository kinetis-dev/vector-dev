# Deployment modes

Vector's UI reaches the API in one of two ways. Both are first-class:
the reference application runs both, and the test suite exercises both.

## Standalone

The UI is a static site on its own origin (`nuxt generate`), the API is
the Kinetis application. Authentication is a bearer token the
application issues (`kinetis/auth-jwt` in the reference app); the
application registers `CorsMiddleware` for the UI origin, allowing
`Authorization`, `Content-Type`, `X-CSRF-Token` and `If-Match`, and
exposing `Content-Disposition` and `ETag`. The application's
authentication middleware joins the `vector` group (and `mcp`, for the
tools) and registers `CurrentUserInterface`.

## Hosted

The PHP application serves the same UI itself from `public/vector/ui`,
with its own session. `kinetis vector:install` publishes the bundle a
released package ships (built with `NUXT_APP_BASE_URL=/vector/ui/`); a
source install has none, and serves one only when `VECTOR_UI_DIR`
points at a separately built bundle.
`ShellController` answers `GET /vector/ui` and every deep link under
it with the bundle's `index.html` plus one inline script:
`window.__VECTOR__` — API base, base path, cookie mode, login and
logout URLs, the session's CSRF token, the response's script nonce,
branding and extension URLs. An anonymous visitor is redirected to
`VECTOR_LOGIN_URL`; without a published bundle the shell answers 503.

The application also decides how long a session may last in all. The
reference app's refresh endpoint keeps the end the sign-in set, twelve
hours later, so a tab that renews itself still signs in again by then;
the layer's own idle timeout (`vector.idleMinutes`, 30 by default) ends
a session nobody is using before that, in both modes.

The application's session middleware (a thin subclass of
`SessionMiddleware`) and an authentication middleware that reads the
session, verifies the CSRF token on unsafe methods, and registers the
user join both the `vector` and `vector-shell` groups. Vector owns the
innermost member of each: `VectorAccessMiddleware` in `vector`,
`VectorShellAccessMiddleware` in `vector-shell`, which redirects a
request that arrives without a user to `VECTOR_LOGIN_URL`. A monolith on a view
adapter keeps its own login page; Vector adds none.

### Content Security Policy

The shell is CSP-clean: every bundle file is same-origin, every inline
script tag carries the response's nonce (the bundle's own, the config
script, the deployer's `VECTOR_SHELL_HEAD`), Vuetify's runtime style
element carries the same nonce, and the icon font is bundled.

The nonce is the application's. With `{nonce}` in the framework's
`SECURITY_CSP`, its security headers put a fresh nonce on every request,
the shell writes that nonce on its tags, and the application's one
policy covers the shell like any other page. The reference
`.env.example` ships:

```
SECURITY_CSP="default-src 'self'; script-src 'self' 'nonce-{nonce}'; style-src 'self' 'nonce-{nonce}'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'"
```

`VECTOR_SHELL_CSP` is a policy for the shell page alone, sent instead of
the application's, with `{nonce}` replaced by the same nonce. Set it
only when the shell needs a different policy. With neither set, the
page draws a nonce of its own and carries no policy.

## Customisation tiers

1. **Descriptor and settings — no JavaScript.** Fields, filters,
   actions, widgets, navigation, the Vuetify theme in the layer, and the
   `Branding` settings an admin edits at runtime (values, rendered
   escaped).
2. **Runtime UI extensions — JavaScript, no rebuild.** ES modules
   under `public/` listed in `VECTOR_UI_EXTENSIONS` (hosted) or
   `runtimeConfig.public.vector.extensions` (standalone), same-origin or
   CORS-enabled, calling `window.Vector.defineCell/defineInput/defineWidget/definePage`.
   The reference app's `public/vector-extensions/stock-level.js` is one.
3. **Bring your own build.** A Nuxt app extending `@kinetis/vector-ui`,
   overriding layer files, registering global components named in
   `app.config`, and generating its own bundle; in hosted mode point
   `VECTOR_UI_DIR` at it.

Script authority is the deployer's: `VECTOR_SHELL_HEAD` and the
extension list are configuration; no setting, field or descriptor value
is ever rendered as markup or executed.
