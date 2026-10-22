# The demo application

The Vector repository ships a complete Kinetis application administered
by Vector, the one both packages are developed and tested against. It
has two business domains on two databases, so every field type and
relation shape Vector administers is exercised:

- a shop on MySQL — customers, orders, products, categories and tags;
- a support desk on PostgreSQL — teams, agents, tickets, replies and
  labels.

It runs both admin modes, background jobs on a real queue, and a seeded
catalog.

## Run it

Docker is the only requirement. From `demo/`:

```console
cp backend/.env.example backend/.env    # then set JWT_SECRET, as the file explains
docker compose up --build -d
docker compose exec app vendor/bin/kinetis migrate
docker compose exec --user www-data app vendor/bin/kinetis demo:seed
```

Open `http://localhost:3000` and sign in as `admin@vector.local` with the
password `password`. Every `docker compose` command on this page runs
from `demo/`.

## What runs

| Service | Address | What it is |
|---|---|---|
| `nginx`, `app` | `localhost:8080` | The API: nginx and PHP-FPM. `/openapi` in development. Set `NGINX_HOST_PORT` to publish it on another host port when 8080 is taken. |
| `frankenphp` | `localhost:8081` | The same application under FrankenPHP worker mode, Kinetis's primary runtime: 12 worker threads on the native database drivers. |
| `node` | `localhost:3000` | The standalone admin: `nuxt dev` for `demo/frontend`. |
| `worker` | — | `queue:work --queue=default,vector`: the application's jobs first, then Vector's. |
| `mysql` | `localhost:3306` | The shop, and the SQL queue table the test suite uses. `vector` / `vector`. Set `MYSQL_HOST_PORT` to publish it on another host port when 3306 is taken. |
| `postgres` | `localhost:5432` | `vector_support`, the support desk (connection `support`), and `vector_admin`, Vector's own tables (connection `admin`). `vector` / `vector`. |
| `redis` | `localhost:6379` | The job queue (connection `jobs`) and, in database 1, the cache holding sign-in throttling and refresh tokens. |

