# Changelog

All notable changes to `kinetis/vector` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.19.0] - 2026-11-26

### Added

- `Field::placeholder()`: a hint inside the input's empty text box,
  published as the field's `placeholder` (absent when none is set),
  translated like its label and carried by `vector_describe`. A field
  whose input has no text box (`Boolean`, `BooleanGroup`, `Date`,
  `DateTime`, `File`, `Image`, `KeyValue`, `Repeater`, `Computed`,
  `Id`, `HasOne`, `HasMany`, `DateRange`) refuses it with a
  `LogicException` when it is defined.
- `minDate()` and `maxDate()` on `Date` and `DateTime`: the earliest and
  latest value, as a `Kinetis\Orm\Date` or `DateTimeInterface`, a
  string in the field's own format, or the relative `today` (a date)
  and `now` (a date-time). `Validation\DateBounds` checks them on every
  write path and refuses a value with `date_too_early` or
  `date_too_late`, naming the bound as declared. A relative bound is
  resolved at each check, never at compile time; `today` is the current
  day anywhere on Earth (UTC-12 for a minimum, UTC+14 for a maximum). A
  bound the field does not take, or a minimum after the maximum, is
  refused where it is declared.
- `Fields\Code`: source text in a string property, edited in a code
  editor. `language()` picks the highlighting from `Code::LANGUAGES`
  (`plain`, `json`, `html`, `css`, `javascript`, `typescript`, `sql`,
  `php`, `yaml`, `markdown`, `xml`) and refuses any other name;
  `lineNumbers()` and `rows()` (2 to 40) shape the editor. The
  descriptor carries them as the field's `code`; the value is the text
  as typed, and `vector_describe` names its language.
- `Fields\DateRange`: a period kept in two properties,
  `DateRange::between('promotion', 'promotionStartsOn',
  'promotionEndsOn')`, both `Kinetis\Orm\Date` or both
  `DateTimeImmutable` as the build reads them from the entity, nullable
  only when both are. Its value is `{from, to}` or null: one end alone
  is `range_incomplete` and an end before the start `range_reversed`,
  each at its end; `required()` asks for both, and `minDate()` and
  `maxDate()` bound both. A write fans out to both properties on
  create, update and bulk edit, a read assembles them, the audit trail
  records the range as one value, and a CSV export and import hold it
  as `from/to`. OpenAPI describes the object and `vector_describe` its
  format. The build refuses an end that is another field or that
  another range or flag group writes, a condition on a range, and a
  range in an action dialog or settings group; `sortable()`,
  `filterable()`, `summarize()` and `unique()` throw, the last because
  a range has no column of its own to match an import's rows by.

### Changed

- `min()` and `max()` on `Date` and `DateTime` throw a `LogicException`
  pointing to `minDate()` and `maxDate()`: they bound a text's length.

## [0.18.0] - 2026-11-25

### Added

- `Fields\BooleanGroup`: named on/off options as one value, an object
  of every option key to a bool (a key a write leaves out is false),
  shown as one cell and edited as one group of checkboxes. In columns
  mode (the default) each key is a non-null bool property with a column
  of its own, and each flag also filters (`filter[group.key]=true`) and
  sorts (`sort=group.key`). With `json()` the group maps one `array`
  property, a `JSON` or `jsonb` column holding the object, and is not
  sortable. Both filter with `any` and `all` of a list of keys being on,
  a bare value being `any`. The build refuses storage that does not
  match the mode. A key that is no option is `invalid_choice` under that
  key on write and, in JSON mode, throws `StoredValueException` on read:
  removing an option needs a data migration first.
- `Fields\Country`: a `Select` over the assigned ISO 3166-1 alpha-2
  codes of `Support\Countries`, searchable by default, one code in a
  string property or several with `multiple()` in an `array` property,
  with the select's filters and strictness. `only()` narrows the codes;
  the build refuses one that is not assigned. The server names codes in
  English; OpenAPI describes the enum of codes.
- `Database\JsonFilter::keysTrue()`: the SQL of a JSON object whose
  keys hold true, any or all of them, each key inside a bound
  `{"key": true}` object matched by containment (`JSON_CONTAINS` on
  MySQL and MariaDB, `@>` on PostgreSQL).
- `Fields\Select`: a choice among fixed options, from an array
  (`value => label`), a closure called once when the resource compiles,
  or a backed enum (`of()`, or `options(Status::class)`), with
  `labels()` and `colors()`. The descriptor type is `select`.
- `Select::multiple()`: several options in an `array` or `?array`
  property (a `JSON` column on MySQL and MariaDB, `jsonb` on
  PostgreSQL), stored deduplicated in the order the options are
  declared. The descriptor publishes `multiple`, OpenAPI an array of the
  option values with `uniqueItems`, and `vector_describe` `multiple`, a
  `format` and its filters.
- `Select::searchable()` gives the input a search box over the options
  (`autocomplete` in the descriptor); `Select::badge([value => colour])`
  shows the values as coloured chips in lists and on the record
  (`badge`).
- The `any` and `all` filter operators of a multiple select: holding at
  least one, or every one, of a comma-separated list of option values;
  a bare `filter[field]=value` is `any`. They work inside `or` and
  `and` groups, on MySQL, MariaDB and PostgreSQL.
