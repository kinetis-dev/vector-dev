<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Jobs\StalledJobs;
use Kinetis\Vector\Store\JobStore;

/**
 * A job whose worker died leaves its row running with nothing to move
 * it on. After the minutes the Background jobs setting allows without a
 * progress report — 30 until an admin changes it — the row is answered
 * as stalled, and the reaper marks it failed.
 */
final class StalledJobsTest extends VectorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'jobs')->delete();
    }

    protected function tearDown(): void
    {
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'jobs')->delete();
        parent::tearDown();
    }

    public function testARunningJobSilentForLongerThanTheSettingIsStalledAndCanBeReaped(): void
    {
        $this->client->get('/vector/settings/jobs', headers: $this->adminHeaders())->assertOk()->assertJsonPath('data.values.stalledAfterMinutes', StalledJobs::DEFAULT_MINUTES);

        $store = $this->app->createRequestScope()->get(JobStore::class);
        self::assertInstanceOf(JobStore::class, $store);
        $id = $store->create('action', 'products', 'reprice-products', '1');
        self::assertTrue($store->start($id));

        try {
            $this->client->get("/vector/jobs/{$id}", headers: $this->adminHeaders())->assertOk()->assertJsonPath('data.status', 'running');

            $this->silentFor($id, 40);
            $this->client->get("/vector/jobs/{$id}", headers: $this->adminHeaders())->assertOk()->assertJsonPath('data.status', 'stalled');
            $listed = $this->client->get('/vector/jobs', headers: $this->adminHeaders())->assertOk()->json();
            self::assertIsArray($listed);
            self::assertSame('stalled', array_column($listed['data'], 'status', 'id')[$id]);
            self::assertSame('running', $this->vectorQuery()->table('vector_jobs')->select('status')->where('id', '=', $id)->first()['status'] ?? null, 'answered as stalled, stored as running');

            // an admin allows longer: the same silence is still running
            $this->client->request('PUT', '/vector/settings/jobs', body: ['stalledAfterMinutes' => 60], headers: $this->adminHeaders())->assertOk();
            $this->client->get("/vector/jobs/{$id}", headers: $this->adminHeaders())->assertOk()->assertJsonPath('data.status', 'running');
            $this->client->request('PUT', '/vector/settings/jobs', body: ['stalledAfterMinutes' => 0], headers: $this->adminHeaders())->assertValidationError('stalledAfterMinutes');

            // the reaper closes what is stalled under the current setting, and nothing else
            $stalled = $this->app->createRequestScope()->get(StalledJobs::class);
            self::assertInstanceOf(StalledJobs::class, $stalled);
            self::assertSame(60, $stalled->minutes());
            self::assertSame(0, $stalled->reap());

            $this->silentFor($id, 61);
            self::assertSame(1, $stalled->reap());
            $this->client->get("/vector/jobs/{$id}", headers: $this->adminHeaders())
                ->assertOk()
                ->assertJsonPath('data.status', 'failed');
            $row = $this->vectorQuery()->table('vector_jobs')->select('failure', 'finished_at')->where('id', '=', $id)->first();
            self::assertIsArray($row);
            self::assertStringContainsString('60 minutes', (string) $row['failure']);
            self::assertNotNull($row['finished_at']);
        } finally {
            $this->vectorQuery()->table('vector_jobs')->where('id', '=', $id)->delete();
        }
    }

    private function silentFor(int $id, int $minutes): void
    {
        $then = new \DateTimeImmutable("-{$minutes} minutes", new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->vectorQuery()->table('vector_jobs')->where('id', '=', $id)->update(['updated_at' => $then]);
    }
}
