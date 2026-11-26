<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Vector\ProductResource;
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\BooleanGroup;
use Kinetis\Vector\Fields\Computed;
use Kinetis\Vector\Fields\Country;
use Kinetis\Vector\Fields\Date;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Field;
use Kinetis\Vector\Fields\File;
use Kinetis\Vector\Fields\HasMany;
use Kinetis\Vector\Fields\Image;
use Kinetis\Vector\Fields\Json;
use Kinetis\Vector\Fields\KeyValue;
use Kinetis\Vector\Fields\Markdown;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\Repeater;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Testing\InteractsWithVector;

/**
 * placeholder(): the hint inside an empty text box, published as the
 * field's `placeholder` and translated like its label; refused, when the
 * field is defined, by every field whose input has no text box.
 */
final class PlaceholderTest extends VectorTestCase
{
    use InteractsWithVector;

    public function testAPlaceholderIsPublishedAndTranslated(): void
    {
        $english = array_column($this->client->get('/vector/schema', headers: $this->headersIn('en'))->assertOk()->json()['resources']['products']['fields'], null, 'name');
        self::assertSame('A unique stock code, such as KB-0042', $english['sku']['placeholder']);
        self::assertArrayNotHasKey('placeholder', $english['name'], 'a field without one publishes no key');

        $german = array_column($this->client->get('/vector/schema', headers: $this->headersIn('de'))->assertOk()->json()['resources']['products']['fields'], null, 'name');
        self::assertSame('Ein eindeutiger Lagercode, etwa KB-0042', $german['sku']['placeholder']);

        // the compiled registry keeps the declared text
        self::assertSame('A unique stock code, such as KB-0042', $this->registry()->resource('products')->field('sku')['placeholder'] ?? null);
    }

    public function testEveryInputWithATextBoxTakesOne(): void
    {
        foreach ([Text::make('a'), Number::make('a'), Json::make('a'), Markdown::make('a'), Country::make('a')] as $field) {
            self::assertSame('Type here', $field->placeholder('Type here')->toArray()['placeholder'], $field->type());
        }
    }

    public function testAFieldWithoutATextBoxRefusesOne(): void
    {
        /** @var list<Field> $fields */
        $fields = [
            Boolean::make('a'),
            BooleanGroup::make('a'),
            File::make('a'),
            Image::make('a'),
            Repeater::make('a'),
            Computed::make('a'),
            Date::make('a'),
            DateTime::make('a'),
            KeyValue::make('a'),
            HasMany::to('a', ProductResource::class),
        ];

        foreach ($fields as $field) {
            try {
                $field->placeholder('Type here');
                self::fail("{$field->type()} took a placeholder");
            } catch (\LogicException $e) {
                self::assertSame("\"a\": a {$field->type()} field's input has no text box to show a placeholder in.", $e->getMessage());
            }
        }
    }

    public function testTheMcpDescriptionCarriesIt(): void
    {
        $described = $this->registry()->resource('products');
        $sku = array_column(\Kinetis\Vector\Mcp\ResourceDescription::of($described, [])['fields'], null, 'name')['sku'];

        self::assertSame('A unique stock code, such as KB-0042', $sku['placeholder']);
    }

    private function registry(): \Kinetis\Vector\Registry\VectorRegistry
    {
        $registry = $this->app->get(\Kinetis\Vector\Registry\VectorRegistry::class);
        self::assertInstanceOf(\Kinetis\Vector\Registry\VectorRegistry::class, $registry);

        return $registry;
    }
}