- `Database\JsonFilter`, the SQL of a filter on a JSON column per
  dialect, every value bound: one `JSON_CONTAINS` per value (`any`) or
  of the whole list (`all`) on MySQL and MariaDB; `?|` over a text
  array (`??|` to the query builder) and `@>` on PostgreSQL. The dialect
  comes from `Database\Connections`, per connection.
- `Exception\StoredValueException`, thrown when a stored select value
  is not one of its options, or a JSON column holds no valid document.
- `Fields\Repeater` (descriptor type `repeater`): rows edited inside the
  record's form. In JSON mode, `fields([...])` declares a row's fields
  and the rows are a JSON list of objects in an `array` or `?array`
  property, each row converted and checked by its fields, a key no field
  declares refused, the list kept in the order it is sent. In
  relationship mode, `relationship('lines')` names an owned `#[HasMany]`
  and the rows are child records written with the parent in one
  transaction (diffed by `id`), `fields(['product', ...])` naming the
  child fields a row edits. `min()` and `max()` bound the rows;
  `orderBy('position')` names an int property of the child that takes
  each row's index on save and orders the rows on load. Errors name
  their row (`specs.2.label`, `lines.1.quantity`), a unique key a child
  row collides with included. The descriptor publishes `repeater`
  (`mode`, `fields`, `min`, `max`, `sortable`, `orderBy`, and a JSON
  repeater's `rowFields`), OpenAPI the rows' schema, and
  `vector_describe` a `format` and the row fields.
- `Fields\Tag` (descriptor type `tag`): free-text strings in a JSON list
  column, stored trimmed, without empty ones, each at most once ignoring
  case (the first spelling kept), in their order. `suggestions()` only
  proposes; `maxLength()` (50) and `maxTags()` (20) bound a tag and the
  list, a refused tag named by its index. It filters by `any` (a bare
  value) or `all` of a list of tags, on MySQL, MariaDB and PostgreSQL.
  The descriptor publishes `tags`, OpenAPI an array of strings, and
  `vector_describe` a `format` and its filters.
- `Json` and `KeyValue` fields take a `stdClass`, `?stdClass`,
  `array|stdClass` or `array|stdClass|null` property, which keeps every
  JSON object an object: `{}` and a nested `{}` are stored and answered
  as sent through a REST create, update or bulk edit, the MCP
  `vector_create` and `vector_update` tools, a CSV import and a revert
  from the audit trail, in a relationship repeater's row as on the
  record itself, and a list stays a list. The `create()` and
  `apply()` hooks receive a `stdClass` there.
- A resource with no fields of its own derives a `Json` field for a
  `stdClass` or `array|stdClass` property, and none for an `array` one.
- `Database\JsonDepth`: how deep a JSON value may nest, from what each
  database stores (100 levels on MySQL, 31 on MariaDB before 12.2, 2499
  on MariaDB 12.2 and later and on PostgreSQL), and
  `VectorDatabase::jsonDepth()` for Vector's own connection.

### Changed

- **Breaking:** an action's or a settings group's `Json` input is a
  `stdClass` tree (a list where JSON sent one) and a `KeyValue` input a
  `stdClass` of strings, instead of PHP arrays, so `{}` and a nested
  `{}` stay objects: in `ActionRequest::$fields`, a settings group's
  `validate()`, `SettingsStore::values()` and the settings API, from a
  REST run or save, the MCP `vector_run_action` tool and a queued run.
  Every other input is unchanged. An action or a settings consumer that
  reads such an input as an array reads it as an object, or converts it.
- **Breaking:** `RunActionJob::$fields` is the dialog's values as JSON
  text, since a job's arguments are arrays, which would hold `{}` as
  `[]`. A queued action job pushed before the upgrade fails to start;
  let the queue drain first.
- Requires `kinetis/orm` ^1.15.0 and `kinetis/query-builder` ^1.8.6.
- Requires `kinetis/framework` ^1.32.0 and `kinetis/mcp` ^1.10.0, whose
  `#[ObjectMap(keepObjects: true)]` lets the MCP write tools keep JSON
  objects.
- **Breaking:** `Json` and `KeyValue` map JSON columns: a `stdClass`,
  `?stdClass`, `array|stdClass` or `array|stdClass|null` property
  instead of JSON text in a `?string` one, and the build refuses any
  other property. `Json` reads as the stored document (an object or an
  array) instead of its text and writes a JSON object or array; a string
  is refused. `KeyValue` writes the object itself. To migrate, change
  the property to `array|stdClass|null` (`?stdClass` for a `KeyValue`;
  and any code that set it to JSON text to set the document), and the
  column to `JSON` on MySQL and MariaDB or `jsonb` on PostgreSQL
  (`ALTER TABLE t ALTER COLUMN c TYPE jsonb USING c::jsonb`). A client
  that sent JSON text to a `Json` field sends the document. An `array`
  or `?array` property is refused too, naming the resource, the property
  and the four accepted types: PHP holds `{}` and `[]` as one array, so
  an empty object, nested or not, would be stored as `[]`.
- **Breaking:** select values are strict. A body value outside the
  options is `invalid_choice` (on its index for a multiple select), and
  a JSON body must send an option's own type (`2`, not `"2"`, for an int
  option). A stored value outside the options throws
  `StoredValueException` on read: before removing an option, migrate the
  rows that still hold it.
