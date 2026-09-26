<?php

declare(strict_types=1);

namespace Kinetis\Vector\Testing;

/**
 * For a test case extending the framework's ApplicationTestCase:
 * `$this->vector($headers)` is a VectorClient on the test's own client.
 */
trait InteractsWithVector
{
    /** @param array<string, string> $headers the identity to act as */
    protected function vector(array $headers = []): VectorClient
    {
        return new VectorClient($this->client, $headers);
    }
}
