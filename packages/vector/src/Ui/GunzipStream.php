<?php

declare(strict_types=1);

namespace Kinetis\Vector\Ui;

/**
 * A gzip file, decompressed through ext-zlib as it is read, never past
 * $maxBytes of output: TarReader's source, so a small archive that
 * inflates to gigabytes is refused at the cap rather than written out.
 * One instance reads one file once, inside one console command.
 */
final class GunzipStream
{
    private const int CHUNK = 8192;

    /** @var resource */
    private $file;

    private \InflateContext $inflate;

    private string $buffer = '';

    private int $offset = 0;

    private int $produced = 0;

    private bool $ended = false;

    /**
     * @throws \RuntimeException when the file cannot be opened
     */
    public function __construct(string $path, private readonly int $maxBytes)
    {
        $file = @fopen($path, 'rb');
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);

        if ($file === false || $inflate === false) {
            throw new \RuntimeException("Could not open {$path}.");
        }

        $this->file = $file;
        $this->inflate = $inflate;
    }

    /**
     * Up to $length decompressed bytes: fewer only at the end of the data.
     *
     * @throws \UnexpectedValueException when the data is not gzip, or inflates past the cap
     */
    public function read(int $length): string
    {
        while (strlen($this->buffer) - $this->offset < $length && !$this->ended) {
            $chunk = fread($this->file, self::CHUNK);
            $last = $chunk === false || $chunk === '' || feof($this->file);
            $out = @inflate_add($this->inflate, $chunk === false ? '' : $chunk, $last ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);

            if ($out === false) {
                throw new \UnexpectedValueException('The archive is not valid gzip.');
            }

            $this->produced += strlen($out);

            if ($this->produced > $this->maxBytes) {
                throw new \UnexpectedValueException("The archive decompresses to more than {$this->maxBytes} bytes.");
            }

            // what was read is dropped before more is appended, so the buffer stays one chunk's output
            $this->buffer = substr($this->buffer, $this->offset) . $out;
            $this->offset = 0;
            $this->ended = $last || inflate_get_status($this->inflate) === ZLIB_STREAM_END;
        }

        $bytes = substr($this->buffer, $this->offset, $length);
        $this->offset += strlen($bytes);

        return $bytes;
    }

    public function close(): void
    {
        fclose($this->file);
    }
}