- **Breaking:** `Computed::as('enum')` is `as('select')`.
- A form condition's `null` and `nnull` treat an empty list as empty.
- `GET /vector/r/{resource}/{id}/{field}/rows` serves a relationship
  repeater's rows, in the order its `orderBy()` property holds.
- The compiler refuses a relationship repeater whose child lives on
  another connection than its parent, since the save is one
  transaction.
- Requires `kinetis/orm` ^1.16.0, `kinetis/persistence` ^1.5.0 and
  `kinetis/database-bridge` ^1.4.7, whose links report the JSON depth
  their server stores.

### Removed

- **Breaking:** `Fields\Enum` and the `enum` descriptor type. Replace
  `Enum::make('status')->of(Status::class)` with
  `Select::make('status')->of(Status::class)`; `labels()` and `colors()`
  carry over, and a derived enum property becomes a `Select`. A client
  reading the descriptor matches `select` instead of `enum`. A select's
  `searchable()` no longer makes the list's search match its column.
- **Breaking:** `HasMany::repeater()`. A has-many edited inside the
  parent's form is a `Repeater` in relationship mode, whose descriptor
  type is `repeater` rather than `hasMany`. Replace
  `HasMany::to('lines', LineResource::class)->repeater(['product', 'quantity'], min: 1, max: 50)`
  with
  `Repeater::make('lines')->relationship('lines', LineResource::class)->fields(['product', 'quantity'])->min(1)->max(50)`,
  and add `->orderBy('position')` over an int child property to let the
  rows be dragged into order. The request body and the rows endpoint
  are unchanged. A `hasMany` field no longer publishes `repeater`.

### Fixed

- The audit trail read its recorded values with every JSON object a PHP
  array, so `GET /vector/r/{resource}/{id}/audit` answered an empty
  `KeyValue` map, or any recorded `{}`, as `[]`. It now answers a
  recorded object as an object.
- Saving a record through a relationship `Repeater` deleted the children
  the child resource's `scope()` hides from the user, after running the
  delete policy and hooks and auditing the delete, and a row naming a
  hidden child's `id` updated it. A save now leaves hidden children as
  they are, position included, and refuses such a row as `unknown_row`.
- `required()` on a `KeyValue` field refused every value. Any non-empty
  object now passes, and an empty one is refused with `not_blank`, as a
  blank string is.
- A `KeyValue` key holding a NUL character was stored, a leading or
  trailing one trimmed away. Such a key is now refused with
  `invalid_key`.
- The rows endpoint of a relationship repeater loaded at most `max()`
  children, so saving the form deleted every child past `max()`. It now
  loads all of them, and `max()` refuses that save with a 422.
- Vector wrote and read JSON 512 levels deep, which no database stores:
  on MySQL an audit row deeper than 100 levels failed the write with
  `SQLSTATE[22032]` (3157) after the record was saved. A `Json` field's
  value, or a JSON repeater's rows, now nests at most the least of what
  the record's connection stores, what Vector's connection stores less
  the two levels the audit row wraps around it, and 507, the most an
  answer carries; a deeper one is a `422` on the field, `too_deep`, with
  the limit as `max`, and nothing is written. The audit trail and a
  record's JSON columns are read as deep as their connection stores, and
  an import's `Json` cell 507 levels deep, so one nested deeper is
  `too_deep`, with the field's own limit as `max`, rather than
  `type_mismatch`.
- A record holding a value deeper than the audit row stores, saved
  before that limit, failed its next update with a `500` after the
  record was saved. The audit entry now records that earlier value as
  `AuditStore::TOO_DEEP` (`{"truncated": "too_deep"}`), and reverting
  the entry is refused with `not_revertable` instead of writing the
  marker into the record.

## [0.17.1] - 2026-11-23

### Changed

- Requires `kinetis/framework` ^1.31.0.

## [0.17.0] - 2026-11-23

### Added

- The MCP tool `vector_aggregate`: counts, sums, averages, minimums and
  maximums (1 to 6 metrics) over every record a `filter` and `q` match,
  read by the list's own grammar, under its scope, trash predicate and
  `viewAny`, grouped by up to three keys: a value, a belongsTo's key
  (`{id, label}`) or a date or datetime by `day`, `week`, `month`,
  `quarter` or `year` in UTC, on MySQL and PostgreSQL; sorted by a metric
  or key, at most 100 groups, with `groupCount` and `truncated`. A field
  is eligible when the user's descriptor publishes it, its list or record
  view shows it, and it is no
  computed, rich-text, key-value or collection field. Money stays in
  minor units, and a field priced in a currency field of its record is
  grouped by that currency too. `Query\AggregateRequest` validates the
  request and `ResourceService::aggregate()` runs it.

- `vector_query` takes `fields`, a list of field names: each row then
  carries only those, its key, and the `_version` and `_trashed` it
  carries anyway. Without it a page is unchanged.

- `Http\VisibleResources::all()` (the resources this request's user may
  list, as they see them, with their permissions) is decided once per
  request: the descriptor, the OpenAPI document and a shell contributor
  that injects it share one pass of the resource and field policies;
  `decided($slug)` reads one resource from that pass when it was made,
  and asks no policy when it was not.

