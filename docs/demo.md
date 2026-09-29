# The demo application

The Vector repository ships a complete Kinetis application administered
by Vector, the one both packages are developed and tested against. It
has two business domains on two databases, so every field type and
relation shape Vector administers is exercised:

- a shop on MySQL — customers, orders, products, categories and tags;
- a support desk on PostgreSQL — teams, agents, tickets, replies and
  labels.

It runs both admin modes, background jobs on a real queue, and a seeded
catalogue.

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
| `nginx`, `app` | `localhost:8080` | The API: nginx and PHP-FPM. `/openapi` in development. |
| `node` | `localhost:3000` | The standalone admin: `nuxt dev` for `demo/frontend`. |
| `worker` | — | `queue:work --queue=default,vector`: the application's jobs first, then Vector's. |
| `mysql` | `localhost:3306` | The shop, and the SQL queue table the test suite uses. `vector` / `vector`. |
| `postgres` | `localhost:5432` | `vector_support`, the support desk (connection `support`), and `vector_admin`, Vector's own tables (connection `admin`). `vector` / `vector`. |
| `redis` | `localhost:6379` | The job queue (connection `jobs`) and, in database 1, the cache holding sign-in throttling and refresh tokens. |

The repository is mounted at `/workspace`. `demo/backend/vendor/` and
the `node_modules/` directories are named volumes. Uploaded files live
on the private `storage` volume, shared by `app` and `worker` and never
served by nginx. Request bodies may be up to 12 MB (`MAX_BODY_SIZE` and
nginx's `client_max_body_size`).

A PHP change applies on the next request, and the admin UI hot-reloads.
PHP errors go to the log, not into responses:

```console
docker compose logs -f app
```

## The hosted admin

The hosted admin is the same UI served by the PHP application at
`/vector/ui`, behind the session sign-in at `/login`. A released
`kinetis/vector` ships its bundle. A checkout builds and publishes it
once:

```console
docker compose exec node pnpm generate:hosted
docker compose exec app vendor/bin/kinetis vector:install
docker compose restart app
```

Then open `http://localhost:8080/vector/ui`. A sign-in form left open
until its session expired comes back with a notice and the e-mail typed,
not an error, and signs in from there.

## Seed data

`demo:seed` fills the demo tables. `--fresh` empties them first,
restarting the shop's identifiers at 1 and clearing Vector's audit trail
and action log; `--scale=N` multiplies the row counts. Product images
are stored in Vector's storage as an upload would store them, so run the
seed as `www-data`, the user the application runs as.

| Account | Password | Signs in as |
|---|---|---|
| `admin@vector.local` | `password` | An admin. |
| `editor@vector.local` | `password` | A user without admin access: refused by the admin. |
| `e2e@vector.local` | `password` | The end-to-end suite's own admin. |

## Where to look

| To see | Look in `demo/backend/` |
|---|---|
| Resources, actions, widgets, dashboards and settings groups | `src/Vector/`; `ProductResource` declares soft deletes, summaries and five actions. |
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
