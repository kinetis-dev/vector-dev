# Getting started

Vector adds an admin to an existing Kinetis application that uses
`kinetis/orm`. This page takes one from install to a working admin: the
package, its tables, the three things your application provides, a
first resource, and the UI.

## Install

```console
composer require kinetis/vector
vendor/bin/kinetis vector:install
vendor/bin/kinetis migrate
```

`vector:install` publishes Vector's migrations — settings, preferences,
saved views, the action log, the audit trail, jobs and notifications —
for MySQL or PostgreSQL, as your connection's `DB_CONNECTION` says. It
also publishes the hosted UI bundle when the package ships one. It is
idempotent: run it again after every upgrade, then `migrate`.

To keep Vector's tables on a database of their own, see
{doc}`databases`.

## Wire authentication

Authentication stays your application's. Vector needs three things
from it:

1. **Middleware in the `vector` group that registers the user.** It
   authenticates the request your way (a JWT, a session) and puts a
   `Kinetis\Http\CurrentUserInterface` on the request scope. Add the
   `mcp` group too if agents will use the {doc}`mcp`.

   ```php
   #[AsMiddlewareGroup('vector', priority: 50)]
   #[AsMiddlewareGroup('mcp', priority: 50)]
   final readonly class AdminAuthMiddleware implements MiddlewareInterface
   {
       // authenticate, then register CurrentUserInterface on the RequestScope
   }
   ```

2. **An access policy.** `VectorAccessInterface` decides who may enter
   the admin at all. Bind it in `bootstrap.php`:

   ```php
   use Kinetis\Vector\Auth\VectorAccessInterface;

   $app->instance(VectorAccessInterface::class, new AdminsOnly());
   ```

   ```php
   final readonly class AdminsOnly implements VectorAccessInterface
   {
       public function canAccessVector(CurrentUserInterface $user): bool
       {
           return $user instanceof Staff && $user->isAdmin;
       }
   }
   ```

3. **An actor resolver, for background jobs.** Jobs run as the admin
   who started them. `ActorResolverInterface::resolve(string $actorId)`
   rebuilds that user in the worker. Without it, queued work is refused.

Impersonation is optional and needs one more binding; see {doc}`audit`.
{doc}`authentication` covers both UI modes in detail.

## Declare a resource

A resource is a class extending `VectorResource` with a `#[Resource]`
attribute naming the entity. Anywhere under your application namespace
is fine; it is discovered.

```php
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\VectorResource;

#[Resource(entity: Product::class, group: 'Catalog', icon: 'mdi-package-variant', title: 'name')]
final class ProductResource extends VectorResource
{
}
```

An empty class derives every field from the entity's ORM mapping. Once
you want control, declare `fields()`; see {doc}`resources` and
{doc}`fields`.

Vector compiles every resource into one descriptor at `kinetis build`,
and on each boot in development. A mistake — a field that maps no
property, a sort on a field without a column — fails the build with the
class and field named.

## Open the admin

Pick one of the two deployment modes; {doc}`deployment` compares them.

**Hosted.** Your application serves the UI at `/vector/ui` behind its
own session login. With the bundle published by `vector:install`, set
where your login page is and open `/vector/ui`:

```text
VECTOR_LOGIN_URL=/login
VECTOR_LOGOUT_URL=/logout
```

**Standalone.** The UI is a Nuxt application of its own that extends
the `@kinetis/vector-ui` layer and calls your API with a bearer token:

```console
pnpm add @kinetis/vector-ui nuxt vuetify vuetify-nuxt-module
```

```ts
// nuxt.config.ts
export default defineNuxtConfig({
    extends: ['@kinetis/vector-ui'],
    runtimeConfig: { public: { vector: { apiBase: 'https://api.example.com' } } },
})
```

Your API must then allow the UI's origin through `CorsMiddleware`, and
offer the `POST /auth/login` endpoint the sign-in page calls.

## Try the demo

The Vector repository ships a complete demo: a shop on MySQL and a
support desk on PostgreSQL, both admin modes, background jobs and a
seeded catalogue. From `demo/`:

```console
cp backend/.env.example backend/.env    # then set JWT_SECRET
docker compose up --build -d
docker compose exec app vendor/bin/kinetis migrate
docker compose exec --user www-data app vendor/bin/kinetis demo:seed
```

Open `http://localhost:3000` and sign in as `admin@vector.local` with
the password `password`.

## See also

- {doc}`core-concepts` — how the pieces fit together.
- {doc}`resources` — everything a resource declares.
- {doc}`using-the-admin` — the admin from a user's side.
