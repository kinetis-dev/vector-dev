<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Product;
use App\Domain\Support\Ticket;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Testing\TestResponse;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\RichText;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Files\VectorStorage;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\RichText\Document;
use Kinetis\Vector\RichText\NodeSpec;
use Kinetis\Vector\VectorResource;

/**
 * Rich text stored as the editor's JSON document: validated against a
 * fixed schema on write, rendered through the same schema for an
 * application's pages, projected to plain text for exports. The demo's
 * product description is a document; the ticket body stores HTML.
 */
final class RichTextTest extends VectorTestCase
{
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    // --- the document ------------------------------------------------------

    public function testTheFixtureIsAValidDocumentOfTheDefaultFeatures(): void
    {
        self::assertSame([], (new Document())->validate(self::fixture(), Document::DEFAULT_FEATURES));
    }

    public function testOnlyTheFieldsFeaturesAreAllowed(): void
    {
        $violations = (new Document())->validate(self::fixture(), ['bold', 'italic', 'heading']);
        $paths = array_map(static fn ($v): string => implode('/', $v->path), $violations);

        self::assertContains('content/1/content/4/marks/0/type', $paths, 'underline is not a feature here');
        self::assertContains('content/2/type', $paths, 'no lists');
        self::assertContains('content/7/type', $paths, 'no tables');
        self::assertStringContainsString('"table", which this field does not allow', implode(' ', array_map(static fn ($v): string => $v->message, $violations)));
    }

    public function testTheRootMustBeADocumentOfValidJson(): void
    {
        $document = new Document();

        foreach (['not json', '[]', '{"type":"paragraph","content":[]}', '{"type":"doc"}'] as $json) {
            self::assertSame('invalid_document', $document->validate($json, Document::DEFAULT_FEATURES)[0]->code, $json);
        }
    }

