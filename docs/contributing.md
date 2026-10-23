# Working on Vector

Vector is developed in one repository: the two packages, and the demo
application ({doc}`demo`) that consumes them the way an adopter would.
Every command on this page runs from `demo/`, with the stack up and
seeded.

## Layout

```text
packages/vector/            kinetis/vector: registry, REST API, jobs, MCP tools, migration stubs
packages/vector-ui/         @kinetis/vector-ui, the Nuxt layer; its Vitest suite in test/nuxt/
  hosted/                   the hosted bundle's build entry: the layer alone, built into dist/static
demo/docker-compose.yml     the stack: app, worker, nginx, frankenphp, mysql, postgres, redis, node; e2e and oha on demand
demo/docker/                the PHP images (PHP-FPM and FrankenPHP) and their shared extension recipe, nginx, the Caddyfile, php.ini
demo/bin/                   check-frankenphp and benchmark, for the FrankenPHP worker service
demo/backend/               the demo application, kinetis/vector installed from ../../packages/vector
  src/Auth/                 both sign-ins, their throttling, the vector and mcp group middleware, impersonation
  src/Console/              demo:seed
  src/Http/Diagnostics/     GET /_demo/runtime, the development-only runtime report bin/check-frankenphp reads
  src/Domain/               the shop's entities, on MySQL; Support/ the support desk's, on PostgreSQL
  src/Vector/               the demo's resources, actions, widgets, dashboards and settings groups
  migrations/               the shop, the queue table and Vector's tables for MySQL
  migrations/admin/         Vector's tables for PostgreSQL, on the connection admin
  migrations/support/       the support desk, on the connection support
  bootstrap.php             JWT keys, CORS for the standalone UI, the Vector bindings
  public/vector-extensions/ the demo's runtime UI extensions
  translations/             the demo's labels
  tests/                    PHPUnit (App\Tests\)
demo/frontend/              the demo Nuxt app: extends @kinetis/vector-ui
demo/e2e/                   the end-to-end suite: Playwright, both admin modes
docs/                       this manual (Sphinx, MyST Markdown)
bin/orbitron-mcp            launches the Orbitron MCP server for demo/backend
```

Controllers, commands, entities and resources anywhere under `App\` or
`Kinetis\Vector\` are discovered; there is no registry to edit. Nothing
under `packages/` may reference `App\`.

The demo installs `kinetis/vector` as a Composer `path` repository, so
`vendor/composer/installed.json` keeps `packages/vector/composer.json` as
it was at the last install. After changing that file, its
`extra.kinetis` keys especially, reinstall the package so discovery sees
the change:

```console
docker compose exec app composer update kinetis/vector
```

## Tests

```console
docker compose exec app vendor/bin/phpunit                                        # the API and the PHP package
docker compose exec -e VECTOR_TEST_DB_CONNECTION=default app vendor/bin/phpunit   # the same, Vector's tables on MySQL
docker compose exec node pnpm test                                                # the UI layer: Vitest, no server
docker compose exec node pnpm typecheck                                           # vue-tsc over the layer and its tests
docker compose run --rm e2e                                                       # the admin in a browser, both modes
bin/check-frankenphp                                                              # the application under FrankenPHP worker mode
```

PHPUnit runs with Vector's tables on PostgreSQL, where `backend/.env`
puts them, and again on MySQL. It handles its requests in-process and
goes through neither web server. `bin/check-frankenphp` checks the
running worker on `:8081` ({doc}`demo`). `frankenphp` keeps the code it
booted with, so run `docker compose restart frankenphp` after a PHP
change, before checking it.

The UI layer's Vitest suite is in `packages/vector-ui/test/nuxt/`. Each
file runs inside a Nuxt app built from the layer (`@nuxt/test-utils`,
happy-dom), so auto-imports, app config, the i18n plugin, Vuetify and
Pinia behave as they do in the admin. It covers the formatters and
client rules, the API client in both authentication modes, the auth
store, the schema and resource clients, and the generated form
(`VxResourceForm`) with its 422 mapping. API calls go to endpoints the
test registers (`test/nuxt/endpoints.ts`); nothing needs a server.
`pnpm typecheck` runs `vue-tsc` in strict mode over the layer, its tests
included.

`bin/benchmark` replaces the demo data when given `--seed=N` and needs
the stack to itself. Run it alone, then restore the stock data set
before PHPUnit:

```console
docker compose exec --user www-data app vendor/bin/kinetis demo:seed --fresh
```

The end-to-end suite needs the hosted bundle installed
({doc}`demo`) and writes to the demo databases, so run it on its own,
never beside PHPUnit. It signs in as its own admin, `e2e@vector.local`,
and clears that account's saved preferences before each test, so it and
a person using the demo do not disturb each other. See
`demo/e2e/README.md`.

## Static analysis and builds

```console
docker compose exec app vendor/bin/phpstan analyse
docker compose exec app vendor/bin/kinetis build
docker compose exec node pnpm lint
docker compose exec node pnpm --filter frontend generate
```

PHPStan runs at level 8 over the demo and `packages/vector/src`, with
two Kinetis rules. `NoStaticPropertiesRule` flags a `static` property,
which outlives every request a worker handles; `NoBlockingIoRule` flags
a call that blocks the event loop instead of yielding. They catch two
specific mistakes; they do not prove a change is correct on a persistent
worker.

```console
docker compose exec app composer psalm          # Psalm, plain
docker compose exec app composer psalm:taint    # Psalm, taint analysis; writes backend/psalm.sarif
```

Psalm covers the demo's `src/` and `migrations/` and
`packages/vector/src`, at its level 7 (Psalm's 1 is its strictest).
It runs twice on purpose: `--taint-analysis` reports only taint issues,
so it cannot replace the plain run. The taint run needs
`zend.assertions=-1`, which the script sets. Request input reaches this
code through PSR-7 and the framework's hydration, and SQL runs through
Kinetis's own links. Psalm knows neither as a taint source or sink, so a
clean taint run only covers flows from PHP's own sources to PHP's own
sinks. Overriding and implementing methods carry `#[\Override]`, and
the plain run enforces it.

