<p align="center">
  <img src="https://kinetis.dev/vector/docs/_static/logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>@kinetis/vector-ui</strong>
  <br>
  <strong>The Kinetis Vector admin UI, as a Nuxt layer on Vuetify</strong>
</p>

<p align="center">
  <a href="https://www.npmjs.com/package/@kinetis/vector-ui"><img src="https://img.shields.io/npm/v/@kinetis/vector-ui?label=version" alt="npm Version"></a>
  <a href="https://www.npmjs.com/package/@kinetis/vector-ui"><img src="https://img.shields.io/npm/dm/@kinetis/vector-ui" alt="npm Downloads"></a>
  <a href="https://www.npmjs.com/package/@kinetis/vector-ui"><img src="https://img.shields.io/npm/l/@kinetis/vector-ui" alt="License"></a>
  <a href="https://github.com/kinetis-dev/vector-dev/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/vector-dev/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications. Vector is developed in the
[kinetis-dev/vector-dev](https://github.com/kinetis-dev/vector-dev)
monorepo, beside its PHP package and a demo application.

The admin UI for [`kinetis/vector`](https://packagist.org/packages/kinetis/vector):
a Nuxt 4 layer on Vuetify 4 that renders the descriptor the API
publishes — navigation, lists, forms, relation panels, actions,
dashboards, jobs, settings — and contains no application knowledge. An
application is a Nuxt app that extends the layer, served as a static
site on its own origin with a bearer token (standalone), or published
into the PHP application and served at `/vector/ui` behind its session
(hosted).

## Installation

```sh
pnpm add @kinetis/vector-ui nuxt vuetify vuetify-nuxt-module
```

```ts
// nuxt.config.ts
export default defineNuxtConfig({
    extends: ['@kinetis/vector-ui'],
    runtimeConfig: { public: { vector: { apiBase: 'https://api.example.com' } } },
})
```

`pnpm nuxt dev` for development, `pnpm nuxt generate` for a static site
any web server hosts. The API allows the UI's origin through
`CorsMiddleware` and offers the `POST /auth/login` endpoint the sign-in
page calls; see
[Deployment](https://kinetis.dev/vector/docs/deployment.html).

## Configuration

| Where | Key | Default | Purpose |
|---|---|---|---|
| `runtimeConfig.public.vector` | `apiBase` | `http://localhost:8080` | The API origin in standalone mode (`NUXT_PUBLIC_VECTOR_API_BASE`). |
| | `extensions` | `[]` | ES-module URLs to import after boot, in standalone mode. |
| `app.config.vector` | `brand.title` | `Vector` | The title before the Branding settings load. |
| | `usersResource` | `null` | The resource whose records are users, for **Impersonate**. |
| | `idleMinutes` | `30` | Minutes without use after which the admin signs the user out; `0` never. |
| | `locale` | `en` | The language before the descriptor names the user's. |

Every setting, including component overrides, custom pages and
messages:
[kinetis.dev/vector/docs/configuration.html](https://kinetis.dev/vector/docs/configuration.html).
Cells, inputs, widgets and runtime extensions:
[Customizing the UI](https://kinetis.dev/vector/docs/customizing-the-ui.html).

## License

MIT — see [LICENSE](LICENSE).
