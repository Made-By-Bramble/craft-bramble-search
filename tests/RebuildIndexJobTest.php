<?php

declare(strict_types=1);

namespace MadeByBrambleTest\BrambleSearch;

use craft\base\Batchable;
use craft\queue\QueueInterface;
use MadeByBramble\BrambleSearch\jobs\RebuildIndexJob;
use PHPUnit\Framework\TestCase;

final class RebuildIndexJobTest extends TestCase
{
    public function testFailedBatchReleasesOwnedRebuildLock(): void
    {
        $job = new FailingBatchSetupRebuildIndexJob([
            'siteId' => 1,
            'rebuildLockAcquired' => true,
        ]);

        try {
            $job->execute(new NoopQueue());
            self::fail('Expected the rebuild job to throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Forced batch failure.', $e->getMessage());
        }

        self::assertSame(1, $job->releasedSiteId);
        self::assertFalse($job->rebuildLockAcquired);
    }

    public function testFailedBatchDoesNotReleaseUnownedRebuildLock(): void
    {
        $job = new FailingBatchSetupRebuildIndexJob([
            'siteId' => 1,
        ]);

        try {
            $job->execute(new NoopQueue());
            self::fail('Expected the rebuild job to throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Forced batch failure.', $e->getMessage());
        }

        self::assertNull($job->releasedSiteId);
    }
}

final class FailingBatchSetupRebuildIndexJob extends RebuildIndexJob
{
    public ?int $releasedSiteId = null;

    public function loadData(): Batchable
    {
        return new SingleItemBatchable();
    }

    public function beforeBatch(): void
    {
        throw new \RuntimeException('Forced batch failure.');
    }

    public function processItem(mixed $item): void
    {
    }

    protected function before(): void
    {
    }

    protected function releaseRebuildLock(?int $siteId): void
    {
        $this->releasedSiteId = $siteId;
        $this->rebuildLockAcquired = false;
    }
}

final class SingleItemBatchable implements Batchable
{
    public function count(): int
    {
        return 1;
    }

    public function getSlice(int $offset, int $limit): iterable
    {
        yield new \stdClass();
    }
}

final class NoopQueue implements QueueInterface
{
    public function run(): mixed
    {
        return null;
    }

    public function retry(string $id): void
    {
    }

    public function retryAll(): void
    {
    }

    public function setProgress(int $progress, ?string $label = null): void
    {
    }

    public function getHasWaitingJobs(): bool
    {
        return false;
    }

    public function getHasReservedJobs(): bool
    {
        return false;
    }

    public function getTotalJobs(): int
    {
        return 0;
    }

    public function getJobInfo(?int $limit = null): array
    {
        return [];
    }

    public function getJobDetails(string $id): array
    {
        return [];
    }

    public function releaseAll(): void
    {
    }

    public function release(string $id): void
    {
    }
}
