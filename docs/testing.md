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

## See also

- {doc}`authorization` — what to assert about.
- {doc}`rest-api` — what each call maps to.
