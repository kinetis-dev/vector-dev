# Resources

A resource makes a `kinetis/orm` entity administrable. It is a class
extending `Kinetis\Vector\VectorResource` with a `#[Resource]` attribute,
discovered anywhere under your application namespace.

```php
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\{BelongsTo, Boolean, Enum, Id, ManyToMany, Money, Number, Text};
use Kinetis\Vector\VectorResource;

#[Resource(entity: Product::class, group: 'Catalog', icon: 'mdi-package-variant', title: 'name')]
final class ProductResource extends VectorResource
{
    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('sku', 'SKU')->required()->max(40)->sortable()->searchable()->filterable(),
            Text::make('name')->required()->max(200)->sortable()->searchable()->filterable(),
            Enum::make('status')->of(ProductStatus::class)->sortable()->filterable(),
            BelongsTo::to('category', CategoryResource::class)->sortable()->filterable(),
            Money::make('priceCents', 'Price')->currencyField('currency')->summarize('avg'),
            Number::make('stock')->sortable()->filterable()->summarize('sum'),
            Boolean::make('isFeatured', 'Featured')->filterable(),
            ManyToMany::to('tags', TagResource::class),
        ];
    }

    public function defaultSort(): array { return ['-createdAt']; }
    public function softDeletes(): string { return 'deletedAt'; }
    public function actions(): array { return [PublishProducts::class]; }
    public function widgets(): array { return [ProductsByStatus::class]; }
}
```

`#[Resource]` names the `entity`, the menu `group`, an `icon` (a
Material Design Icons name) and the `title` field that names a record in
breadcrumbs, relations and search. `slug` overrides the URL name; by
default the mapped table name is used with underscores replaced by dashes.
`label` and `labelSingular` override the display names derived from the
class.

## Several resources over one entity

An entity may have more than one resource: a second view of the same
rows with its own fields, policy, scope and actions, such as the demo's
`profiles` beside `users`. Each needs its own `slug`, since the default
one, the table name, can only be taken once. Each is a resource in its
own right: its policy, writes, audit trail, jobs, MCP tools and menu
entry are keyed by its slug.

A relation that names its resource, `BelongsTo::to('owner',
ProfileResource::class)`, links to that one. A relation that names none
— a field derived from the entity's mapping — links to the entity's
relation target: its only resource, or, when it has several, the one
marked `relationTarget: true`:

```php
#[Resource(entity: User::class, title: 'name', relationTarget: true)]
final class UserResource extends VectorResource { /* ... */ }

#[Resource(entity: User::class, slug: 'profiles', title: 'name')]
final class ProfileResource extends VectorResource { /* ... */ }
```

Compilation fails when an entity with several resources marks none of
them, or more than one, naming the entity and every resource over it.
An entity with a single resource needs no mark.

A resource's hooks and policy govern its own requests only. The demo's
`UserResource` refuses to delete the last admin in `beforeDelete()`,
which a delete through `profiles` would not run, so `profiles` refuses
create and delete in its policy instead.

## Derived or declared fields

An empty resource derives every field from the entity's mapping: a
string column becomes a searchable `Text`, a backed enum a filterable
`Enum`, a `#[BelongsTo]` a relation when its target has a resource, and
so on. Declaring `fields()` replaces derivation entirely. See
{doc}`fields`.

## Definition methods

These run once, at compile time, on an instance built without
arguments, and return plain data:

| Method | Declares |
|---|---|
| `fields()` | The fields, in order, with optional sections and tabs; see {doc}`forms`. |
| `actions()` | Action classes; see {doc}`actions`. |
| `widgets()` | Widget classes shown above the list; see {doc}`dashboards`. |
| `policy()` | The policy class; see {doc}`authorization`. |
| `defaultSort()` | The list's order, such as `['-createdAt', 'name']`. |
| `perPage()`, `perPageOptions()`, `maxPerPage()` | The default page size, the sizes offered, and the most one request may ask for. |
| `softDeletes()` | The nullable `DateTimeImmutable` property that marks a record trashed. |
| `timestamps()` | The properties Vector stamps on create and on every update; `createdAt` and `updatedAt` by default. |
| `exportLimit()`, `exportQueueLimit()`, `bulkLimit()` | Per-resource limits; see {doc}`limits`. |
| `badge()` | A count beside the resource in the menu. |
| `replicable()` | Whether records can be duplicated; true by default. |
| `searchSubtitle()`, `searchImage()` | What global search and a relation's choice list show under the title and as a thumbnail. |

## Request-time hooks

These run on an instance the request container builds, so they may
inject anything a controller may:

