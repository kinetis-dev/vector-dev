# Working on Vector

Vector is developed in one repository: the two packages, and the demo
application ({doc}`demo`) that consumes them the way an adopter would.
Every command on this page runs from `demo/`, with the stack up and
seeded.

## Layout

```text
packages/vector/            kinetis/vector: registry, REST API, jobs, MCP tools, migration stubs
packages/vector-ui/         @kinetis/vector-ui, the Nuxt layer; its Vitest suite in test/nuxt/
  hosted/                   the hosted bundle's build entry: the layer alone
demo/docker-compose.yml     the stack: app, worker, nginx, mysql, postgres, redis, node, and e2e on demand
demo/docker/                the PHP image, nginx and php.ini
demo/backend/               the demo application, kinetis/vector installed from ../../packages/vector
  src/Auth/                 both sign-ins, their throttling, the vector and mcp group middleware, impersonation
  src/Console/              demo:seed
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
```

PHPUnit runs with Vector's tables on PostgreSQL, where `backend/.env`
puts them, and again on MySQL.

The end-to-end suite needs the hosted bundle published
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

`pnpm lint` runs ESLint over every TypeScript and Vue file in the
workspace: the UI layer and its hosted app, the demo frontend and the
end-to-end suite. One flat config at the repository root,
`eslint.config.mjs`, uses `@nuxt/eslint-config` on its own, so linting
needs no `nuxt prepare`. Each package also has its own `pnpm lint`
against that config. TypeScript is pinned to `~6.0.3` because
typescript-eslint supports TypeScript only below 6.1.

## See also

- {doc}`demo` — the stack and its services.
- {doc}`coding-agents` — the same work with an AI coding agent.
