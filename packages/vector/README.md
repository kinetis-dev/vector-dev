<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/vector</strong>
  <br>
  <strong>A schema-driven admin platform for Kinetis applications</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/vector"><img src="https://img.shields.io/packagist/v/kinetis/vector?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/vector"><img src="https://img.shields.io/packagist/dt/kinetis/vector" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/vector"><img src="https://img.shields.io/packagist/php-v/kinetis/vector" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/vector"><img src="https://img.shields.io/packagist/l/kinetis/vector" alt="License"></a>
  <a href="https://github.com/kinetis-dev/vector-dev/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/vector-dev/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications. Vector is developed in the
[kinetis-dev/vector-dev](https://github.com/kinetis-dev/vector-dev)
monorepo, beside its admin UI and a demo application.

Declare *resources* over your `kinetis/orm` entities. Vector compiles
them into one immutable descriptor at `kinetis build`, serves a REST
admin API from it, runs actions and background jobs, and exposes the
same services as MCP tools — under your application's authentication
and per-resource policies. The admin UI is a separate package,
[`@kinetis/vector-ui`](https://www.npmjs.com/package/@kinetis/vector-ui),
which renders the descriptor and contains no application knowledge.

```php
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\{BelongsTo, Enum, Id, Money, Text};
use Kinetis\Vector\VectorResource;

#[Resource(entity: Product::class, group: 'Catalog', icon: 'mdi-package-variant', title: 'name')]
final class ProductResource extends VectorResource
{
    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('name')->required()->max(200)->sortable()->searchable()->filterable(),
            Enum::make('status')->of(ProductStatus::class)->filterable(),
            BelongsTo::to('category', CategoryResource::class)->sortable()->filterable(),
            Money::make('priceCents', 'Price')->currencyField('currency')->summarize('avg'),
        ];
    }
}
```

Everything a request may name — a field, a column, a sort, a filter, an
action, a widget — is checked against the compiled descriptor, so no
request text ever reaches SQL as an identifier. Everything that runs per
request is built by the request container; nothing Vector holds on the
application scope carries request state.

## Provides

Installing this package auto-registers, via `extra.kinetis`:

- **Routes**: the REST admin API under `/vector`, in the `vector`
  middleware group, and the hosted admin shell at `GET /vector/ui`, in
  the `vector-shell` group.
- **The innermost member of each group**: `VectorAccessMiddleware`,
  which asks the application's `VectorAccessInterface`, and
  `VectorShellAccessMiddleware`, which redirects a request without a
  user to `VECTOR_LOGIN_URL`.
- **Commands** on `vendor/bin/kinetis`: `vector:install`, `vector:list`
  and `vector:jobs:reap`.
- **MCP tools** `vector_resources`, `vector_describe`, `vector_query`,
  `vector_get`, `vector_create`, `vector_update`, `vector_delete`,
  `vector_run_action` and `vector_widget`, and the resource
  `vector://schema`.
- **The registry**: every `#[Resource]`, `#[Settings]` and `#[Dashboard]`
  class, compiled into the descriptor, with the built-in **Branding**,
  **Languages** and **Background jobs** settings groups.
- **Bindings**, once per worker, from `PackageBootstrap`: the hosted UI
  document, Vector's database, queue and storage connections, its widget
  cache and, unless the application binds its own, its label translator.

Nothing else. Authentication stays the application's: its middleware in
the `vector` group (and `mcp`, for the tools over HTTP) registers
`CurrentUserInterface`, and it binds `VectorAccessInterface` and, for
background jobs, `ActorResolverInterface`.

## Configuration

Read from the environment (or `.env`) via `Kinetis\Config`. The
essential keys:

| Key | Default | Purpose |
|---|---|---|
| `VECTOR_DB_CONNECTION` | `default` | The connection Vector's own tables live on. |
| `VECTOR_QUEUE` | `default` | The queue name Vector's jobs are pushed to. |
| `VECTOR_QUEUE_CONNECTION` | *(unset)* | A named queue connection for Vector's jobs; unset, the application's own queue. |
| `VECTOR_STORAGE_CONNECTION` | *(unset)* | A named storage connection for uploads; unset, the application's `FilesystemOperator`. |
| `VECTOR_UI_DIR` | `public/vector/ui` | Where the hosted UI bundle lives. |
| `VECTOR_LOGIN_URL`, `VECTOR_LOGOUT_URL` | `/login`, `/logout` | The application's pages the hosted shell redirects to and posts to. |
| `VECTOR_EXPORT_FORMATS` | `csv,xlsx` | The export formats offered; XLSX also needs `openspout/openspout`, `ext-zip` and a queue. |

Every key, and the UI's own settings:
[kinetis.dev/vector/docs/configuration.html](https://kinetis.dev/vector/docs/configuration.html).

## Installation

```sh
composer require kinetis/vector
vendor/bin/kinetis vector:install    # Vector's migrations, and the hosted UI a released package ships
vendor/bin/kinetis migrate
```

Requires PHP 8.4+ and [`kinetis/framework`](https://github.com/kinetis-dev/framework).
Run `vector:install` again after every upgrade, then `migrate`. Full
documentation:
[kinetis.dev/vector/docs](https://kinetis.dev/vector/docs/), starting with
[Getting started](https://kinetis.dev/vector/docs/getting-started.html).

## License

MIT — see [LICENSE](LICENSE).