- `scope(Query $query, CurrentUserInterface $user)` narrows the rows a
  user sees — everywhere: lists, counts, exports, badges, search.
- `create()` builds a new entity from the validated values, and
  `apply()` writes them to a loaded one. Returning `null` or `false`
  leaves it to Vector's default writer.
- `beforeCreate()`, `beforeUpdate()`, `beforeDelete()` and their
  `after*` counterparts run around each write, after validation and
  before the flush.

## Badges

A badge counts the records matching a filter, as the user's own list
would count them, and shows the count in the menu:

```php
public function badge(): Badge
{
    return Badge::count(['status' => 'open'], 'info', 'open tickets');
}
```

The filter uses the list grammar and must name filterable fields, with
operators they take. It is counted with the resource's scope and soft
deletion applied. The UI polls `GET /vector/badges` every minute and
after each navigation. A user who may not list the resource, or see a
filtered field, gets no badge.

## Global search

Every resource is globally searchable unless `globallySearchable()`
returns false. A match is listed by its title, with
`searchSubtitle()`'s field beneath (such as a SKU) and
`searchImage()`'s image as a thumbnail. A relation's choice list in a
form shows the same, so a customer is picked by name and e-mail and a
product by name, SKU and image. Either is left out for a user who may
not see its field.

## Checked at build

Compilation refuses, naming the class and field: a field that maps no
property, a `#[BelongsTo]` declared without `BelongsTo::to()`, a
relation to an unregistered resource, a sortable field without a
column, a searchable field that is not a string column, a filterable
collection, a summary over a non-numeric column, a default sort over a
field that is not sortable, an unadministered identifier, a
`softDeletes()` property that is not a nullable `DateTimeImmutable`,
duplicate field, action or widget names, a slug already taken, and an
entity with several resources but not exactly one `relationTarget`.
The pages on {doc}`fields` and {doc}`forms` list what each feature
refuses besides.

## Listing what compiled

`vector:list` compiles the registry afresh, as `kinetis build` would,
and prints its resources, settings groups and dashboards; it reads and
writes no cache. A compile error fails it with the same message. The
labels are the declared ones, before translation, and every resource is
listed whatever its policy: what a given user sees is
`GET /vector/schema` or the MCP tool `vector_describe`. In the demo:

```console
$ vendor/bin/kinetis vector:list
Resources:
Slug               Label              Group    Entity                          Resource                                Connection
-----------------  -----------------  -------  ------------------------------  --------------------------------------  ----------
agents             Agents             Support  App\Domain\Support\Agent        App\Vector\Support\AgentResource        support
categories         Categories         Catalog  App\Domain\Category             App\Vector\CategoryResource             default
customer-profiles  Customer profiles  Sales    App\Domain\CustomerProfile      App\Vector\CustomerProfileResource      default
customers          Customers          Sales    App\Domain\Customer             App\Vector\CustomerResource             default
labels             Labels             Support  App\Domain\Support\Label        App\Vector\Support\LabelResource        support
order-lines        Order lines        Sales    App\Domain\OrderLine            App\Vector\OrderLineResource            default
orders             Orders             Sales    App\Domain\Order                App\Vector\OrderResource                default
products           Products           Catalog  App\Domain\Product              App\Vector\ProductResource              default
profiles           Profiles           Access   App\Domain\User                 App\Vector\ProfileResource              default
support-teams      Teams              Support  App\Domain\Support\Team         App\Vector\Support\TeamResource         support
tags               Tags               Catalog  App\Domain\Tag                  App\Vector\TagResource                  default
ticket-replies     Ticket replies     Support  App\Domain\Support\TicketReply  App\Vector\Support\TicketReplyResource  support
tickets            Tickets            Support  App\Domain\Support\Ticket       App\Vector\Support\TicketResource       support
users              Users              Access   App\Domain\User                 App\Vector\UserResource                 default

Settings groups:
Name       Label            Class
---------  ---------------  ---------------------------------
branding   Branding         Kinetis\Vector\Settings\Branding
jobs       Background jobs  Kinetis\Vector\Settings\Jobs
languages  Languages        Kinetis\Vector\Settings\Languages
store      Store            App\Vector\Settings\StoreSettings

Dashboards:
Name  Label     Class
----  --------  -----------------------------------
main  Overview  App\Vector\Dashboards\MainDashboard
```

A resource in no navigation group shows `—` in the Group column.

## See also

- {doc}`fields` — the field catalogue.
- {doc}`forms` — conditions, dependent choices and repeaters.
- {doc}`authorization` — who may do what.
