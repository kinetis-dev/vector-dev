# Testing your admin

`Kinetis\Vector\Testing` drives the REST API from your own test suite,
through the framework's `TestClient`, so your middleware, authentication,
policies and hooks all run as in production.

```php
use Kinetis\Vector\Testing\InteractsWithVector;

final class CatalogAdminTest extends ApplicationTestCase
{
    use InteractsWithVector;

    public function testEditorsCannotSeeCosts(): void
    {
        $products = $this->vector(['Authorization' => "Bearer {$this->editorToken()}"])->resource('products');

        $products->list(filter: ['status' => ['eq' => 'active']])->assertOk()->assertMissingField('costCents');
        $id = $products->create(['sku' => 'A-1', 'name' => 'Lamp'])->assertCreated()->id();
        $products->update($id, ['sku' => ''])->assertInvalid('sku', 'required');
        $products->action('reprice', [$id], ['percent' => 5])->assertQueued();
    }
}
```

`$this->vector($headers)` is a client authenticated as you choose.

- `resource($slug)` has one method per endpoint: `list`, `show`,
  `create`, `update`, `delete`, `restore`, `batch`, `changes`, `action`,
  `related`, `attach`, `detach`, `options`, `audit`, `revert`, `export`,
  `upload` and `file`.
- The client itself has `schema()`, `me()`, `search()`, `badges()` and
  `job()`; `withHeaders()` switches identity.

Every call answers a `VectorResponse` with chaining assertions —
`assertRecord`, `assertTotal`, `assertIds`, `assertListed`,
`assertEveryRecord`, `assertInvalid`, `assertForbidden`,
`assertPermission`, `assertHasField`, `assertQueued` and more. `raw()`
is the framework's `TestResponse`, for anything else.

## Waiting on the database without blocking

On a persistent worker, a request that waits on the database has to
yield to the event loop, so the worker can keep serving other requests.
The demo proves this for Vector's list, show, update and action
requests in `demo/backend/tests/Vector/LoopLivenessTest.php`, with the
framework's `LoopLiveness::turnedDuring()`.

PHPUnit gets a blocking PDO connection by default, so the test sets
`DB_DRIVER`, `DB_ADMIN_DRIVER` and `DB_SUPPORT_DRIVER` to `native` in
`configOverrides()`. A local database answers too fast to show anything,
so a separate process (`tests/Fixtures/hold-products-lock.php`) holds
`LOCK TABLES products WRITE` for 300 ms while each request runs. The
request's own statements wait on that lock through the connection Vector
uses. Each path also runs on `pdo`, where the same request must report
that the loop did *not* turn, so the proof can fail. The blocking setup
stays outside the observed request, and only those two files get the
PHPStan `kinetis.blockingCall` ignore.

## See also

- {doc}`authorization` — what to assert about.
- {doc}`rest-api` — what each call maps to.
