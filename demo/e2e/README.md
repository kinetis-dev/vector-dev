# End-to-end tests

Playwright, driving the demo admin in a real browser against the running
stack, in both of its deployment modes:

| Project | What it drives | How it signs in |
|---|---|---|
| `standalone` | the Nuxt dev server, `http://localhost:3000` | a bearer token from `POST /auth/login` |
| `hosted` | the bundle the PHP application serves, `http://localhost:8080/vector/ui` | the application's own `/login` form (session cookie) |

A spec named `*.standalone.spec.ts` or `*.hosted.spec.ts` runs in that
project only; every other spec runs in both.

## Running it

From `demo/`, with the stack up, migrated and seeded (see the root
README), and the hosted bundle published once:

```sh
docker compose exec node pnpm --filter frontend generate:hosted
docker compose exec app vendor/bin/kinetis vector:install
docker compose restart app
```

then:

```sh
docker compose run --rm e2e                                # everything, both projects
docker compose run --rm e2e test --project hosted          # one project
docker compose run --rm e2e test tests/tags.spec.ts        # one file
docker compose run --rm e2e test -g "searches by SKU"      # by title
```

The `e2e` service is the Playwright image, with Chromium, and is not
started by `docker compose up`. Everything after the service name goes
to `playwright`. Its packages come from the workspace, installed by
the `node` service, so `node` must have finished its `pnpm install`.

The browser runs inside that container, while the admin calls the API
at `localhost:8080`. `E2E_FORWARD` has the global setup forward the
container's own `localhost:3000` and `localhost:8080` to `node` and
`nginx`, so origins, CORS and cookies are exactly what a browser on the
host sees. The global setup also waits for the stack and loads the
standalone admin once, so the dev server's first compile is not charged
to the first test.

A failure keeps its trace and screenshot in `test-results/`. The HTML
report is in `playwright-report/`, served with:

```sh
docker compose run --rm -p 9323:9323 e2e show-report --host 0.0.0.0
```

The hosted project tests the bundle last published, not the layer's
current source: publish it again after changing the UI.

## What it assumes

- The seed: `e2e@vector.local`, the suite's own admin, and
  `editor@vector.local` (no admin access), both with the password
  `password`, and 150 products, `SKU-00001` to `SKU-00150`. No test
  creates or deletes products. A database seeded before the suite had
  its own account gets it from `demo:seed` without `--fresh`, which adds
  just that account and changes nothing else.
- One run at a time, one test at a time (`workers: 1`). The suite
  writes to the demo databases: what a test creates it names uniquely
  (`unique()`) and removes afterwards, even when it fails (`cleanup`).
- The suite's admin's saved UI preferences (list columns, language and
  the like) are cleared before each test, so every list starts from its
  defaults, and some tests change them. That is why the suite has an
  account of its own: a person using the demo as `admin@vector.local` at
  the same time neither loses their preferences nor changes the suite's
  (a language picked in the browser used to reach the tests' pages).

## Known bugs

A test that pins a known bug is marked `test.fail`, with a comment above
it saying what is wrong. It describes what the admin should do. It
passes while the bug is there, and fails once the bug is fixed, as the
reminder to turn it back into a plain `test`. The layer's Vitest suite
uses `it.fails` the same way.

## Layout

```text
playwright.config.ts    the two projects, timeouts, reporters
support/global-setup.ts forwards the ports, waits for the stack, warms up the dev server
support/fixtures.ts     accounts, signIn(), the admin/api/cleanup fixtures, unique()
tests/                  the specs
```