    public function testAttributesAreTheSchemas(): void
    {
        $document = new Document();
        $paragraph = static fn (array $node): string => json_encode(['type' => 'doc', 'content' => [$node]], JSON_THROW_ON_ERROR);
        $problems = static fn (string $json): array => array_map(static fn ($v): string => implode('/', $v->path) . ' ' . $v->code, $document->validate($json, Document::DEFAULT_FEATURES));

        self::assertSame(['content/0/attrs/style not_allowed'], $problems($paragraph(['type' => 'paragraph', 'attrs' => ['style' => 'color:red']])));
        self::assertSame(['content/0/attrs/onclick not_allowed'], $problems($paragraph(['type' => 'paragraph', 'attrs' => ['onclick' => 'alert(1)']])));
        self::assertSame(['content/0/attrs/level invalid_attribute'], $problems($paragraph(['type' => 'heading', 'attrs' => ['level' => 9], 'content' => [['type' => 'text', 'text' => 'x']]])));
        self::assertSame(['content/0 invalid_node'], $problems($paragraph(['type' => 'paragraph', 'html' => '<b>x</b>'])));
        self::assertSame(['content/0/content/0/text invalid_node'], $problems($paragraph(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]])));
    }

    public function testAnAttributeValueEndsWhereItsPatternDoes(): void
    {
        $document = new Document();
        $doc = static fn (array $node): string => json_encode(['type' => 'doc', 'content' => [$node]], JSON_THROW_ON_ERROR);
        $rel = static fn (string $rel): string => $doc(['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'rel' => $rel]]]],
        ]]);
        $language = static fn (string $language): string => $doc(['type' => 'codeBlock', 'attrs' => ['language' => $language], 'content' => [['type' => 'text', 'text' => 'echo 1;']]]);
        $image = static fn (string $src, string $width): string => $doc(['type' => 'image', 'attrs' => ['src' => $src, 'width' => $width]]);
        $codes = static fn (string $json, array $features): array => array_map(static fn ($v): string => implode('/', $v->path) . ' ' . $v->code, $document->validate($json, array_values($features)));

        self::assertSame([], $codes($rel('noopener noreferrer'), ['link']));
        self::assertSame([], $codes($language('php'), ['codeBlock']));
        self::assertSame([], $codes($image('vector/posts/images/a.png', '120'), ['image']));

        self::assertSame(['content/0/content/0/marks/0/attrs/rel invalid_attribute'], $codes($rel("noopener\n"), ['link']));
        self::assertSame(['content/0/content/0/marks/0/attrs/rel invalid_attribute'], $codes($rel("noopener\n noreferrer"), ['link']));
        self::assertSame(['content/0/attrs/language invalid_attribute'], $codes($language("php\n"), ['codeBlock']));
        self::assertSame(['content/0/attrs/src invalid_attribute'], $codes($image("vector/posts/images/a.png\n", '120'), ['image']));
        self::assertSame(['content/0/attrs/width invalid_attribute'], $codes($image('vector/posts/images/a.png', "120\n"), ['image']));
    }

    public function testLinksGoOnlyToHttpMailtoOrRelativePlaces(): void
    {
        $document = new Document();
        $link = static fn (string $href): string => json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href, 'target' => '_blank']]]],
        ]]]], JSON_THROW_ON_ERROR);

        foreach (['https://example.com', 'http://example.com/a?b', 'mailto:a@example.com', '/relative', '#anchor', '?q=1', 'page.html'] as $href) {
            self::assertSame([], $document->validate($link($href), ['link']), $href);
        }

        foreach (['javascript:alert(1)', ' JavaScript:alert(1)', "java\tscript:alert(1)", 'data:text/html,x', 'vbscript:x', 'ftp://example.com'] as $href) {
            self::assertSame('invalid_attribute', $document->validate($link($href), ['link'])[0]->code ?? null, $href);
        }
    }

    public function testSizeAndDepthAreBounded(): void
    {
        $document = new Document();
        $big = json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('x', 300_000)]]]]], JSON_THROW_ON_ERROR);
        self::assertSame('too_large', $document->validate($big, Document::DEFAULT_FEATURES)[0]->code);
        self::assertSame([], $document->validate($big, Document::DEFAULT_FEATURES, 400_000));

        $deep = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]];
        for ($i = 0; $i < 40; $i++) {
            $deep = ['type' => 'blockquote', 'content' => [$deep]];
        }
        self::assertContains('too_deep', array_map(static fn ($v): string => $v->code, $document->validate(json_encode(['type' => 'doc', 'content' => [$deep]], JSON_THROW_ON_ERROR), Document::DEFAULT_FEATURES)));

        $many = json_encode(['type' => 'doc', 'content' => array_fill(0, 10_001, ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]])], JSON_THROW_ON_ERROR);
        self::assertSame('too_large', $document->validate($many, Document::DEFAULT_FEATURES, 1_000_000)[0]->code);
    }

    public function testAnApplicationNodeIsAcceptedOnlyOnceRegistered(): void
    {
        $json = json_encode(['type' => 'doc', 'content' => [['type' => 'callout', 'attrs' => ['tone' => 'warning'], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Careful']]]]]]], JSON_THROW_ON_ERROR);

        self::assertNotSame([], (new Document())->validate($json, ['callout']));
        self::assertSame([], (new Document())->extend(new NodeSpec('callout', ['tone']))->validate($json, ['callout']));
        self::assertTrue((new Document())->extend(new NodeSpec('callout'))->knows('callout'));
    }

    public function testItRendersEscapedHtmlThroughTheSchema(): void
    {
        self::assertSame(
            '<h2>Care &amp; use</h2><p><strong>Bold</strong>, <em>italic</em>, <u>underlined</u>, <s>struck</s> and <code>code</code><br>See the <a target="_blank" rel="noopener noreferrer nofollow" href="https://example.com/guide">guide</a> or &lt;script&gt;alert(1)&lt;/script&gt;.</p>'
            . '<ul><li><p>One</p></li><li><p>Two</p></li></ul><ol start="1"><li><p>First</p></li></ol><blockquote><p>Quoted</p></blockquote><pre><code class="language-php">echo 1;</code></pre><hr>'
            . '<table><tbody><tr><th rowspan="1" colspan="1"><p>Size</p></th><th rowspan="1" colspan="1"><p>Weight</p></th></tr><tr><td rowspan="1" colspan="1"><p>M</p></td><td rowspan="1" colspan="1"><p>2 kg</p></td></tr></tbody></table>',
            (new Document())->toHtml(self::fixture()),
        );

        // rendered with fewer features, what they do not cover is left out
        $html = (new Document())->toHtml(self::fixture(), ['bold']);
        self::assertStringNotContainsString('<table', $html);
        self::assertStringNotContainsString('<em>', $html);
        self::assertStringContainsString('<strong>Bold</strong>', $html);
    }

    public function testImagesAreMappedToUrlsTheApplicationServesOrLeftOut(): void
    {
        $json = json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Look']]], ['type' => 'image', 'attrs' => ['src' => 'vector/products/imageKey/a.png', 'alt' => 'A']]]], JSON_THROW_ON_ERROR);
        $document = new Document();

        self::assertStringContainsString('<img src="/media/a.png" alt="A">', $document->toHtml($json, ['image'], static fn (string $key): string => '/media/' . basename($key)));
        self::assertStringNotContainsString('<img', $document->toHtml($json, ['image'], static fn (string $key): ?string => null));
        self::assertSame(['vector/products/imageKey/a.png'], $document->imageKeys($json));
    }

    public function testThePlainTextProjection(): void
    {
        self::assertSame(
            "Care & use\nBold, italic, underlined, struck and code\nSee the guide or <script>alert(1)</script>.\nOne\nTwo\nFirst\nQuoted\necho 1;\n\nSize | Weight\nM | 2 kg",
            (new Document())->toPlainText(self::fixture()),
        );
        self::assertSame("Hello\nworld", (new Document())->toPlainText('<p>Hello</p><p><strong>world</strong></p>'), 'stored HTML reads through the schema');
    }

    // --- the write path ----------------------------------------------------

    public function testAProductDescriptionIsWrittenAsADocument(): void
    {
        $id = $this->productId();
        $before = $this->description($id);

        try {
            $this->client->patch("/vector/r/products/{$id}", body: ['description' => self::fixture()], headers: $this->adminHeaders())
                ->assertOk();
            self::assertSame(json_decode(self::fixture(), true), json_decode((string) $this->description($id), true));
        } finally {
            $this->restore($id, $before);
        }
    }

    public function testAnOutOfSchemaDocumentIsRefusedOnTheField(): void
    {
        $id = $this->productId();
        $bad = static fn (array $node): string => json_encode(['type' => 'doc', 'content' => [$node]], JSON_THROW_ON_ERROR);
        $patch = fn (string $value): TestResponse => $this->client->patch("/vector/r/products/{$id}", body: ['description' => $value], headers: $this->adminHeaders());

        $patch($bad(['type' => 'iframe', 'attrs' => ['src' => 'https://evil.example']]))->assertStatus(422)->assertJsonPath('errors.0.path', ['description', 'content', 0, 'type']);
        $patch($bad(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]]))
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'invalid_attribute');
        $patch($bad(['type' => 'paragraph', 'attrs' => ['style' => 'color:red'], 'content' => [['type' => 'text', 'text' => 'x']]]))->assertStatus(422);
        $patch('<p onclick="alert(1)">html</p>')->assertStatus(422)->assertJsonPath('errors.0.code', 'invalid_document');
        $patch($bad(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('x', 300 * 1024)]]]))->assertStatus(422)->assertJsonPath('errors.0.code', 'too_large');
    }

    public function testAScriptTypedAsTextIsJustText(): void
    {
        $id = $this->productId();
        $before = $this->description($id);
        $json = json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '<script>alert(1)</script>']]]]], JSON_THROW_ON_ERROR);

        try {
            $this->client->patch("/vector/r/products/{$id}", body: ['description' => $json], headers: $this->adminHeaders())->assertOk();

            $page = $this->client->get("/shop/products/{$id}")->assertOk()->body();
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
            self::assertStringNotContainsString('<script>alert(1)', $page);
        } finally {
            $this->restore($id, $before);
        }
    }

    public function testAnEmptyDocumentIsNoValue(): void
    {
        $id = $this->productId();
        $before = $this->description($id);

        try {
            $this->client->patch("/vector/r/products/{$id}", body: ['description' => '{"type":"doc","content":[{"type":"paragraph"}]}'], headers: $this->adminHeaders())
                ->assertOk()->assertJsonPath('data.description', null);
        } finally {
            $this->restore($id, $before);
        }
    }

    public function testAnImageMustBeAnUploadOfTheImageFieldAndIsServedToTheRecordsViewers(): void
    {
        $id = $this->productId();
        $before = $this->description($id);
        $image = static fn (string $key): string => json_encode(['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => $key, 'alt' => 'Photo']]]], JSON_THROW_ON_ERROR);

        $this->client->patch("/vector/r/products/{$id}", body: ['description' => $image('vector/products/imageKey/' . str_repeat('a', 32) . '.png')], headers: $this->adminHeaders())
            ->assertStatus(422)->assertJsonPath('errors.0.path', ['description', 'content', 0, 'attrs', 'src']);
        $this->client->patch("/vector/r/products/{$id}", body: ['description' => $image('https://evil.example/a.png')], headers: $this->adminHeaders())->assertStatus(422);

        $key = $this->upload()->assertOk()->json()['key'];
        self::assertIsString($key);

        try {
            $this->client->patch("/vector/r/products/{$id}", body: ['description' => $image($key)], headers: $this->adminHeaders())->assertOk();

            $file = $this->client->get("/vector/r/products/{$id}/files/imageKey", query: ['key' => $key], headers: $this->adminHeaders())->assertOk();
            self::assertSame(base64_decode(self::PNG, true), $file->body());

            // a key the record's documents do not show is not served through them
            $other = $this->upload()->assertOk()->json()['key'];
            self::assertIsString($other);
            $this->client->get("/vector/r/products/{$id}/files/imageKey", query: ['key' => $other], headers: $this->adminHeaders())->assertNotFound();
            $this->storage()->delete($other);

            // saved again unchanged, the stored image stays valid
            $this->client->patch("/vector/r/products/{$id}", body: ['description' => $image($key), 'name' => 'Renamed for the test'], headers: $this->adminHeaders())->assertOk();
        } finally {
            $this->restore($id, $before);
            $this->storage()->delete($key);
        }
    }

    public function testAFieldThatStoresHtmlRendersTheDocumentThroughTheSchema(): void
    {
        $ticket = $this->client->get('/vector/r/tickets', query: ['perPage' => 1], headers: $this->adminHeaders())->assertOk()->json()['data'][0];
        $id = $ticket['id'];
        $shown = $this->client->get("/vector/r/tickets/{$id}", headers: $this->adminHeaders())->assertOk()->json()['data']['body'];
        $json = json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Please '],
            ['type' => 'text', 'text' => 'restart', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' <img src=x onerror=alert(1)>'],
        ]]]], JSON_THROW_ON_ERROR);

        try {
            $body = $this->client->patch("/vector/r/tickets/{$id}", body: ['body' => $json], headers: $this->adminHeaders())->assertOk()->json()['data']['body'];

            self::assertSame('<p>Please <strong>restart</strong> &lt;img src=x onerror=alert(1)&gt;</p>', $body);

            // a feature this field does not allow is refused, even though it stores HTML
            $this->client->patch("/vector/r/tickets/{$id}", body: ['body' => json_encode(['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'x']]]]], JSON_THROW_ON_ERROR)], headers: $this->adminHeaders())
                ->assertStatus(422);
        } finally {
            $this->client->patch("/vector/r/tickets/{$id}", body: ['body' => json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => strip_tags((string) $shown)]]]]], JSON_THROW_ON_ERROR)], headers: $this->adminHeaders());
        }
    }

    public function testTheExportCarriesThePlainText(): void
    {
        $csv = $this->client->get('/vector/r/products/export', query: ['columns' => 'sku,description', 'filter' => ['sku' => ['eq' => 'SKU-00042']]], headers: $this->adminHeaders())->assertOk()->body();
        $lines = explode("\r\n", trim($csv));

        self::assertCount(2, $lines);
        self::assertStringContainsString('Silent Monitors 42 — a demo product in the Monitors category.', $lines[1]);
        self::assertStringNotContainsString('"type"', $lines[1], 'the cell holds text, not the JSON');
    }

    public function testTheDescriptorPublishesTheSchemaButNotTheTextProperty(): void
    {
        $fields = array_column($this->client->get('/vector/schema/products', headers: $this->adminHeaders())->assertOk()->json()['fields'], null, 'name');

        self::assertSame('richtext', $fields['description']['type']);
        self::assertSame(['features' => [...Document::DEFAULT_FEATURES, 'image'], 'imageField' => 'imageKey', 'mentions' => null, 'storesHtml' => false, 'maxBytes' => 262_144], $fields['description']['richtext']);
        self::assertSame([], $fields['description']['operators'], 'not filterable here; a filterable one would take null and nnull only');

        $replies = array_column($this->client->get('/vector/schema/ticket-replies', headers: $this->adminHeaders())->assertOk()->json()['fields'], null, 'name');
        self::assertSame('markdown', $replies['body']['type']);
        self::assertContains('codeBlock', $replies['body']['markdown']['toolbar']);
    }

    // --- the build ---------------------------------------------------------

    public function testTheBuildRefusesWhatARichTextFieldCannotBe(): void
    {
        $cases = [
            'stores its document in a string property' => new #[Resource(Product::class, slug: 'rich-products')] class extends VectorResource {
                public function fields(): array { return [Id::make(), RichText::make('stock')]; }
            },
            'cannot be sorted or summarized' => new #[Resource(Product::class, slug: 'rich-products')] class extends VectorResource {
                public function fields(): array { return [Id::make(), RichText::make('description')->sortable()]; }
            },
            'allows images, which need imageField()' => new #[Resource(Product::class, slug: 'rich-products')] class extends VectorResource {
                public function fields(): array { return [Id::make(), RichText::make('description')->features(['bold', 'image'])]; }
            },
            'allows mentions, which need mentions()' => new #[Resource(Product::class, slug: 'rich-products')] class extends VectorResource {
                public function fields(): array { return [Id::make(), Text::make('name'), RichText::make('description')->mentions('name')]; }
            },
            'searching it needs textField()' => new #[Resource(Product::class, slug: 'rich-products')] class extends VectorResource {
                public function fields(): array { return [Id::make(), RichText::make('description')->searchable()]; }
            },
            'which is not a string property of the entity' => new #[Resource(Product::class, slug: 'rich-products')] class extends VectorResource {
                public function fields(): array { return [Id::make(), Number::make('stock'), RichText::make('description')->textField('stock')]; }
            },
        ];

        foreach ($cases as $message => $resource) {
            try {
                self::compile($resource);
                self::fail("compiled: {$message}");
            } catch (ResourceCompileException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testASearchableFieldSearchesItsTextProperty(): void
    {
        $compiled = self::compile(new #[Resource(Ticket::class, slug: 'rich-tickets')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), RichText::make('body')->textField('subject')->searchable()];
            }
        });

        self::assertSame(['body' => 'subject'], $compiled['searchColumns']);
        self::assertSame('subject', $compiled['fields'][1]['richtext']['textField']);
    }

    /** @return array<string, mixed> */
    private static function compile(VectorResource $resource): array
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        $compiled = (new ResourceCompiler($metadata, [$resource::class]))->compile();

        return array_values($compiled)[0];
    }

    private static function fixture(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/Fixtures/richtext-document.json');
    }

    private function productId(): int
    {
        return (int) $this->shop()->table('products')->select('id')->where('sku', '=', 'SKU-00042')->value('id');
    }

    private function description(int $id): ?string
    {
        $value = $this->shop()->table('products')->select('description')->where('id', '=', $id)->value('description');

        return is_string($value) ? $value : null;
    }

    private function restore(int $id, ?string $description): void
    {
        $this->shop()->table('products')->where('id', '=', $id)->update(['description' => $description, 'name' => 'Silent Monitors 42']);
    }

    private function upload(): TestResponse
    {
        $body = "--vector\r\nContent-Disposition: form-data; name=\"file\"; filename=\"photo.png\"\r\nContent-Type: image/png\r\n\r\n" . base64_decode(self::PNG, true) . "\r\n--vector--\r\n";

        return $this->client->raw('POST', '/vector/r/products/fields/imageKey/upload', $body, ['Content-Type' => 'multipart/form-data; boundary=vector', ...$this->realAdminHeaders()]);
    }

    private function storage(): \League\Flysystem\FilesystemOperator
    {
        $storage = $this->app->get(VectorStorage::class);
        self::assertInstanceOf(VectorStorage::class, $storage);

        return $storage->filesystem();
    }

    private function shop(): Query
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return new Query($link);
    }
}
