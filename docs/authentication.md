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

## The `mcp` middleware group

The MCP tools over HTTP, `POST /mcp`, are served by `kinetis/mcp` in its
own `mcp` middleware group, which the `vector` group's members do not
join on their own. Its last member, `McpIdentityGuardMiddleware` at
priority 0, answers `401` unless a middleware earlier in the group
registered a `CurrentUserInterface`. With your authentication middleware
in `vector` only, every caller of `POST /mcp` — admins included — gets
`401`. Add it to the `mcp` group as well, as in the example above and
the demo's `VectorAuthMiddleware`.

Its priority in `mcp` must be above the guard's 0, so it runs before
the guard; at 0 or below it is not ordered before the guard, and
requests still get `401`. 50 sits between the guard and `kinetis/mcp`'s `McpOriginMiddleware`, at 100,
which checks the `Origin` header first. Do not set `MCP_HTTP_PUBLIC` as
a workaround: that opens the endpoint to callers without an identity.
See {doc}`mcp`.

## The `vector-shell` middleware group

Hosted mode's shell page, `GET /vector/ui` and every deep link under it,
is in the `vector-shell` group. Your session and authentication
middleware join it as they join `vector`, as above. Vector's own
`VectorShellAccessMiddleware` is the group's innermost member: a request
that reaches it with no identity registered is redirected to
`VECTOR_LOGIN_URL` (`302`, `Cache-Control: no-store`); any other reaches
the page. The group always exists, so the route table is valid before
your middleware joins it.

## Standalone: bearer tokens

The standalone UI signs in with `POST {apiBase}/auth/login`, sending
`{"email", "password"}`, and expects:

```json
{ "accessToken": "…", "tokenType": "Bearer", "expiresIn": 900,
  "refreshToken": "…", "refreshExpiresIn": 43200,
  "user": { "id": 1, "name": "Vector Admin", "email": "admin@example.com" } }
```

It then sends `Authorization: Bearer <token>` on every request and keeps
the tokens for the browser tab only: in memory, mirrored to
`sessionStorage` for reloads, never in `localStorage`.

`refreshToken` is optional. When your login answers one, the UI renews
the session itself, one renewal at a time: with under two minutes left
on the access token, and once when the API answers `401` (the
request is then repeated), it calls `POST {apiBase}/auth/refresh` with
`{"refreshToken"}` and expects the same answer as the login; **Sign
out** calls `POST {apiBase}/auth/logout` with `{"refreshToken"}` to revoke
it. When the renewal is refused, or there is no refresh token, the UI
signs out when the access token expires, and a `401` goes to `/login`.
An impersonation token is never renewed. `kinetis/auth-jwt` issues and
verifies access tokens, and its `RefreshTokenStore` keeps single-use
refresh tokens in a cache.

## Protecting the sign-in

Throttling failed sign-ins is your application's job — Vector never sees
a password. The demo shows the shape with the framework's
`AttemptThrottle` on a Redis cache: five failures in fifteen minutes lock
one e-mail from one client address, twenty lock the address, a success
clears both, and a locked attempt answers `429` with `Retry-After` (the
HTML form shows a message).

```{warning}
Never lock an account by its e-mail alone: anyone who knows the address
could then keep its owner out by typing wrong passwords. Count failures
per e-mail *and* client address, so an attacker locks only themselves,
plus per address, so one address cannot work through many accounts.
```

The demo's access tokens last fifteen minutes and its refresh tokens
twelve hours, reloading the user at each renewal so a changed role
applies. Without a cache it refuses to sign anyone in (`503`) rather than
run unthrottled.

The UI lives on another origin, so register `CorsMiddleware` for it,
allowing the `Authorization`, `Content-Type`, `X-CSRF-Token` and
`If-Match` headers and exposing `Content-Disposition` and `ETag`.

## Hosted: the session cookie

The hosted UI rides your application's session. The layer knows it is
hosted from the `window.__VECTOR__` configuration the shell page
injects, and is then in *cookie mode*: a same-origin API with
`credentials: same-origin`, the session cookie on every request, and the
`X-CSRF-Token` it was given on every unsafe method; your middleware
verifies it. A `401` sends the browser to `VECTOR_LOGIN_URL`, as an
anonymous visitor to `/vector/ui` is redirected there; **Sign out**
posts to `VECTOR_LOGOUT_URL` with the CSRF token. Impersonation reloads
the page in this mode.

Your session middleware (a thin subclass of `SessionMiddleware`) and an
authentication middleware that reads the session, verifies the CSRF
token on unsafe methods and registers the user join both the `vector`
and `vector-shell` groups. A monolith on a view adapter keeps its own
login page; Vector adds none.

A user object may implement `VectorShellUserInterface` to give the shell
a display name, and `ImpersonatedUserInterface` to say who is
impersonating it, which Vector writes to every log and audit row
({doc}`audit`).

## How long a session lasts

A session does not end on its own: the UI renews the token
before it runs out and polls badges and the inbox, so an open tab would
stay signed in for as long as it is open. The layer's `app.config`
`vector.idleMinutes` (30 by default; 0 turns it off) ends it, in both
modes, when nobody has used the admin — no pointer, key, touch or
scroll — for that long. Bearer mode revokes the refresh token and opens
the login page with a notice and the way back to the page that was
open; cookie mode posts the application's logout form. A tab that comes
back into view after sleeping is checked at that moment too.

How long a session may last in all is your application's rule. The
demo's refresh endpoint keeps the end the sign-in set, twelve hours
later, however often the tab renewed, so a tab still signs in again by
then.

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

Without it, Vector queues nothing: queued actions and imports answer
`403` naming what is missing, and exports stay inline and CSV-only
({doc}`jobs`).

## See also

- {doc}`authorization` — what an admitted user may do.
- {doc}`deployment` — choosing a mode.
- {doc}`audit` — impersonation.
