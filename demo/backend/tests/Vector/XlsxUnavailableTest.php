<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/** A deployment that offers CSV only — as one without openspout/openspout would. */
final class XlsxUnavailableTest extends VectorTestCase
{
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'VECTOR_EXPORT_FORMATS' => 'csv'];
    }

    public function testXlsxIsNeitherOfferedNorAccepted(): void
    {
        $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->assertJsonPath('capabilities.exportFormats', ['csv']);
        $this->client->get('/vector/r/tags/export', query: ['format' => 'xlsx'], headers: $this->adminHeaders())->assertValidationError('format');
        $this->client->get('/vector/r/tags/export', query: ['format' => 'csv'], headers: $this->adminHeaders())->assertOk();
    }
}
