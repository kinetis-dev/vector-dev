<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Console\CommandArguments;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Console\PruneFilesCommand;
use Kinetis\Vector\Files\FilePruner;
use Kinetis\Vector\Files\VectorStorage;

/**
 * `vector:files:prune` deletes an upload no record uses once it is older
 * than the grace period, and keeps everything else: a recent upload, a
 * key a file column holds — on a trashed row too —, an image a rich-text
 * document shows, a value the audit trail can revert to, a key outside
 * `vector/` and a folder no field declares. The test's own rows are put
 * back and its files removed.
 */
final class FilePruneTest extends VectorTestCase
{
    /** A cutoff far behind anything another test writes, so only a test's own backdated files are candidates. */
    private const int CUTOFF = 978307200; // 2001-01-01T00:00:00Z

    /** @var list<string> */
    private array $written = [];

    /** @var array<int, array<string, mixed>> product id => the columns a test changed */
    private array $products = [];

    /** @var list<string> */
    private array $directories = [];

    private ?int $auditId = null;

    protected function tearDown(): void
    {
        foreach ($this->directories as $path) {
            if ($this->storage()->directoryExists($path)) {
                $this->storage()->deleteDirectory($path);
            }
        }

        foreach ($this->products as $id => $row) {
            $this->shop()->table('products')->where('id', '=', $id)->update($row);
        }

        if ($this->auditId !== null) {
            $this->vectorQuery()->table('vector_audit')->where('id', '=', $this->auditId)->delete();
        }

        foreach ($this->written as $key) {
            if ($this->storage()->fileExists($key)) {
                $this->storage()->delete($key);
            }
        }

        parent::tearDown();
    }

