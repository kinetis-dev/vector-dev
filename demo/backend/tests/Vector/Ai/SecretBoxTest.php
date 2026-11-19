<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Config\Config;
use Kinetis\VectorAi\Secrets\SealedWithAnotherKeyException;
use Kinetis\VectorAi\Secrets\SecretBox;
use PHPUnit\Framework\TestCase;

/** SecretBox: sealing under VECTOR_AI_KEY for one context, a fresh nonce per write, and a key that changed told apart. */
final class SecretBoxTest extends TestCase
{
    public function testASealedKeyOpensUnderTheSameKeyOnly(): void
    {
        $key = random_bytes(32);
        $box = SecretBox::fromConfig(new Config(['VECTOR_AI_KEY' => 'base64:' . base64_encode($key)]));
        $first = $box->seal('sk-secret', 'openai');
        $second = $box->seal('sk-secret', 'openai');

        self::assertSame(substr(hash('sha256', $key), 0, 8), $box->keyId());
        self::assertSame($box->keyId(), $first->keyId);
        self::assertSame(24, strlen($first->nonce));
        self::assertNotSame($first->nonce, $second->nonce, 'a random nonce for every write');
        self::assertNotSame($first->ciphertext, $second->ciphertext);
        self::assertStringNotContainsString('sk-secret', $first->ciphertext);
        self::assertSame('sk-secret', $box->open($first, 'openai'));
        self::assertStringNotContainsString(base64_encode($key), print_r($box, true), 'a dump never shows the key');

        $this->expectException(SealedWithAnotherKeyException::class);
        $this->expectExceptionMessage('Sealed with another key; re-enter it.');
        SecretBox::withKey(random_bytes(32))->open($first, 'openai');
    }

    public function testAnAlteredCiphertextDoesNotOpen(): void
    {
        $box = SecretBox::withKey(random_bytes(32));
        $sealed = $box->seal('sk-secret', 'openai');

        $this->expectException(\UnexpectedValueException::class);
        $box->open(new \Kinetis\VectorAi\Secrets\Sealed($sealed->ciphertext . 'x', $sealed->nonce, $sealed->keyId), 'openai');
    }

    public function testASealedValueOpensForItsOwnContextOnly(): void
    {
        $box = SecretBox::withKey(random_bytes(32));
        $sealed = $box->seal('sk-secret', 'openai');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('sealed for another provider');
        $box->open($sealed, 'anthropic');
    }

    public function testWithoutAKeyNothingIsSealed(): void
    {
        $box = SecretBox::fromConfig(new Config([]));

        self::assertFalse($box->canSeal());
        self::assertNull($box->keyId());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Set VECTOR_AI_KEY to store provider keys.');
        $box->seal('sk-secret', 'openai');
    }
}
