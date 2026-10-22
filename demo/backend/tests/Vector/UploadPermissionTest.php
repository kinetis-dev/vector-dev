<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\Vector\Files\VectorStorage;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Testing\InteractsWithVector;

/**
 * An upload writes to storage, so it needs write permission: the field
 * — or a rich-text field uploading its images through it — on the create
 * form while the user may create, or on the update form while the user
 * may update the record the upload names. Listing the resource is not
 * enough. Every file a test stores is removed again.
 */
final class UploadPermissionTest extends VectorTestCase
{
    use InteractsWithVector;

    /** A 1×1 PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private UploadPolicy $policy;

    /** @var list<string> */
    private array $stored = [];

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new UploadPolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);

        $registry = $app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $data = $registry->toArray();

        foreach ($data['resources']['products']['fields'] as $i => $field) {
            // the image field off the forms: only the rich-text description writes its uploads
            if ($field['name'] === 'imageKey' && str_starts_with($this->name(), 'testARichText')) {
                $data['resources']['products']['fields'][$i]['views'] = ['index', 'show'];
            }

            if ($field['name'] === 'description' && $this->name() === 'testARichTextFieldThatIsNotEditedGivesItsImageFieldNoUploads') {
                $data['resources']['products']['fields'][$i]['readonly'] = true;
            }
        }

        $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
    }

    protected function tearDown(): void
    {
        foreach ($this->stored as $key) {
            $this->storage()->delete($key);
        }

        parent::tearDown();
    }

    public function testAnAccountThatMayOnlyListCannotUpload(): void
    {
        $this->policy->deny = ['create', 'update'];
        $this->client->get('/vector/r/products', headers: $this->adminHeaders())->assertOk();

        $this->upload()->assertForbidden();
        $this->upload($this->productId())->assertForbidden();
    }

    public function testCreatePermissionAllowsAnUploadToAFieldOnTheCreateForm(): void
    {
        $this->policy->deny = ['update'];

        $this->upload()->assertOk();
        $this->upload($this->productId())->assertOk();
    }

    public function testUpdatePermissionAllowsAnUploadForTheRecordBeingEdited(): void
    {
        $this->policy->deny = ['create'];

        $this->upload()->assertForbidden();
        $this->upload($this->productId())->assertOk();
        $this->upload('999999999')->assertNotFound(); // a record that does not exist grants nothing

        // update decided for that record: the one the policy refuses gives no upload
        $this->policy->refuseRecord = $this->productId();
        $this->upload($this->productId())->assertForbidden();
    }

    public function testARichTextFieldUploadsItsImagesThroughAnImageFieldOffTheForms(): void
    {
        $this->policy->deny = ['update'];
        $this->upload()->assertOk();

        $this->policy->deny = ['create', 'update'];
        $this->upload()->assertForbidden();
        $this->upload($this->productId())->assertForbidden();

        $this->policy->deny = ['create'];
        $this->upload()->assertForbidden();
        $this->upload($this->productId())->assertOk();
    }

    public function testARichTextFieldThatIsNotEditedGivesItsImageFieldNoUploads(): void
    {
        $this->upload()->assertInvalid('field', 'read_only');
    }

    private function upload(int|string|null $record = null): \Kinetis\Vector\Testing\VectorResponse
    {
        $response = $this->vector($this->adminHeaders())->resource('products')->upload('imageKey', (string) base64_decode(self::PNG, true), 'p.png', $record);
        $key = $response->status() === 200 ? ($response->json()['key'] ?? null) : null;

        if (is_string($key)) {
            $this->stored[] = $key;
        }

        return $response;
    }

    private function storage(): \League\Flysystem\FilesystemOperator
    {
        $storage = $this->app->get(VectorStorage::class);
        self::assertInstanceOf(VectorStorage::class, $storage);

        return $storage->filesystem();
    }

    private function productId(): string
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return (string) (new Query($link))->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->value('id');
    }
}

final class UploadPolicy extends ResourcePolicy
{
    /** @var list<string> */
    public array $deny = [];

    public ?string $refuseRecord = null;

    public function create(CurrentUserInterface $user): bool
    {
        return !in_array('create', $this->deny, true);
    }

    /** @param array<string, mixed> $record */
    public function update(CurrentUserInterface $user, array $record): bool
    {
        return !in_array('update', $this->deny, true) && (string) ($record['id'] ?? '') !== $this->refuseRecord;
    }
}
