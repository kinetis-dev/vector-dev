# Databases

Vector reads and writes your entities where the ORM maps them, and keeps
its own tables where you tell it. MySQL and PostgreSQL are supported
everywhere.

## Entities on several connections

An entity may live on a named connection:

```php
#[Entity(table: 'tickets', connection: 'support')]
```

with its `DB_SUPPORT_*` keys and its migrations in
`migrations/support/`. Its resource needs nothing extra. The compiled
descriptor records each resource's connection. Every read — lists,
filters, search, summaries, relation labels and counts, options,
related lists, export, global search — is built on that connection
through the request's `EntityManagerRegistry`, and every write —
create, update, delete, restore, batch, actions — flushes through that
connection's manager. A related record is loaded through the manager of
the connection it lives on. A relation never spans two connections (the
ORM refuses such a mapping at build time), so every relation Vector
follows stays on one database. A value pointing into another database,
such as a shop customer's id on a support ticket, is an ordinary field.

Each connection commits on its own: an action or batch over one
resource is one flush, and Vector's own rows (action log, audit, jobs)
commit on Vector's connection after it.

## Vector's own tables

By default Vector's tables — settings, preferences, views, the action
log, audit, jobs and notifications — share the application's default
connection. To keep them apart:

```console
vendor/bin/kinetis vector:install --connection=admin
vendor/bin/kinetis migrate            # or: migrate --connection=admin
```

```text
DB_ADMIN_CONNECTION=pgsql
DB_ADMIN_HOST=db.internal
DB_ADMIN_NAME=vector_admin
DB_ADMIN_USER=vector
DB_ADMIN_PASSWORD=secret
VECTOR_DB_CONNECTION=admin
```

The migrations go to `migrations/admin/`, the partition
`kinetis/migrations` runs on that connection with a ledger of its own.
`VECTOR_DB_CONNECTION` tells Vector where its tables are at runtime; the
installer prints it when it is missing. Vector uses the application's
`db.admin` binding when `bootstrap.php` makes one, and otherwise builds
a link from the `DB_ADMIN_*` keys and closes it with the worker.

Only Vector's tables move: your entities stay on the connection their
`#[Entity]` names, and Vector's jobs go where
[Where the jobs go](jobs.md#where-the-jobs-go) describes. When the
connections differ, Vector's own rows commit after, and separately
from, the entity changes they describe.

A connection name is lowercase letters and digits, starting with a
letter. `default` names the default connection, and `app` is reserved.

## Dialect notes

Both dialects are supported on every connection — Vector's own, the
default, and every named one an entity uses.

- Search, the *contains* filter and relation option search ignore case
  on both (`ILIKE` on PostgreSQL).
- Full-text search reads whole words on both. MySQL's index leaves out
  words shorter than `innodb_ft_min_token_size` (3) and its stopwords;
  PostgreSQL's follows the configuration `fullText()` names.
- A unique-index violation is reported on the field it concerns, on
  both: from PostgreSQL's `Key (column)=` detail, or MySQL's index name.
- A calendar day is a `Kinetis\Orm\Date` property on a `DATE` column:
  its `Date` field reads and writes `YYYY-MM-DD` with no time or zone. A
  `DateTime` field on one is refused at build time; a `Date` field on a
  timestamp property shows the instant's date part.
- On PostgreSQL, a saved view's flags (`shared`, `is_default`,
  `resource_default`) are `SMALLINT` 0/1, as they are 0/1 in MySQL's
  `TINYINT(1)`, and a job's file
  is stored as base64 text, at most 12 MiB of file (MySQL's
  `MEDIUMBLOB`).

## Full-text indexes

A resource's `fullText()` searches through an index the application
creates in its own migration, on each engine the entity may live on.

On MySQL, a `FULLTEXT` index over exactly the columns `fullText()`
names, in its order. `MATCH` refuses a column list no index covers, so
the search fails without it:

```sql
CREATE FULLTEXT INDEX products_name_fulltext ON products (name);
CREATE FULLTEXT INDEX articles_fulltext ON articles (title, body);
```

On PostgreSQL, a GIN index over the expression the search builds, with
the same configuration, columns and order. Without it the search
answers by scanning the table:

```sql
CREATE INDEX tickets_subject_fulltext ON tickets USING GIN (to_tsvector('simple', coalesce(subject, '')));
CREATE INDEX articles_fulltext ON articles USING GIN (to_tsvector('english', coalesce(title, '') || ' ' || coalesce(body, '')));
```

## See also

- {doc}`configuration` — every key.
- {doc}`limits` — sizes and bounds.