## Mutation testing

```console
docker compose exec app composer infection:workers  # the workers' databases, first
docker compose exec app composer infection          # the demo's src/, about 70 minutes
docker compose exec app composer infection:vector   # Vector's Registry and Query
```

Infection runs the PHPUnit suite against each mutant and fails below the
`minMsi` and `minCoveredMsi` floors in `backend/infection.json5` and
`backend/infection.vector.json5`.

The mutated tests write to the databases, so each Infection worker runs
on stores of its own. Infection numbers its worker processes in
`TEST_TOKEN`, and when that is set `backend/tests/bootstrap.php` points
worker N at the MySQL database `vector_N`, the PostgreSQL databases
`vector_admin_N` and `vector_support_N`, Redis databases 2N (the cache)
and 2N+1 (the jobs queue) and a temporary directory of its own
(`backend/tests/worker-stores.php`). `composer infection:workers` drops,
recreates, migrates and seeds those databases for workers 1 to 4, as root
on MySQL; run it before each mutation-testing run. Without
`TEST_TOKEN` (PHPUnit on its own, and Infection's initial run of the
suite) the tests use the base databases.

`infection:vector` runs four workers.
`infection` runs one, on worker 1's stores: its whole suite includes
tests that share state outside them. Neither run touches the base
databases beyond what PHPUnit itself does, but both still need them
seeded, so run them one at a time, never beside PHPUnit or the
end-to-end suite. A killed mutant stops its test before the test puts its
rows back, so afterwards a worker's databases can hold missing order
lines, changed product prices and stock, a leftover `hosted-test` tag,
and, in Vector's own tables, a store `lowStockThreshold` of 0 and saved
product views; `composer infection:workers` starts them over.

The reports go to `backend/infection.log` and
`backend/infection.vector.log`, each with a `.summary.log` beside it.

`pnpm lint` runs ESLint over every TypeScript and Vue file in the
workspace: the UI layer and its hosted app, the demo frontend and the
end-to-end suite. One flat config at the repository root,
`eslint.config.mjs`, uses `@nuxt/eslint-config` on its own, so linting
needs no `nuxt prepare`. Each package also has its own `pnpm lint`
against that config. TypeScript is pinned to `~6.0.3` because
typescript-eslint supports TypeScript only below 6.1.

## Continuous integration

The workflows in `.github/workflows/` run on pushes to `main` and on
pull requests, each for the paths it covers:

- `ci.yml` — on PHP 8.4 and 8.5, in the demo's own PHP image with
  MySQL, PostgreSQL and Redis as services: `composer validate --strict`
  for both `composer.json` files, `composer audit`, the PHPUnit suite
  (both placements of Vector's tables, as above), PHPStan and both Psalm
  runs; `pnpm lint`, `pnpm typecheck`, `pnpm test`,
  `pnpm test:scripts`, and `pnpm generate:hosted` followed by
  `node scripts/check-vector-ui-pack.mjs --packed`, which fails unless
  npm would pack `vector-bundle.json` and every file it lists; and the
  strict build of this manual.
- `monorepo-validate.yml` — `node scripts/validate-monorepo.mjs`: no
  `App\` reference under `packages/`.
- `versions.yml` — `node scripts/validate-versions.mjs`: a pull request
  that changes files under `packages/vector` bumps `vector` in
  `packages.manifest.json` by one step, with `node scripts/bump.mjs`. One
  that changes files under `packages/vector-ui` bumps both `vector-ui`
  and `vector`, since `kinetis/vector` pins the UI release
  (`packages/vector/resources/ui.json`, which `bump.mjs vector-ui`
  moves); see {doc}`releasing`.
- `infection.yml` — `composer infection:workers`, then
  `composer infection:vector`, when Vector's Registry or Query or the
  mutation setup changes; the mutated files are split over three
  parallel jobs, each passing `--filter` and floors of its own.
- `sonarqube.yml` — the PHPUnit suite under pcov, its coverage of
  `packages/vector/src` reported to SonarQube Cloud with its quality
  gate.
- `semgrep.yml` — Semgrep SAST; `.semgrepignore` lists the reviewed
  false positives.

`deploy-docs.yml` publishes this manual to `kinetis.dev/vector/docs/`
when `docs/` changes on `main`.

## See also

- {doc}`demo` — the stack and its services.
- {doc}`coding-agents` — the same work with an AI coding agent.