    public function testAnUnusedUploadOlderThanTheGracePeriodIsDeletedAndEverythingStillUsedIsKept(): void
    {
        $unused = $this->stored('vector/products/imageKey/' . self::hex() . '.png', ageHours: 25);
        $recent = $this->stored('vector/products/imageKey/' . self::hex() . '.png', ageHours: 23);
        $onARow = $this->stored('vector/products/imageKey/' . self::hex() . '.png', ageHours: 48);
        $onATrashedRow = $this->stored('vector/products/imageKey/' . self::hex() . '.png', ageHours: 48);
        $inADocument = $this->stored('vector/products/imageKey/' . self::hex() . '.png', ageHours: 48);
        $inTheAudit = $this->stored('vector/products/imageKey/' . self::hex() . '.png', ageHours: 48);
        $outside = $this->stored('legacy/prune-test/' . self::hex() . '.png', ageHours: 48);
        $undeclared = $this->stored('vector/products/noSuchField/' . self::hex() . '.png', ageHours: 48);
        $notIssued = $this->stored('vector/products/imageKey/kept-by-hand.png', ageHours: 48);

        [$first, $second, $third] = $this->productIds();
        $this->change($first, ['image_key' => $onARow]);
        $this->change($second, ['image_key' => $onATrashedRow, 'deleted_at' => '2026-01-01 00:00:00']);
        // stored as json_encode writes it, slashes escaped
        $this->change($third, ['description' => json_encode(['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => $inADocument, 'alt' => null]]]], JSON_THROW_ON_ERROR)]);
        $this->auditId = (int) $this->vectorQuery()->table('vector_audit')->insertGetId([
            'resource' => 'products', 'record_id' => (string) $first, 'actor_id' => '1', 'impersonator_id' => null, 'event' => 'update',
            'changes' => json_encode(['imageKey' => ['from' => $inTheAudit, 'to' => $onARow]], JSON_THROW_ON_ERROR), 'created_at' => '2026-01-01 00:00:00',
        ]);

        [$code, $output] = $this->prune();

        self::assertSame(0, $code);
        self::assertFalse($this->storage()->fileExists($unused), 'unused and past the grace period');
        self::assertStringContainsString("Deleted {$unused}\n", $output);

        foreach ([$recent, $onARow, $onATrashedRow, $inADocument, $inTheAudit, $outside, $undeclared, $notIssued] as $kept) {
            self::assertTrue($this->storage()->fileExists($kept), "{$kept} is kept");
            self::assertStringNotContainsString($kept, $output);
        }

        // a shorter grace period reaches the recent one
        [, $output] = $this->prune('--grace-hours=1');
        self::assertFalse($this->storage()->fileExists($recent));
        self::assertStringContainsString("Deleted {$recent}\n", $output);
    }

    public function testADryRunListsWhatWouldGoAndDeletesNothing(): void
    {
        $unused = $this->stored('vector/products/imageKey/' . self::hex() . '.png', ageHours: 25);

        [$code, $output] = $this->prune('--dry-run');

        self::assertSame(0, $code);
        self::assertStringContainsString("Would delete {$unused}\n", $output);
        self::assertStringContainsString('nothing was deleted', $output);
        self::assertTrue($this->storage()->fileExists($unused));
    }

    public function testTheGracePeriodMustBeWholeHours(): void
    {
        $unused = $this->stored('vector/products/imageKey/' . self::hex() . '.png', ageHours: 25);

        foreach (['--grace-hours=0', '--grace-hours=1.5', '--grace-hours=', '--grace-hours'] as $option) {
            [$code, $output] = $this->prune($option);
            self::assertSame(1, $code, $option);
            self::assertStringContainsString('--grace-hours needs a whole number of hours', $output);
        }

        self::assertTrue($this->storage()->fileExists($unused));
    }

    /**
     * More than one batch of candidates in one folder: each batch is
     * checked against the file columns, and documents' images never enter
     * one, so a key used on either side of the boundary is kept and every
     * other one is deleted — or, on a dry run, only listed.
     */
    public function testAFolderOfMoreThanOneBatchIsPrunedWholeWithTheKeysInUseOnEitherSideKept(): void
    {
        $folder = 'vector/products/imageKey';
        $keys = [];

        for ($i = 0; $i < 510; $i++) {
            $keys[] = $this->storedAt("{$folder}/" . self::hex() . '.png', self::CUTOFF - 3600);
        }

        // the order the pruner reads the folder in decides the batches: the first 500 keys not in a document are one
        $listed = [];

        foreach ($this->storage()->listContents($folder, false) as $item) {
            $listed[] = $item->path();
        }

        $order = array_values(array_intersect($listed, $keys));
        self::assertCount(510, $order);
        [$onARowBefore, $inADocumentBefore, $onARowAfter, $inADocumentAfter] = [$order[10], $order[20], $order[505], $order[507]];

        [$first, $second, $third] = $this->productIds();
        $this->change($first, ['image_key' => $onARowBefore]);
        $this->change($second, ['image_key' => $onARowAfter]);
        $images = array_map(static fn (string $src): array => ['type' => 'image', 'attrs' => ['src' => $src, 'alt' => null]], [$inADocumentBefore, $inADocumentAfter]);
        $this->change($third, ['description' => json_encode(['type' => 'doc', 'content' => $images], JSON_THROW_ON_ERROR)]);

        $expected = array_values(array_diff($keys, [$onARowBefore, $inADocumentBefore, $onARowAfter, $inADocumentAfter]));
        sort($expected);
        self::assertCount(506, $expected);

        [$count, $told] = $this->pruneBefore(self::CUTOFF, dryRun: true);

        self::assertSame(506, $count);
        self::assertSame($expected, $told);

        foreach ($keys as $key) {
            self::assertTrue($this->storage()->fileExists($key), "{$key} is kept by a dry run");
        }

        [$count, $told] = $this->pruneBefore(self::CUTOFF, dryRun: false);

        self::assertSame(506, $count);
        self::assertSame($expected, $told);

        foreach ($keys as $key) {
            self::assertSame(!in_array($key, $expected, true), $this->storage()->fileExists($key), $key);
        }
    }

    /**
     * Only a file of the issued shape counts, and the cutoff itself is
     * old enough: a file modified exactly then goes, one modified a second
     * later stays, and a directory named like an upload is never touched.
     */
    public function testAFileModifiedAtTheCutoffGoesWhileALaterOneAndADirectoryStay(): void
    {
        $folder = 'vector/products/imageKey';
        $atTheCutoff = "{$folder}/" . self::hex() . '.png';
        $aSecondLater = "{$folder}/" . self::hex() . '.png';
        $directory = "{$folder}/" . self::hex() . '.png';
        $root = sys_get_temp_dir() . '/vector-storage-tests'; // VectorTestCase's FILESYSTEM_ROOT

        // the pruner's cutoff is its own clock less the grace period: a run that crossed a second is arranged and run again
        do {
            $this->storedAt($atTheCutoff, self::CUTOFF);
            $this->storedAt($aSecondLater, self::CUTOFF + 1);
            $this->storage()->createDirectory($directory);
            $this->directories[] = $directory;
            self::assertTrue(touch("{$root}/{$directory}", self::CUTOFF - 3600));

            $started = time();
            [$count, $told] = $this->pruneBefore(self::CUTOFF, dryRun: false, now: $started);
        } while (time() !== $started);

        self::assertSame(1, $count);
        self::assertSame([$atTheCutoff], $told);
        self::assertFalse($this->storage()->fileExists($atTheCutoff));
        self::assertTrue($this->storage()->fileExists($aSecondLater));
        self::assertTrue($this->storage()->directoryExists($directory));
    }

    /**
     * Prunes with the grace period that puts the cutoff at $cutoff.
     *
     * @return array{int, list<string>} what prune() returned and the keys it told of, sorted
     */
    private function pruneBefore(int $cutoff, bool $dryRun, ?int $now = null): array
    {
        $pruner = $this->app->createRequestScope()->get(FilePruner::class);
        self::assertInstanceOf(FilePruner::class, $pruner);
        /** @var list<string> $told */
        $told = [];

        $count = $pruner->prune(($now ?? time()) - $cutoff, $dryRun, static function (string $key) use (&$told): void {
            $told[] = $key;
        });
        sort($told);

        return [$count, $told];
    }

    /** @return array{int, string} the exit code and what the command wrote, refusals last */
    private function prune(string ...$argv): array
    {
        $scope = $this->app->createRequestScope();
        $pruner = $scope->get(FilePruner::class);
        $storage = $scope->get(VectorStorage::class);
        self::assertInstanceOf(FilePruner::class, $pruner);
        self::assertInstanceOf(VectorStorage::class, $storage);
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);

        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($errors);

        $code = (new PruneFilesCommand($pruner, $storage, $output, $errors))->prune(CommandArguments::parse(array_values($argv)));
        rewind($output);
        rewind($errors);

        return [$code, (string) stream_get_contents($output) . (string) stream_get_contents($errors)];
    }

    private function stored(string $key, int $ageHours): string
    {
        return $this->storedAt($key, time() - $ageHours * 3600);
    }

    private function storedAt(string $key, int $modified): string
    {
        $this->storage()->write($key, 'x');
        $this->written[] = $key;
        $root = sys_get_temp_dir() . '/vector-storage-tests'; // VectorTestCase's FILESYSTEM_ROOT
        self::assertTrue(touch("{$root}/{$key}", $modified));

        return $key;
    }

    /** @param array<string, mixed> $values */
    private function change(int $id, array $values): void
    {
        $this->products[$id] = $this->shop()->table('products')->select(...array_keys($values))->where('id', '=', $id)->first() ?? [];
        $this->shop()->table('products')->where('id', '=', $id)->update($values);
    }

    /** @return list<int> three live products */
    private function productIds(): array
    {
        $ids = array_map('intval', $this->shop()->table('products')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->limit(3)->pluck('id'));
        self::assertCount(3, $ids);

        return $ids;
    }

    private static function hex(): string
    {
        return bin2hex(random_bytes(16));
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
