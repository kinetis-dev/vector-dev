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

publishes the bundle a released `kinetis/vector` package ships to
`public/vector/ui` (`VECTOR_UI_DIR`). A source install has no bundle;
the command says so and exits successfully, since the migrations and the
API need none. Vector
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

The rich-text editor works under it too: its style element carries the
nonce, and the admin shows images it fetches as `data:` URLs, which
`img-src 'self' data:` admits (a `blob:` URL would need `blob:` in the
policy).

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
rebuild after changing a resource. Build the compiled cache inside the
release or image itself: an existing valid `.kinetis-cache/compiled.php`
is used as it is, without comparing it to the source that produced it
(see [Caching](https://kinetis.dev/docs/caching.html)). Run a queue
worker for Vector's jobs ({doc}`jobs`).

## Performance

One recorded run of the demo's list benchmark, Vector's list
endpoints under FrankenPHP worker mode against nginx and PHP-FPM. It is
a measurement on one machine on one day, not a CI gate and not a
guarantee: the figures depend on the hardware, the data and the
configuration below.

**Run.** 2026-10-17, from `demo/` ({doc}`demo`):

```console
bin/benchmark --seed=20
docker compose exec --user www-data app vendor/bin/kinetis demo:seed --fresh   # the stock data set again
```

`--seed=20` first replaces the demo data with `demo:seed --fresh
--scale=20`: 3000 products, 8000 orders and 1600 customers on MySQL,
6000 tickets on PostgreSQL. The script prints the report below as
Markdown; run it alone, with nothing else using the stack.

**Machine.** Intel Core i9-9880H (8 cores, 16 threads), 16 GiB, macOS
26.7.1, Docker Engine 29.8.2 (Docker Desktop). The Docker VM has 16
CPUs and 9.7 GiB.

**Configuration.** PHP 8.4.26 in both images. OPcache is enabled under
both web SAPIs, as `bin/check-frankenphp` reports it for this
configuration ("enabled under both").

- FrankenPHP 1.13.1 in worker mode: 12 worker threads, with
  `DB_MAX_CONNECTIONS=4` (MySQL) and `DB_ADMIN_MAX_CONNECTIONS=2` and
  `DB_SUPPORT_MAX_CONNECTIONS=2` (PostgreSQL) per thread.
- PHP-FPM behind nginx: `pm.max_children = 5`, the image's default.
- Both run `APP_ENV=development`, the demo's setting.

**Load.** [oha](https://github.com/hatoo/oha) 1.16.0 in a container on
the Compose network, signed in as the admin with a bearer token. For
each endpoint and runtime:

- "One at a time" is the median of 20 sequential requests on one
  connection.
- "Under load" is 32 connections for 20 s, after a 5 s warm-up.

Statements per request are counted by the database servers over those
20 sequential requests: MySQL's `performance_schema` for the
application's account, and `pg_stat_statements` in `vector_admin` and
`vector_support`. No request failed.

| Endpoint | Runtime | One at a time: p50 ms | Under load: requests/s | p50 ms | p99 ms | MySQL statements | PostgreSQL statements |
|---|---|---:|---:|---:|---:|---:|---:|
| Products, default list | FrankenPHP worker | 8.4 | 752 | 35.6 | 115.3 | 4.0 | 0.0 |
| | nginx + PHP-FPM | 185.8 | 16 | 2046.7 | 3056.9 | 4.0 | 0.0 |
| Products, sorted by price then name | FrankenPHP worker | 7.4 | 734 | 36.4 | 123.2 | 4.0 | 0.0 |
| | nginx + PHP-FPM | 209.5 | 16 | 2010.4 | 3096.3 | 4.0 | 0.0 |
| Products, filtered (featured, stock ≤ 50) | FrankenPHP worker | 8.9 | 666 | 39.3 | 129.8 | 4.0 | 0.0 |
| | nginx + PHP-FPM | 193.9 | 17 | 2010.6 | 2930.8 | 4.0 | 0.0 |
| Products, searched ("wireless") | FrankenPHP worker | 9.6 | 642 | 48.5 | 90.7 | 4.0 | 0.0 |
| | nginx + PHP-FPM | 198.3 | 18 | 1894.8 | 2766.4 | 4.0 | 0.0 |
| Orders, sorted by customer (BelongsTo) | FrankenPHP worker | 5.3 | 509 | 57.0 | 165.0 | 4.0 | 0.0 |
| | nginx + PHP-FPM | 192.6 | 17 | 1926.5 | 3170.3 | 4.0 | 0.0 |
| Tickets on PostgreSQL (team, assignee) | FrankenPHP worker | 9.9 | 585 | 53.5 | 85.7 | 0.0 | 6.0 |
| | nginx + PHP-FPM | 218.8 | 15 | 2089.3 | 4205.4 | 0.0 | 6.0 |

**Reading.**

- Both runtimes send the same statements for each endpoint: 4 on MySQL
  for the shop's lists, 6 on PostgreSQL for the tickets. The difference
  is not in the queries.
- The difference is the boot. A FrankenPHP worker thread boots the
  application once and serves request after request, so a single
  request takes 5–10 ms. PHP-FPM boots it for every request, and under
  `APP_ENV=development` that boot re-reads the descriptor from source
  (see Production above). With the same statements on both, the boot
  is what its 185–219 ms adds; this run does not break that time down
  further.
- Under load, PHP-FPM is bounded by its five children: five requests
  at a time, each a boot of its own, came to 15–18 requests/s. The other
  27 connections queue, and that queue is its p50 of about 2 s. The
  worker served 509–752 requests/s with p99 at or under 165 ms.
- This compares the demo's development configuration on both runtimes.
  A production PHP-FPM, with the compiled cache built into the release
  and a pool sized for the machine, should boot faster and serve more;
  it was not measured here, so these figures say nothing about it.

**Background load.** The Docker VM also ran other projects' containers
during the run, idle or nearly so: 24 samples of `docker stats`, every
20 s, put their combined CPU at 1.8–18.3% of one core, under 2% of the
VM's 16 CPUs.

## See also

- {doc}`authentication` — the two credential modes.
- {doc}`customizing-the-ui` — extensions and your own build.
