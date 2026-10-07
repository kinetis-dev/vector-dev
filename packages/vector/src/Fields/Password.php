<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use Kinetis\Vector\Auth\PasswordHasherInterface;

/**
 * A secret typed on a form and never shown again: only on forms, never
 * listed, searched, audited or reverted. What is stored is what was
 * typed, unless the field is `hashed()`: then the application's
 * PasswordHasherInterface — or the implementation the field names —
 * hashes it before it reaches the entity, and an empty value on an edit
 * form leaves the stored hash alone.
 */
final class Password extends Field
{
    #[\Override]
    public static function make(string $name, ?string $label = null): static
    {
        return parent::make($name, $label)->onlyOnForms();
    }

    #[\Override]
    public function type(): string
    {
        return 'password';
    }

    /**
     * Hash what is typed before storing it, through the application's
     * bound PasswordHasherInterface, or through $hasher — a class
     * implementing it, built by the request container — when this
     * field's table is hashed differently.
     *
     * @param class-string<PasswordHasherInterface>|null $hasher
     */
    public function hashed(?string $hasher = null): static
    {
        return $this->props(['hasher' => $hasher ?? PasswordHasherInterface::class]);
    }
}
