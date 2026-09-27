# Databases

Vector reads and writes your entities where the ORM maps them, and keeps
its own tables where you tell it. MySQL and PostgreSQL are supported
everywhere.

## Entities on several connections

An entity may live on a named connection:

```php
#[Entity(table: 'tickets', connection: 'support')]
```

Its resource needs nothing extra. Every read and write for it — lists,
filters, search, summaries, relation labels, exports, actions — runs on
that connection. A relation never spans two connections (the ORM refuses
it at build time), so every relation Vector follows stays on one
database. A value pointing into another database, such as a customer id
on a support ticket, is an ordinary field.

Each connection commits on its own.

## Vector's own tables

By default Vector's tables — settings, preferences, views, the action
log, audit, jobs and notifications — share the application's default
connection. To keep them apart:

```console
vendor/bin/kinetis vector:install --connection=admin
vendor/bin/kinetis migrate
```

```text
DB_ADMIN_CONNECTION=pgsql
DB_ADMIN_HOST=db.internal
DB_ADMIN_NAME=vector_admin
DB_ADMIN_USER=vector
DB_ADMIN_PASSWORD=secret
VECTOR_DB_CONNECTION=admin
```

The migrations go to `migrations/admin/`. `VECTOR_DB_CONNECTION` tells
Vector where its tables are at runtime. Only Vector's tables move; your
entities stay where their `#[Entity]` puts them. When the connections
differ, Vector's own rows commit after, and separately from, the entity
changes they describe.

## Dialect notes

- Search, the *contains* filter and relation search ignore case on both
  (`ILIKE` on PostgreSQL).
- A unique-index violation is reported on the field it concerns, on both.
- A calendar day is a `Kinetis\Orm\Date` property on a `DATE` column,
  read and written as `YYYY-MM-DD` with no time zone.

## See also

- {doc}`configuration` — every key.
- {doc}`limits` — sizes and bounds.
