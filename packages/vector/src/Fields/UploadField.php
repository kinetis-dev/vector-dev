<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

/**
 * A field whose value is the storage key of an uploaded file. The upload
 * endpoint accepts a file only when its content — detected from its
 * bytes, never from the name or type the client sent — is one of the
 * formats accept() lists, and within maxBytes(); it stores the file
 * privately under a key it generates. A written value must be such a
 * key, issued for this field, or the value already stored.
 */
abstract class UploadField extends Field
{
    /**
     * Accepted formats: detected media type => extension of the stored key.
     *
     * @param array<string, string> $formats
     */
    public function accept(array $formats): static
    {
        if ($formats === []) {
            throw new \InvalidArgumentException("\"{$this->name}\": accept() needs at least one format.");
        }

        foreach ($formats as $type => $extension) {
            if (preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~', $type) !== 1 || preg_match('/^[a-z0-9]{1,10}$/', $extension) !== 1) {
                throw new \InvalidArgumentException("\"{$this->name}\": accept() maps a media type such as image/png to an extension such as png.");
            }
        }

        $this->props['accept'] = $formats;

        return $this;
    }

    /** The largest file accepted, in bytes. */
    public function maxBytes(int $bytes): static
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException("\"{$this->name}\": maxBytes() must be positive.");
        }

        $this->props['maxBytes'] = $bytes;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $array = parent::toArray();
        $array['props'] = ['accept' => $this->defaultFormats(), 'maxBytes' => $this->defaultMaxBytes(), ...$array['props']];

        return $array;
    }

    /** @return array<string, string> */
    abstract protected function defaultFormats(): array;

    abstract protected function defaultMaxBytes(): int;
}
