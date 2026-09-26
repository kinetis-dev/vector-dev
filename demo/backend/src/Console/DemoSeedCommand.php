<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Category;
use App\Domain\Customer;
use App\Domain\CustomerProfile;
use App\Domain\Order;
use App\Domain\Product;
use App\Domain\Tag;
use App\Domain\User;
use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Orm\Date;
use Kinetis\Orm\EntityManager;
use Kinetis\Vector\Files\VectorStorage;

/**
 * Fills the demo domain with a deterministic data set. `--fresh` empties
 * the tables first; `--scale=N` multiplies the row counts.
 *
 * Product images are real files in Vector's storage, under the key shape
 * an upload gets, so they show and download like uploaded ones. Run it as
 * the user the application runs as (`--user www-data`), or the files it
 * stores are not the application's to read.
 */
final readonly class DemoSeedCommand
{
    private const array CATEGORIES = [
        'Audio' => ['Headphones', 'Speakers', 'Microphones'],
        'Computing' => ['Laptops', 'Monitors', 'Keyboards', 'Mice'],
        'Home' => ['Lighting', 'Kitchen', 'Garden'],
    ];

    private const array TAGS = [
        'new' => '#2E7D32', 'sale' => '#C62828', 'bestseller' => '#EF6C00', 'eco' => '#558B2F',
        'wireless' => '#1565C0', 'premium' => '#6A1B9A', 'refurbished' => '#455A64', 'limited' => '#AD1457',
        'bundle' => '#00838F', 'gift' => '#F9A825', 'clearance' => '#4E342E', 'pro' => '#283593',
    ];

    private const array ADJECTIVES = ['Compact', 'Wireless', 'Studio', 'Ultra', 'Classic', 'Smart', 'Portable', 'Silent', 'Rapid', 'Precision'];

    private const array FIRST_NAMES = ['Ada', 'Grace', 'Linus', 'Margaret', 'Dennis', 'Barbara', 'Ken', 'Radia', 'Tim', 'Frances', 'Alan', 'Hedy'];

    private const array LAST_NAMES = ['Lovelace', 'Hopper', 'Torvalds', 'Hamilton', 'Ritchie', 'Liskov', 'Thompson', 'Perlman', 'Berners-Lee', 'Allen', 'Turing', 'Lamarr'];

    /** A colour per top-level category, for the product images. */
    private const array PALETTE = ['Audio' => [21, 101, 192], 'Computing' => [46, 125, 50], 'Home' => [180, 83, 9]];

    private const array CITIES = [['Amsterdam', 'NL'], ['Berlin', 'DE'], ['Lisbon', 'PT'], ['Tel Aviv', 'IL'], ['Vienna', 'AT'], ['Dublin', 'IE'], ['Prague', 'CZ'], ['Oslo', 'NO']];

    public function __construct(
        private EntityManager $entities,
        private SupportSeeder $support,
        private VectorStorage $storage,
    ) {}

    #[Command('demo:seed', description: 'Fills the demo domain with sample data (--fresh, --scale=N)')]
    public function seed(CommandArguments $arguments): int
    {
        $scale = max(1, (int) ($arguments->option('scale') ?? '1'));

        if ($arguments->hasOption('fresh')) {
            $this->truncate();
            $this->support->truncate();

            if ($this->storage->available()) {
                $this->storage->filesystem()->deleteDirectory('vector/products');
            }
        } elseif ($this->entities->repository(User::class)->query()->exists()) {
            if ($this->support->isEmpty()) {
                // the shop is seeded and the support desk (PostgreSQL) is new: seed only that
                mt_srand(20260925);

                return $this->seedSupport($scale);
            }

            fwrite(STDERR, "The demo tables already hold data. Run with --fresh to replace it.\n");

            return 1;
        }

        mt_srand(20260924);
        $utc = new DateTimeZone('UTC');

        $this->entities->persist(new User('admin@vector.local', 'Vector Admin', 'password', true));
        $this->entities->persist(new User('editor@vector.local', 'Demo Editor', 'password', false));

        $categories = [];
        $position = 0;

        $colourOf = [];

        foreach (self::CATEGORIES as $parentName => $children) {
            $parent = new Category($parentName, $this->slug($parentName), ++$position);
            $this->entities->persist($parent);

            foreach ($children as $childName) {
                $child = new Category($childName, $this->slug($childName), ++$position, $parent, "Everything about {$childName}.");
                $this->entities->persist($child);
                $categories[] = $child;
                $colourOf[$childName] = self::PALETTE[$parentName];
            }
        }

        $tags = [];

        foreach (self::TAGS as $name => $color) {
            $tag = new Tag($name, $color);
            $this->entities->persist($tag);
            $tags[] = $tag;
        }

        $this->entities->flush();

        $products = [];
        $productCount = 150 * $scale;

        for ($i = 1; $i <= $productCount; $i++) {
            $category = $categories[array_rand($categories)];
            $name = self::ADJECTIVES[array_rand(self::ADJECTIVES)] . ' ' . $category->name() . ' ' . $i;
            $product = new Product(
                sku: sprintf('SKU-%05d', $i),
                name: $name,
                slug: $this->slug($name),
                category: $category,
                priceCents: mt_rand(5, 900) * 100 + 99,
                currency: 'EUR',
                stock: mt_rand(0, 250),
                weightKg: mt_rand(50, 12000) / 1000,
            );
            $product->describe(
                "**{$name}** — a demo product in the {$category->name()} category.\n\nLorem ipsum dolor sit amet.",
                // a key-value field: text values
                json_encode(['color' => ['black', 'silver', 'white'][mt_rand(0, 2)], 'warrantyMonths' => ['12', '24', '36'][mt_rand(0, 2)]], JSON_THROW_ON_ERROR),
                mt_rand(0, 3) === 0 ? null : $this->image($i, $colourOf[$category->name()] ?? [96, 96, 96]),
            );

            $roll = mt_rand(1, 10);

            if ($roll <= 7) {
                $product->activate(new DateTimeImmutable('-' . mt_rand(1, 700) . ' days', $utc), mt_rand(1, 8) === 1);
            } elseif ($roll === 8) {
                $product->archive();
            }

            $tagCount = mt_rand(0, 3);
            $chosen = [];

            for ($t = 0; $t < $tagCount; $t++) {
                $tag = $tags[array_rand($tags)];
                $chosen[spl_object_id($tag)] = $tag; // a join collection is a set: one object at most once
            }

            $product->tag(array_values($chosen));
            $this->entities->persist($product);
            $products[] = $product;
        }

        $this->entities->flush();

        $customers = [];
        $customerCount = 80 * $scale;

        for ($i = 1; $i <= $customerCount; $i++) {
            $first = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
            $last = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
            $customer = new Customer(strtolower("{$first}.{$last}.{$i}@example.com"), "{$first} {$last}");
            $customer->contact(
                mt_rand(0, 2) === 0 ? null : sprintf('+31 6 %08d', mt_rand(0, 99999999)),
                mt_rand(0, 3) === 0 ? null : 'https://' . strtolower($last) . '.example',
                Date::fromString((new DateTimeImmutable('-' . mt_rand(20, 70) . ' years', $utc))->format('Y-m-d')),
                mt_rand(0, 4) === 0 ? 'Prefers e-mail contact.' : null,
            );

            if (mt_rand(0, 3) !== 0) {
                [$city, $country] = self::CITIES[array_rand(self::CITIES)];
                $customer->profile(new CustomerProfile($customer, mt_rand(1, 200) . ' Main Street', $city, (string) mt_rand(1000, 9999), $country, mt_rand(0, 1) === 1));
            }

            $this->entities->persist($customer);
            $customers[] = $customer;
        }

        $this->entities->flush();

        $orderCount = 400 * $scale;

        for ($i = 1; $i <= $orderCount; $i++) {
            $placedAt = new DateTimeImmutable('-' . mt_rand(0, 365) . ' days -' . mt_rand(0, 86399) . ' seconds', $utc);
            $order = new Order(sprintf('ORD-%06d', $i), $customers[array_rand($customers)], 'EUR', $placedAt);
            $lineCount = mt_rand(1, 5);

            for ($l = 0; $l < $lineCount; $l++) {
                $order->add($products[array_rand($products)], mt_rand(1, 4));
            }

            $roll = mt_rand(1, 10);

            if ($roll <= 5) {
                $order->pay();
                $order->ship($placedAt->modify('+' . mt_rand(1, 5) . ' days'));
            } elseif ($roll <= 8) {
                $order->pay();
            } elseif ($roll === 9) {
                $order->cancel('Customer changed their mind.');
            }

            $this->entities->persist($order);

            if ($i % 100 === 0) {
                $this->entities->flush();
            }
        }

        $this->entities->flush();

        echo "Seeded 2 users, " . count($categories) . " leaf categories, " . count($tags) . " tags, {$productCount} products, {$customerCount} customers and {$orderCount} orders.\n";
        $this->seedSupport($scale);
        echo "Log in with admin@vector.local / password.\n";

        return 0;
    }

    private function seedSupport(int $scale): int
    {
        $customers = array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'email' => (string) $row['email']],
            $this->entities->builder()->table('customers')->select('id', 'email')->orderBy('id', 'ASC')->get(),
        );
        $seeded = $this->support->seed($scale, $customers);
        echo "Seeded the support desk on PostgreSQL: {$seeded['teams']} teams, {$seeded['agents']} agents, {$seeded['labels']} labels and {$seeded['tickets']} tickets.\n";

        return 0;
    }

    private function truncate(): void
    {
        $builder = fn () => $this->entities->builder();

        $builder()->table('order_lines')->where('id', '>', 0)->delete();
        $builder()->table('orders')->where('id', '>', 0)->delete();
        $builder()->table('product_tag')->where('product_id', '>', 0)->delete();
        $builder()->table('products')->where('id', '>', 0)->delete();
        $builder()->table('customer_profiles')->where('id', '>', 0)->delete();
        $builder()->table('customers')->where('id', '>', 0)->delete();
        $builder()->table('tags')->where('id', '>', 0)->delete();
        $builder()->table('categories')->where('parent_id', '!=', null)->delete();
        $builder()->table('categories')->where('id', '>', 0)->delete();
        $builder()->table('users')->where('id', '>', 0)->delete();
    }

    /**
     * Stores a product image — a 96-pixel gradient in its category's
     * colour — under the key an upload of it would get, or none without
     * storage.
     *
     * @param array{int, int, int} $rgb
     */
    private function image(int $number, array $rgb): ?string
    {
        if (!$this->storage->available()) {
            return null;
        }

        $key = 'vector/products/imageKey/' . md5("demo-product-{$number}") . '.png';
        $this->storage->filesystem()->write($key, self::png(96, $rgb, $number));

        return $key;
    }

    /**
     * A truecolour PNG, top to bottom from the colour to a light tint of
     * it, the tint shifted per product so no two look alike.
     *
     * @param array{int, int, int} $rgb
     */
    private static function png(int $size, array $rgb, int $seed): string
    {
        $raw = '';
        $shift = ($seed % 7) * 0.05;

        for ($y = 0; $y < $size; $y++) {
            $mix = min(1.0, $y / $size * 0.8 + $shift);
            $pixel = pack('C3', ...array_map(static fn (int $c): int => (int) round($c + (255 - $c) * $mix), $rgb));
            $raw .= "\0" . str_repeat($pixel, $size);
        }

        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($raw))
            . $chunk('IEND', '');
    }

    private function slug(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    }
}