- `Auth\ActorScope::run()` runs a closure on a request scope of its own,
  created as the worker creates a job's, acting as a given admin (their
  `ActorContext` and user registered on it), and disposes of it as
  kinetis/queue's `SyncQueue` disposes of a job's: the closure's own
  failure is rethrown, and a disposal failure on top of it is logged at
  error rather than put in its place; alone, it is the outcome.
  `JobContext::inFreshScope()` runs a closure that way as the job's
  actor.

- A queued action knows its job and can leave it a file:
  `ActionRequest::$jobId` names the job every chunk (and the run's
  `finish()`) runs in, null on the synchronous path, and
  `VectorAction::finish()` runs once after a queued run's last chunk: a
  `download` it answers becomes the job's file and message, `danger`
  fails the job, null (the default) changes nothing.
  `ActionResponse::download()` takes an optional message for it.
- An action can declare the records it acted on:
  `ActionResponse::withRecords(list<int|string>)`. Only those, of the
  records it was handed, are logged in `vector_action_events` and get an
  `action:<name>` audit entry (none for an empty list), and `affected`
  is their count rather than the chunk's size. An identifier the action
  was not handed is ignored. A response that declares nothing behaves as
  before; the response's JSON is unchanged.

### Changed

- Requires `kinetis/framework` `^1.30.0`, whose console answers `--help`
  and `-h` on every command, Vector's included, with the command's usage
  and description, without running it.
- A queued action's every chunk, and its `finish()`, now run on a request
  scope of their own (`RunActionJob`, through `JobContext::inFreshScope()`),
  the resource read anew as the admin sees it: a request-scoped cache
  filled during one chunk is no longer what the next one sees.
- `vector:install-ui` installs `@kinetis/vector-ui` 0.14.0 by default,
  the release that clears SonarQube findings in the assistant's drawer.

### Fixed

