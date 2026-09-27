# Authentication

Vector has no users, passwords or sessions of its own. Your application
authenticates; Vector asks whether the result may enter the admin.

## The `vector` middleware group

Every Vector route is in the `vector` middleware group. Your
authentication middleware joins it and registers a
`Kinetis\Http\CurrentUserInterface` on the request scope; Vector's own
`VectorAccessMiddleware`, the group's innermost member, then asks your
`VectorAccessInterface`:

| Request | Answer |
|---|---|
| No identity registered | `401` |
| Identity the access policy refuses | `403`, `This account has no access to the admin.` |
| Admitted | The route runs, under the resource policies |

```php
#[AsMiddlewareGroup('vector', priority: 50)]
#[AsMiddlewareGroup('vector-shell', priority: 50)] // hosted mode's shell page
#[AsMiddlewareGroup('mcp', priority: 50)]          // the MCP tools over HTTP
final readonly class AdminAuthMiddleware implements MiddlewareInterface { /* … */ }
```

Register nothing when no credential is present: the API then answers
`401`, and the hosted shell redirects to your login page.

## Standalone: bearer tokens

The standalone UI signs in with `POST {apiBase}/auth/login`, sending
`{"email", "password"}`, and expects:

```json
{ "accessToken": "…", "tokenType": "Bearer", "expiresIn": 3600,
  "user": { "id": 1, "name": "Vector Admin", "email": "admin@example.com" } }
```

It then sends `Authorization: Bearer <token>` on every request, keeps
the token for the browser tab only (never in `localStorage`), and signs
out when it expires or the API answers `401`. `kinetis/auth-jwt` issues
and verifies such tokens.

The UI lives on another origin, so register `CorsMiddleware` for it,
allowing the `Authorization`, `Content-Type`, `X-CSRF-Token` and
`If-Match` headers and exposing `Content-Disposition` and `ETag`.

## Hosted: the session cookie

The hosted UI rides your application's session. It sends the session
cookie with every request, and the `X-CSRF-Token` it was given on every
unsafe method; your middleware verifies it. An anonymous visitor to
`/vector/ui` is redirected to `VECTOR_LOGIN_URL`; **Sign out** posts to
`VECTOR_LOGOUT_URL` with the CSRF token. Vector adds no login page; keep
your own.

A user object may implement `VectorShellUserInterface` to give the shell
a display name.

## One API, both modes

One middleware can accept both — a bearer token when one is sent, the
session otherwise, requiring the CSRF token only for a session. The demo
application does exactly that.

## Background jobs

A queued job runs in a worker, with no request. Bind
`ActorResolverInterface` so Vector can rebuild the admin who started it
from their id:

```php
$app->bind(ActorResolverInterface::class, AdminResolver::class);
```

```php
final readonly class AdminResolver implements ActorResolverInterface
{
    public function resolve(string $actorId): ?CurrentUserInterface { /* load the user */ }
}
```

Without it, queued actions and imports are refused, naming what is
missing.

## See also

- {doc}`authorization` — what an admitted user may do.
- {doc}`deployment` — choosing a mode.
- {doc}`audit` — impersonation.
