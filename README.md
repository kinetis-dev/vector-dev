<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/vector</strong>
  <br>
  <strong>A schema-driven admin platform for Kinetis applications</strong>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications.

Declare resources over your `kinetis/orm` entities, and Vector serves an
admin API, a complete admin UI and MCP tools from the same definition,
enforcing your policies on every request.

This repository is the Vector monorepo: the two packages Vector is
delivered as, the demo application they are developed against, and the
user manual.

| Path | What it is |
|---|---|
| [`packages/vector`](packages/vector) | `kinetis/vector`, the PHP package: the compiled resource registry, the REST admin API, background jobs and MCP tools. |
| [`packages/vector-ui`](packages/vector-ui) | `@kinetis/vector-ui`, the admin UI as a Nuxt 4 layer on Vuetify 4. |
| [`demo/`](demo) | A Kinetis application administered by Vector — a shop on MySQL and a support desk on PostgreSQL — with the Docker stack that runs it and the end-to-end suite. |
| [`docs/`](docs) | The user manual. |

The architecture, the decisions behind it and the phase plan are in
`~/Documents/Kinetis/Vector/KINETIS_VECTOR_MASTER_PLAN_2026-09-24.md`.

## Try it

Docker is the only requirement. From `demo/`:

```sh
cp backend/.env.example backend/.env   # then set JWT_SECRET, as the file explains
docker compose up --build -d
docker compose exec app vendor/bin/kinetis migrate
docker compose exec --user www-data app vendor/bin/kinetis demo:seed
```

Open [http://localhost:3000](http://localhost:3000) and sign in as
`admin@vector.local` with the password `password`.

## Documentation

The user manual is in [`docs/`](docs). Start with
[Getting started](docs/getting-started.md). To work on Vector itself,
read [The demo application](docs/demo.md),
[Working on Vector](docs/contributing.md) and
[AI coding agents](docs/coding-agents.md).

Build the manual with Docker:

```sh
docker run --rm -v "$PWD/docs":/docs -w /docs python:3.12-slim \
    sh -c 'pip install -q -r requirements.txt && sphinx-build -W -b html . _build/html'
```

`-W` fails the build on any Sphinx warning. Open
`docs/_build/html/index.html`.

## License

MIT — see [LICENSE](LICENSE).