- `vector_aggregate` refuses a field neither the list nor the record view shows (as a group key, metric, sort or a money metric's currency), as a list's `columns` does.

## [0.16.0] - 2026-11-19

### Added

- A metadata bag: `Field::meta(string $key, mixed $value)` and
  `#[Resource(meta: [...])]`, published verbatim as `meta` on the field
  and the resource in the descriptor. Vector never reads it; keys are
  namespaced by convention (`ai.send`). Omitted when empty, so a
  resource that declares none is published, and described by
  `vector_describe`, exactly as before.
- Shell contributors: a class implementing
  `Http\ShellContributorInterface` and carrying
  `#[ShellContributor(name: '...')]` is discovered at compile time and
  answers, per user and per request, `capabilities.<name>` in
  `GET /vector/schema`. A malformed name, one taken twice or one of the
  built-in capability keys stops the compile.
- A write's origin: `ActorContext` takes an optional `origin` token
  (`assistant`, say) for a write that did not come from the admin's own
  hand, recorded in a new nullable `origin` column of `vector_audit` and
  `vector_action_events` (the `vector_write_origin` migration) and
  returned on each entry of `GET /vector/r/{resource}/{id}/audit`. Null
  for every other write, and not written at all then, so an installation
  that has not applied the migration keeps recording its writes.

### Changed

- The descriptor is version 6 (`VectorRegistry::VERSION`): an AOT cache
  compiled at version 5 is refused and rebuilt, and the UI must be
  @kinetis/vector-ui 0.13.0 or later.
- `vector:install-ui` installs `@kinetis/vector-ui` 0.13.0 by default.
- Requires PHP 8.4 or 8.5 (`>=8.4 <8.6`). Every Kinetis requirement is
  raised to its latest release: `kinetis/framework` 1.29.1 and the rest
  of the Kinetis packages one patch release up.

## [0.15.1] - 2026-11-16

### Changed

- `vector:install-ui` installs `@kinetis/vector-ui` 0.12.5 by default,
  the release built against Tiptap 3.34.0.

## [0.15.0] - 2026-11-16

### Changed

- Vector's console commands write through `Kinetis\Console\Output`, the
  output the command runner binds (framework 1.27), instead of `echo`
  and `fwrite()` to `STDOUT`/`STDERR`. Their messages, exit codes and
  the stdout/stderr split are unchanged. This changes public
  constructors:
  - `ListCommand(ProjectRoot $projectRoot, Output $output)`: the
    `$output` stream is now an `Output`.
  - `PruneFilesCommand(FilePruner, VectorStorage, Output $output)`:
    the `$output` and `$errors` streams become one `Output`.
  - `Queues\Console\PruneMetricsCommand(JobMetricsStore, MetricsClock,
    Output $output)`: the same.
  - `InstallUiCommand(Output $output, ?UiTarballSource $source = null)`:
    the same, and `$source` moves to the second position.
  - `InstallCommand(Output $output)` and
    `ReapJobsCommand(StalledJobs $stalled, Output $output)` gain an
    `Output`.

  Code that runs these commands through `vendor/bin/kinetis` is
  unaffected. Code that constructs one directly passes an `Output`,
  for instance `new Output($stdout, $stderr, false, false)`. A test can
  use `TestApplication::command()` instead.
- An empty `VECTOR_UI_DIR`, `VECTOR_LOGIN_URL` or `VECTOR_LOGOUT_URL`
  (`KEY=`) falls back to its default, `public/vector/ui`, `/login` or
  `/logout`, instead of an empty directory or URL. Every `VECTOR_*`
  setting now treats an empty value as unset. Through framework 1.26's
  `Config::string()` this also applies to `VECTOR_EXPORT_FORMATS`: an
  empty value now offers the default `csv,xlsx` rather than CSV alone.
  Set `VECTOR_EXPORT_FORMATS=csv` to offer only CSV.
- Requires `kinetis/framework` ^1.28.0, `kinetis/mcp` ^1.9.0,
  `kinetis/queue` ^1.10.2, `kinetis/migrations` ^1.5.0,
  `kinetis/authorization` ^1.0.8, `kinetis/database-bridge` ^1.4.5,
  `kinetis/orm` ^1.13.3, `kinetis/persistence` ^1.4.7 and
  `kinetis/query-builder` ^1.8.4. The suggested
  `kinetis/queue-failed-jobs` is ^1.2.0.
- `vector:install-ui` installs `@kinetis/vector-ui` 0.12.4 by default,
  the release built against Tiptap 3.33.1, vue-i18n 11.4.19 and
  Vuetify 4.3.3.

## [0.14.3] - 2026-11-08

### Changed

- `vector:install-ui` installs `@kinetis/vector-ui` 0.12.3 by default,
  the release whose key-value cell and input show nested values as JSON.
- Failures while installing the hosted UI throw
  `Kinetis\Vector\Ui\UiInstallException`, which extends
  `\RuntimeException`, so existing catches still apply; their messages
  are unchanged.
- The tar reader, HTTPS tarball source, `vector:install-ui` usage checks
  and the queue dashboard's query parsing are restructured to clear
  SonarQube complexity findings. Their checks, order and messages are
  unchanged.

## [0.14.2] - 2026-11-08

### Changed

- `vector:install-ui` installs `@kinetis/vector-ui` 0.12.2 by default,
  the release whose resource table marks its unawaited list loads as
  intentional.

## [0.14.1] - 2026-11-08

### Changed

- The check that a repeater row is an object of field values is split in
  two, so Psalm 7.1 no longer reads the row as an empty array after it.
  The rows it accepts and the `type_mismatch` it reports are unchanged.
- `vector:install-ui` installs `@kinetis/vector-ui` 0.12.1 by default,
  the release that requires Tiptap `^3.33.0`.

## [0.14.0] - 2026-11-03

### Added

- A queue dashboard, built in (`Kinetis\Vector\Queues\`, under
  `/vector/queues`): queue sizes and clears on the application's
  `QueueInterface`, the failed jobs `kinetis/queue-failed-jobs` keeps
  (browsed, retried, forgotten and pruned) and per-minute job metrics,
  behind `QueueDashboardPolicyInterface` (view-only by default, an
  application's own binding wins) and watching `VECTOR_QUEUES`. It is the
  code of the separate queue-dashboard package, never released, whose
  classes keep their names. The descriptor reports `capabilities.queues` (a queue
  bound and the policy letting this user view) and
  `capabilities.failedJobs` (that, and `kinetis/queue-failed-jobs`
  installed and wired). Without the first every `/vector/queues`
  endpoint answers the Kernel's own `404` and the queues card is not
  viewable; without the second the `/vector/queues/failed` endpoints do,
  the overview's `failed` is `null` and the permissions name no
  failed-job action. `kinetis/queue-failed-jobs` is a suggestion, not a
  requirement. The audit trail records the dashboard's actions under the
  resource `vector-queues`, prefixed to keep it apart from the application's own resources.
- `VECTOR_QUEUE_METRICS` turns the queue dashboard's job metrics off.
  Unset or blank, they are on, as before. `false`, `0`, `off` or `no`
  turns them off, and a value `Config::bool()` cannot read stops the boot.
  Each worker reads it once, at boot. Off, the listener records nothing,
  builds neither the metrics store nor its link and logs no warning, even
  without `vector_queue_metrics`. `GET /vector/queues/metrics` then
  answers the Kernel's own `404`, after the dashboard's own `404` and
  before any `422`, and the descriptor's new `capabilities.queueMetrics`
  (the dashboard there and the metrics on) is `false`.
  `vector:queues:prune-metrics` and the migration stay as they are.
- `vector_queue_metrics`, the job metrics, is one of Vector's own tables,
  on Vector's own connection (`VECTOR_DB_CONNECTION`): `vector:install`
  publishes its migration, `20260926000008_vector_queue_metrics`, for
  MySQL 8.0.19 or later and PostgreSQL. Run `vector:install` and
  `migrate` after upgrading. Every `queue:work` process records metrics;
  until the table exists, each worker logs one warning a minute and the
  jobs run as before. `vector:queues:prune-metrics --days=<n>` prunes it.
- The built-in widget type `queues` (`VectorWidget::QUEUES`,
  `WidgetResult::queues()`), the queue dashboard's card, which
  `QueuesWidget` uses. It joins `VectorWidget::BUILT_IN_TYPES`. The
  label `Queues` is in Vector's translations for every locale.
- Extension widget types: an application's widget may return its own
  lower-case kebab-case `type()` that is none of the six built-in types,
  such as `sparkline`, and answer it with
  `WidgetResult::extension($type, $data)`, whose data is served as it
  is. The UI renders it with the card the application registers, through
  `defineWidget` in a runtime extension or `app.config`
  `vector.widgets`. `WidgetResult::extension()` refuses a built-in type
  and the keys `type`, `range` and `cached`. Any other type is still
  refused at compile time, now with a message that says what a type may
  be. The descriptor schema's widget `type` is the kebab-case pattern
  instead of the built-in enum, and `VectorWidget::BUILT_IN_TYPES`,
  `KEBAB_CASE` and `isExtensionType()` are new.

### Changed

- Requires the Kinetis releases built with Psalm 7:
  `kinetis/framework` `^1.24.3`, `kinetis/authorization` `^1.0.7`,
  `kinetis/database-bridge` `^1.4.3`, `kinetis/mcp` `^1.8.3`,
  `kinetis/mcp-protocol` `^1.1.2`, `kinetis/migrations` `^1.4.3`,
  `kinetis/orm` `^1.13.2`, `kinetis/persistence` `^1.4.6`,
  `kinetis/query-builder` `^1.8.3` and `kinetis/queue` `^1.9.1`.
- **Breaking:** the descriptor is version 5 (`VectorRegistry::VERSION`,
  and the descriptor schema's `version`), so it pairs with an
  `@kinetis/vector-ui` that reads version 5, and a hosted bundle must be
  rebuilt for it. A registry cached by an earlier version is compiled
  again. The descriptor's `ui` carries `expected` alone.
- `vector:install-ui` installs `@kinetis/vector-ui` 0.12.0 by default,
  the release that hands extensions a wider set of Vuetify components
  and requires Node 26.

## [0.13.4] - 2026-10-30

### Changed

- Requires `kinetis/framework` `^1.24.1`, `kinetis/orm` `^1.13.1`,
  `kinetis/authorization` `^1.0.6`, `kinetis/database-bridge` `^1.4.2`,
  `kinetis/mcp` `^1.8.2`, `kinetis/mcp-protocol` `^1.1.1`,
  `kinetis/migrations` `^1.4.2`, `kinetis/persistence` `^1.4.5`,
  `kinetis/query-builder` `^1.8.2`, `kinetis/queue` `^1.7.2` and
  `ueberdosis/tiptap-php` `^2.2.1`.
- `vector:install-ui` installs `@kinetis/vector-ui` 0.11.4 by default,
  the release that requires Tiptap `^3.32.2`.

## [0.13.3] - 2026-10-30

### Changed

- `vector:install-ui` installs `@kinetis/vector-ui` 0.11.3 by default,
  the release that requires Nuxt `^4.6.3` for its path-check, route-rule
  and dev server security fixes.

## [0.13.2] - 2026-10-30

### Changed

- `vector:install-ui` installs `@kinetis/vector-ui` 0.11.2 by default,
  the release that requires `vue-i18n` `^11.4.16` for its own-property
  message lookups.

## [0.13.1] - 2026-10-23

### Changed

- `vector:install-ui` installs `@kinetis/vector-ui` 0.11.1 by default,
  the release that requires Nuxt `^4.6.2` for its server island and
  route-rule security fixes.

## [0.13.0] - 2026-10-23

### Added

- `kinetis vector:install-ui` installs the hosted admin UI into the
  served directory, `VECTOR_UI_DIR` or `public/vector/ui`. By default it
  downloads the `@kinetis/vector-ui` release this package pins in
  `resources/ui.json` from the npm registry, over HTTPS, and checks the
  tarball against the registry's sha512 `dist.integrity` before
  extracting it. `--version=X.Y.Z` installs another published release;
  `--from=<tgz|dir>` installs a packed tarball (checked against
  `--integrity=sha512-…` when given) or a built `dist/static` directory;
  `--force` reinstalls a release that is installed and verifies already;
  `--check` verifies the served directory against its
  `vector-bundle.json` and the expected release without the network.
  Exit codes are 0 (done or in sync), 1 (refused or failed) and 2 (a
  usage error). `VECTOR_UI_REGISTRY` names another registry, and
  `VECTOR_UI_REGISTRY_TOKEN` a bearer token sent to that registry's host
  only. A bundle is staged and verified whole before anything in the
  served directory changes; `index.html` and `vector-bundle.json` are
  written last, and only files the previous manifest listed are pruned.
- `resources/ui.json` pins `@kinetis/vector-ui` 0.11.0, and
  `/vector/schema` publishes it as `ui.expected`, so the dashboard can
  say when it runs another release.

### Changed

- `kinetis vector:install` publishes the migrations only, then notes
  when the served admin UI is missing, has no `vector-bundle.json`, or is
  not the pinned release.
- The hosted bundle is no longer part of this package: it ships inside
  `@kinetis/vector-ui`, and `vector:install-ui` installs it.
- Requires `ext-zlib` and `ext-openssl`.

## [0.12.2] - 2026-10-22

### Changed

- The `vector_widget` MCP tool resolves its default dashboard and range
  before choosing which widget to compute. Its behavior is unchanged.

## [0.12.1] - 2026-10-22

### Changed

- The write, import and file-prune paths, list parsing, search, the MCP
  tools, the OpenAPI document, rich text, the registry compilers and the
  install command are split into smaller methods. Their behavior,
  responses and failure codes are unchanged.
- Ships the `@kinetis/vector-ui` 0.10.4 hosted bundle.

## [0.12.0] - 2026-10-22

### Added

- `kinetis vector:files:prune` deletes uploads no record uses: a file
  under a declared file or image field's `vector/{resource}/{field}/`,
  older than `--grace-hours=<n>` (24), that no file or image column of
  any row holds (trashed rows included), no rich-text document using
  the field as its `imageField` shows, and no entry of the resource's
  audit trail holds, since a revert writes an old value back.
  `--dry-run` lists what would be deleted. Nothing outside `vector/` is
  ever touched. Run it from a scheduler.

### Changed

- Uploading to a file or image field needs write permission, not just
  list (`viewAny`): the field (or a rich-text field uploading its
  images through it) on the create form while the policy allows
  `create()`, or on the update form while it allows `update()` on the
  record the upload names in its new multipart `record` part. Otherwise
  the upload answers `403`. The UI's inputs send the id of the record
  they edit; a client of its own that uploads while editing must send
  `record` too, or the account needs create permission. A rich-text
  field's image field no longer needs to be on a form itself.
- Ships the `@kinetis/vector-ui` 0.10.3 hosted bundle, whose upload
  inputs send that `record` part.

### Security

- A job whose push the queue backend refused stored the backend's own
  message on its row (hosts, ports, and whatever else the driver put in
  it), where the admin's Jobs page shows it. The row now says only that
  the job failed unexpectedly, as a job failing in the worker does; the
  message reaches the log with the rethrown exception.
- The MCP `vector://schema` resource listed every action of a resource,
  including those the policy's `runAction()` or the action's own
  `canRun()` refuses this user, which `GET /vector/schema` leaves out.
  It now publishes the same actions.
- `POST /vector/views/{resource}` saved a view of a resource whose list
  the policy's `viewAny()` refuses this user, which the views index
  already refused. Creating a view now asks `viewAny()` first and
  answers the same `403`.
- An import's failed-rows file echoed the uploaded cells as they came,
  so a cell such as `=HYPERLINK(…)` ran as a formula when the admin
  opened the file in a spreadsheet. Its headers and cells now get the
  same formula guard as CSV and XLSX exports.
- `GET /vector/r/{resource}/{id}/files/{field}` served whatever key the
  record held. Only a write checks the key, and only when storage was
  available at the time, so a value written otherwise could point at
  another field's upload or walk out of its prefix with `..`. A download
  now answers `404` for a key with an empty, `.` or `..` segment, a
  leading `/`, a backslash or a control character, or one under
  `vector/` but outside the field's own `vector/{resource}/{field}/`.
  Keys outside `vector/`, which applications and uploads-off forms
  write, are still served.
- Several request-value patterns ended in `$` without the `D` flag, so a
  trailing newline slipped through: a rich-text link's `rel`, a code
  block's `language`, an image's `src` and size, a date-time value, a
  widget's `range`, a preference key and the branding colours. Each now
  refuses it as it refuses any other value outside its pattern.

### Fixed

- A job whose completion notification could not be stored was marked
  failed after its row had already been finished. The row now stays
  done, and the failed notification is logged.
- An import whose failed-rows file came to more than 12 MiB, the most a
  job file holds, failed at the end with a `LengthException` although
  every row had been processed. The file now keeps the failed rows that
  fit, whole, the last one's `error` saying how many more are not in it,
  and the import completes.
- An enum value of the scalar type its enum is not backed by (any
  `filter[field]=…` on an int-backed enum, or a string sent for one in
  a body) answered `500` with a `TypeError` from `tryFrom()`. A list
  filter now matches an int-backed enum by its digits, and any other
  value is refused as `invalid_choice` (`422`).
- A CSV import refused a non-empty cell of a date-only field
  (`Kinetis\Orm\Date`) on every row with "must be a date as
  YYYY-MM-DD.": the parsed date reached the writer as an object rather
  than the text a body carries.
- A row of a CSV import that the database refused (a duplicate unique
  value, a value too long for its column) stayed pending in the entity
  manager, so the next row's write sent it again and failed with that
  row's reason, up to the next hundred rows. A refused row's pending
  changes are now discarded before the next row.
- The descriptor published each resource's `primaryKey.column`, the
  key's database column, which its JSON schema does not declare and no
  client reads; every other column it already leaves out. The key is
  `{field, type}`, as the MCP description has it.
- `descriptor.schema.json` did not declare two keys the descriptor
  publishes: a resource's `show.extra` (as `table.extra` is) and a
  relation's `owned`.

## [0.11.0] - 2026-10-21

### Added

- `Kinetis\Vector\Import\CsvReadException` and
  `Kinetis\Vector\Query\ExportWriteException`, thrown where the CSV
  reader and the CSV and XLSX export writers threw a bare
  `\RuntimeException`, with the same messages. Both extend
  `\RuntimeException`, so an existing `catch` still catches them.

### Fixed

- An integer identifier, list cursor, `page`, `perPage` or integer
  value no longer accepts a trailing newline: `"42\n"` read as `42`,
  because the patterns ended in `$` without the `D` modifier. It is now
  refused like any other non-integer: 404 for a record, job,
  notification, view or history entry, 422 for a list parameter or a
  field value.
- Ships the `@kinetis/vector-ui` 0.10.2 hosted bundle, with its keyboard
  and screen-reader fixes and its linear-time e-mail and Regex rule
  checks.

## [0.10.1] - 2026-10-21

### Security

- Ships the `@kinetis/vector-ui` 0.10.1 hosted bundle, built with
  `markdown-it` 15.0.3, which fixes two quadratic-time denials of
  service in rendering Markdown.

## [0.10.0] - 2026-10-20

### Added

- `GET /vector/openapi.json`: Vector's REST API as an OpenAPI 3.1
  document with concrete paths for the resources the caller may list,
  filtered through the same per-identity overlay as the descriptor, so a
  hidden resource, field or action never appears. It is served where the
  framework serves its own `/openapi.json`, per `OPENAPI_ENVIRONMENTS`
  and `APP_ENV`; elsewhere its path answers 404 before authentication.
- A searchable `belongsTo` makes `q` match the related record's title,
  through a subquery that applies neither the target's `scope()` nor its
  trash. Compilation refuses one whose target's title is not a string
  column.
- `VectorResource::fullText()`: `q` matches the named searchable text
  fields by whole words through their full-text index, `MATCH ...
  AGAINST` in boolean mode on MySQL and `to_tsvector @@ plainto_tsquery`
  on PostgreSQL. LIKE stays on the other searchable columns.
- `VectorResource::withoutCount()`: an offset list page skips its COUNT.
  The page is `{data, currentPage, perPage, total: null, lastPage: null,
  hasMore}`, with `hasMore` read from one row past the page through the
  same policy, scope and trash predicate; summaries still work. A counted
  resource's page is unchanged and carries no `hasMore`. Its export does
  not count the match either: `ResourceService::exportCount()` takes a
  `$cap` and reads at most one row past it, which is enough to choose
  between inline and queued, and a refusal says "more than N records".
- `VectorResource::cursorPaging()`: the admin pages the list by cursor.
  The REST API is unchanged: `?page=` and `?cursor=` work on every
  resource.
- Both flags are published in the schema's `table` (`withoutCount`,
  `cursorPaging`). The OpenAPI document gives a withoutCount resource's
  list `total` and `lastPage` as `null` and documents `hasMore`;
  `vector_describe` marks it `withoutCount`, and `vector_query` says so.
- `VectorResponse::assertHasMore()`. `assertTotal()` on an uncounted page
  now fails with a message pointing to it.

### Changed

- Ships the `@kinetis/vector-ui` 0.10.0 hosted bundle, which pages
  uncounted and cursor lists, and makes `VectorPage.total` and
  `lastPage` nullable.

### Fixed

- `ListRequest::fromQueryParams()` takes a caller's `perPageCap` only as a
  tighter bound: a cap above the resource's `maxPerPage()` no longer raises
  the page size a request may ask for.
- Two compile refusals read correctly: a badge without a color says
  "color", and a repeater naming a field its rows cannot write no longer
  puts "a" before the child's label ("not a writable field of Order
  line").

## [0.9.5] - 2026-10-19

### Changed

- Ships the `@kinetis/vector-ui` 0.9.4 hosted bundle, which renames the
  toast type to `VectorToast`.

## [0.9.4] - 2026-10-19

### Changed

- Documentation: the manual reviewed for correctness against the code;
  the agent guide moved to the Kinetis documentation as
  `kinetis://docs/vector`.

## [0.9.3] - 2026-10-18

### Changed

- Released alongside `@kinetis/vector-ui` 0.9.3, whose bundled package is
  unchanged from 0.9.2; nothing in `kinetis/vector` changed.

## [0.9.2] - 2026-10-18

### Changed

- The hosted bundle is built from `@kinetis/vector-ui` 0.9.2
  (Nuxt 4.6.1, Vue 3.5.44, Vuetify 4.2.6).

## [0.9.1] - 2026-10-18

### Changed

- The hosted bundle is built from `@kinetis/vector-ui` 0.9.1
  (Tiptap 3.32.0).

## [0.9.0] - 2026-10-18

### Added

- Resources over `kinetis/orm` entities, compiled into one immutable
  descriptor at `kinetis build`: derived or declared fields, layouts,
  conditions, dependent choices, repeaters, computed and rich-text
  fields, badges and global search, and several resources over one
  entity.
- A REST admin API under `/vector`: lists with filters, "any of" groups,
  sorting, grouping, summaries and cursors; create, update, delete,
  restore, batch and inline edits under optimistic locking; uploads;
  CSV and XLSX export and CSV import; saved views, preferences,
  settings groups, dashboards and widgets, notifications and
  impersonation.
- Resource policies, row scope and field visibility, enforced on every
  request, and a field-level audit trail with revert.
- Actions, run directly or as background jobs on the application's
  queue, with large exports and imports as jobs too.
- MCP tools and the `vector://schema` resource over the same services.
- The hosted admin shell at `/vector/ui`, under a strict Content
  Security Policy, and labels translated per user.
- MySQL and PostgreSQL on every connection; Vector's own tables on a
  connection of their own.
- `vector:install`, `vector:list` and `vector:jobs:reap`, and
  `Kinetis\Vector\Testing` for an application's own test suite.
