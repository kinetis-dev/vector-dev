<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Testing\TestResponse;
use Kinetis\Vector\Files\VectorStorage;

/**
 * Uploads for file and image fields, on the demo's local storage: the
 * format is detected from the bytes, the key is generated and private,
 * a written value must be a key issued for that field, and the stored
 * file is served back to whoever may view the record.
 */
final class FileUploadTest extends VectorTestCase
{
    /** A 1×1 PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testTheSchemaAnnouncesUploadsAndEachFieldsRules(): void
    {
        $schema = $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($schema);
        self::assertTrue($schema['capabilities']['uploads']);
        $image = array_column($schema['resources']['products']['fields'], null, 'name')['imageKey'];
        self::assertSame('jpg', $image['props']['accept']['image/jpeg']);
        self::assertSame(5_000_000, $image['props']['maxBytes']);
    }

    public function testAnUploadedImageIsWrittenAndServedBackInline(): void
    {
        $id = $this->productId();
        $before = $this->shop()->table('products')->select('image_key')->where('id', '=', $id)->value('image_key');
        $uploaded = $this->upload('imageKey', (string) base64_decode(self::PNG, true), 'my photo.png')->assertOk()->json();
        self::assertIsArray($uploaded);
        self::assertMatchesRegularExpression('~^vector/products/imageKey/[0-9a-f]{32}\.png$~', $uploaded['key']);
        self::assertSame('image/png', $uploaded['type']);
        self::assertSame('my photo.png', $uploaded['name'], 'the client name comes back as data, never as the path');

        try {
            $this->client->patch("/vector/r/products/{$id}", body: ['imageKey' => $uploaded['key']], headers: $this->realAdminHeaders())
                ->assertOk()->assertJsonPath('data.imageKey', $uploaded['key']);

            $file = $this->client->get("/vector/r/products/{$id}/files/imageKey", headers: $this->realAdminHeaders())->assertOk();
            self::assertSame('image/png', $file->getHeaderLine('Content-Type'));
            self::assertSame('inline', $file->getHeaderLine('Content-Disposition'));
            self::assertSame('nosniff', $file->getHeaderLine('X-Content-Type-Options'));
            self::assertStringContainsString('sandbox', $file->getHeaderLine('Content-Security-Policy'));
            self::assertSame(base64_decode(self::PNG, true), $file->body());
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['image_key' => $before]);
            $this->storage()->delete($uploaded['key']);
        }
    }

    public function testAWrittenFileValueMustBeAKeyIssuedForThatField(): void
    {
        $id = $this->productId();
        $h = $this->realAdminHeaders();
        $stored = $this->shop()->table('products')->select('image_key')->where('id', '=', $id)->value('image_key');

        foreach ([
            '../../etc/passwd',
            'vector/products/imageKey/' . str_repeat('a', 32) . '.png',   // the right shape, never uploaded
            'vector/customers/imageKey/' . str_repeat('b', 32) . '.png',  // another resource's prefix
        ] as $forged) {
            $this->client->patch("/vector/r/products/{$id}", body: ['imageKey' => $forged], headers: $h)->assertValidationError('imageKey');
        }

        // the value already stored may be sent back unchanged
        $this->client->patch("/vector/r/products/{$id}", body: ['imageKey' => $stored], headers: $h)->assertOk();

        // and a key whose file was never stored is not found
        try {
            $this->shop()->table('products')->where('id', '=', $id)->update(['image_key' => 'vector/products/imageKey/' . str_repeat('c', 32) . '.png']);
            $this->client->get("/vector/r/products/{$id}/files/imageKey", headers: $h)->assertStatus(404);
        } finally {
            $this->shop()->table('products')->where('id', '=', $id)->update(['image_key' => $stored]);
        }
    }

    public function testTheFormatComesFromTheBytesAndTheSizeFromTheField(): void
    {
        $this->upload('imageKey', "not an image at all\n", 'fake.png')->assertValidationError('file');
        $this->upload('imageKey', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.svg')->assertValidationError('file');
        $this->upload('imageKey', substr((string) base64_decode(self::PNG, true), 0, 16) . str_repeat("\0", 5_000_001), 'huge.png')->assertValidationError('file');
        $this->upload('name', 'x', 'x.txt')->assertValidationError('field');
    }

    private function upload(string $field, string $contents, string $filename): TestResponse
    {
        $name = addcslashes($filename, '"\\');
        $body = "--vector\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$name}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$contents}\r\n--vector--\r\n";

        return $this->client->raw('POST', "/vector/r/products/fields/{$field}/upload", $body, ['Content-Type' => 'multipart/form-data; boundary=vector', ...$this->realAdminHeaders()]);
    }

    private function storage(): \League\Flysystem\FilesystemOperator
    {
        $storage = $this->app->get(VectorStorage::class);
        self::assertInstanceOf(VectorStorage::class, $storage);

        return $storage->filesystem();
    }

    private function productId(): int
    {
        return (int) $this->shop()->table('products')->select('id')->where('deleted_at', '=', null)->where('image_key', '!=', null)->orderBy('id', 'ASC')->value('id');
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