The repository is mounted at `/workspace`. `demo/backend/vendor/` and
the `node_modules/` directories are named volumes. Uploaded files live
on the private `storage` volume, shared by `app`, `worker` and
`frankenphp` and never served by nginx or Caddy. The file sessions are a
volume too, so one browser session holds on both ports. Request bodies
may be up to 12 MB (`MAX_BODY_SIZE` and nginx's `client_max_body_size`).

A PHP change applies on the next request on `:8080`, and the admin UI
hot-reloads. `:8081` keeps the code each worker thread booted with, so
restart it after editing PHP, and after `vector:install`:

```console
docker compose restart frankenphp
```

PHP errors go to the log, not into responses:

```console
docker compose logs -f app          # PHP-FPM
docker compose logs -f frankenphp   # FrankenPHP
```

## FrankenPHP worker mode

`frankenphp` runs the same `public/index.php` as PHP-FPM, from the same
mounts and `backend/.env`, but each of its worker threads boots the
application once and then serves request after request. `DB_DRIVER=auto`
selects the native non-blocking MySQL and PostgreSQL clients there, and
PDO under PHP-FPM, the queue worker and the CLI. Its Caddyfile is
`docker/Caddyfile`.

`app`, `worker` and `frankenphp` are built from one `docker/Dockerfile`.
PHP-FPM's PHP and FrankenPHP's are separate builds (one thread-safe, one
not), so a compiled extension cannot move between them. Both targets
run the same recipe instead, `docker/php-extensions.sh`. It builds
pcov, uv and event with [PIE](https://github.com/php/pie), pinned to
exact versions, from a binary mounted only while the recipe runs. The
PHP minor version, the FrankenPHP release and the PIE release are set
once, at the top of the Dockerfile, and one `docker compose build`
rebuilds every PHP image.

Two scripts check and measure it:

```console
bin/check-frankenphp   # the worker, the extensions, worker mode, the drivers and request isolation
bin/benchmark          # Vector's list endpoints on both runtimes
```

`bin/benchmark` prints its report as Markdown: latency, throughput and
the statements each request sends, per endpoint and runtime. Run it
alone, with nothing else using the stack.

`bin/check-frankenphp` exits nonzero when any check fails:

- **The worker script.** It must be `public/index.php`, the script
  `php_server` routes every request to. Any other worker script fails
  loudly, not by falling back to classic mode. A script that never calls
  `frankenphp_handle_request()` stops FrankenPHP from starting at all.
  One that does call it leaves `public/index.php` on FrankenPHP's
  regular threads, where Kinetis still picks its FrankenPHP adapter and
  every request is a `500` (`frankenphp_handle_request() called while
  not in worker mode`). The check reads the worker file from Caddy's own
  configuration and names that cause.
- **The extensions.** `app` and `frankenphp` load the same extensions,
  Zend extensions such as OPcache included, at the same versions, on the
  same PHP version.
- **OPcache.** PHP-FPM behind nginx and the FrankenPHP worker run with
  the same OPcache setting, as each one reports it through
  `GET /_demo/runtime` (the CLI has a setting of its own).
- **(a) Worker mode.** It sends many requests to `GET /_demo/runtime`.
  That endpoint reports the boot that answered and how many requests
  that boot has served. Fewer boots than requests, with climbing counts,
  is a persistent worker. Classic mode boots once per request.
- **(b) The native drivers.** The same document names the database
  clients `DB_DRIVER=auto` built and the event-loop driver.
- **(c) Request isolation.** Four principals (two admins, the editor
  and an anonymous caller) are interleaved at random, many requests in
  flight at once, for 25 rounds on the same threads. Every response must
  be its own principal's: the identity, the admin access, and the
  per-record `delete` permission, which differs between the two admins
  because nobody may delete their own account. `--wrong-expectation`
  inverts one expected value, to show that this check can fail.

`GET /_demo/runtime` lives in the demo application
(`src/Http/Diagnostics/`) and answers only under `APP_ENV=development`.
Under any other value, a request to that path with any HTTP method gets
the Kernel's own `404` for an unmatched path: the same status, headers
and body. `RuntimeGate` answers it before routing runs, so no method
gets the `405` that would confirm the route exists. The OpenAPI document
never lists it.

### Threads and database connections

Each worker thread runs `bootstrap.php` and builds its own pool for each
connection it uses. The `frankenphp` service sizes them in
`docker-compose.yml`. Each named connection has its own key and no
fallback to the default's; the default width is 8.

| Connection | Database | Per thread | 12 threads |
|---|---|---:|---:|
| `default` | MySQL | `DB_MAX_CONNECTIONS=4` | 48 |
| `admin` (Vector's tables) | PostgreSQL | `DB_ADMIN_MAX_CONNECTIONS=2` | 24 |
| `support` (the support desk) | PostgreSQL | `DB_SUPPORT_MAX_CONNECTIONS=2` | 24 |

PDO holds one connection per connection used, per process: up to 5
PHP-FPM children (`pm.max_children`, the image's default) and the queue
worker.

- **MySQL**, `max_connections` = 151: 12 × 4 = 48 for FrankenPHP,
  + 5 × 1 for PHP-FPM, + 1 for the queue worker = 54. That leaves 97 for
  migrations, `demo:seed` and the test suite.
- **PostgreSQL**, `max_connections` = 100: 12 × (2 + 2) = 48 for
  FrankenPHP, + 5 × 2 for PHP-FPM, + 2 for the queue worker = 60. That
  leaves 40 for the CLI and the tests.

mysqli waits through `select()`, which cannot see a descriptor numbered
above 1024. `DB_WARM_CONNECTIONS=4` opens each thread's whole MySQL pool
at boot, before any client socket exists, so its descriptors stay low.
The 48 mysqli connections in the one FrankenPHP process sit far below
that ceiling. Raise the threads (`FRANKENPHP_WORKERS`) or a pool width,
and redo both sums.

### The event loop: ext-uv and ext-event

Without a loop extension, Revolt uses `select()` too. Under FrankenPHP
the Go server's client sockets share the process's descriptor table with
the PostgreSQL and Redis clients' watchers, and a loaded worker passes
descriptor 1024. Kinetis's runtime guide therefore requires a loop
extension in a worker image. Both images carry two:

- **ext-event 3.1.6**, the stable driver the guide requires. It is the
  one Revolt uses if uv is ever dropped.
- **ext-uv 0.3.1**, which Revolt prefers when both are loaded. It has
  only 0.x releases, so it is pinned to an exact version. The guide
  names PECL's `uv-0.3.0`; the images install through PIE, and
  `amphp/uv`, uv's PIE package, publishes 0.3.1 and nothing earlier.
  It is kept because it is also kinetis/storage's native file driver:
  amphp/file does filesystem I/O through uv only when the loop runs on
  uv, and otherwise sends every call to a pool of helper processes.

`bin/check-frankenphp` reports the driver in use, which is
`Revolt\EventLoop\Driver\UvDriver`, and fails on the `select()` driver.

## The hosted admin

The hosted admin is the same UI served by the PHP application at
`/vector/ui`, behind the session sign-in at `/login`. A released
`kinetis/vector` ships its bundle. A checkout builds and publishes it
once:

```console
docker compose exec node pnpm generate:hosted
docker compose exec app vendor/bin/kinetis vector:install
docker compose restart app frankenphp
```

Then open `http://localhost:8080/vector/ui`, or
`http://localhost:8081/vector/ui` for the same admin under FrankenPHP.
There the restart matters: a worker that booted before the bundle was
published keeps answering a signed-in visitor `503` until it restarts.
An anonymous visitor is redirected to the sign-in page either way. A
sign-in form left open until its session expired comes back with a notice and the e-mail typed, not an
error, and signs in from there.

The hosted admin is same-origin on either port. It signs in with the
session cookie, so it needs no CORS. `VECTOR_UI_ORIGIN` names the
standalone admin's origin (`localhost:3000`), which calls the API on
`:8080` with a bearer token.

## Seed data

`demo:seed` fills the demo tables. `--fresh` empties them first,
restarting the shop's identifiers at 1 and clearing Vector's audit trail
and action log; `--scale=N` multiplies the row counts. Product images
are stored in Vector's storage as an upload would store them, so run the
seed as `www-data`, the user the application runs as. At large scales
the seed needs more than the CLI's 128 MiB:

```console
docker compose exec --user www-data app php -d memory_limit=1G vendor/bin/kinetis demo:seed --fresh --scale=20
```

The test suite expects the stock data set. Put it back with
`demo:seed --fresh` (scale 1) after a benchmark or a scaled seed.

| Account | Password | Signs in as |
|---|---|---|
| `admin@vector.local` | `password` | An admin. |
| `editor@vector.local` | `password` | A user without admin access: refused by the admin. |
| `e2e@vector.local` | `password` | The end-to-end suite's own admin. |

## Where to look

| To see | Look in `demo/backend/` |
|---|---|
| Resources, actions, widgets, dashboards and settings groups | `src/Vector/`; `ProductResource` declares soft deletes, summaries and five actions; `OrderResource` lists without a count (`withoutCount()`), `OrderLineResource` pages by cursor (`cursorPaging()`) ({doc}`lists`). |
| Both sign-ins, their throttling and impersonation | `src/Auth/`: the JWT API under `/auth/*` and the session form at `/login` ({doc}`authentication`). |
| Rich text rendered outside the admin | `GET /shop/products/{id}` (`src/Http/ProductPageController.php`) renders a product description with `Document::toHtml()`. |
| Runtime UI extensions | `public/vector-extensions/`: a stock-level cell, and a "Stock overview" page reading `GET /vector-demo/stock-overview` ({doc}`customizing-the-ui`). |
| The queue wiring | `.env.example`: the named connection `jobs` on Redis, with Vector's jobs on the queue `vector` ({doc}`jobs`). |
| One Content Security Policy for the whole application | `SECURITY_CSP` in `.env.example` ({doc}`deployment`). |
| Translated labels | `translations/` (`VECTOR_TRANSLATIONS_DIR`, {doc}`languages`). |

## See also

- {doc}`getting-started` — the same steps in your own application.
- {doc}`contributing` — the tests, the repository layout and building
  the manual.
- {doc}`coding-agents` — working on the demo with an AI coding agent.
