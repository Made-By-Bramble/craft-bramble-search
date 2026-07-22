<?php

declare(strict_types=1);

namespace MadeByBrambleTest\BrambleSearch;

use Craft;
use craft\base\Batchable;
use craft\queue\QueueInterface;
use MadeByBramble\BrambleSearch\jobs\RebuildIndexJob;
use PHPUnit\Framework\TestCase;

final class RebuildIndexJobTest extends TestCase
{
    public function testRebuildJobRetriesExpiredOrFailedAttempts(): void
    {
        $job = new FailingBatchSetupRebuildIndexJob(['siteId' => 1]);

        self::assertSame(1800, $job->getTtr());
        self::assertTrue($job->canRetry(1, new \RuntimeException('transient')));
        self::assertTrue($job->canRetry(2, new \RuntimeException('transient')));
        self::assertFalse($job->canRetry(3, new \RuntimeException('transient')));
    }

    public function testExpiredRebuildLockDetectionUsesJobTtrBuffer(): void
    {
        $job = new FailingBatchSetupRebuildIndexJob(['siteId' => 1]);

        self::assertFalse($job->publicIsExpiredRebuildLock(time()));
        self::assertTrue($job->publicIsExpiredRebuildLock(time() - 1900));
    }

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

    public function testSupersededContinuationBatchReturnsWithoutProcessing(): void
    {
        $siteId = 9101;
        $key = "bramble-search:rebuild:$siteId";
        $cache = Craft::$app->getCache();
        $cache->set($key, ['time' => time(), 'runId' => 'newer-chain'], 3600);

        try {
            $job = new SupersedeGuardRebuildIndexJob([
                'siteId' => $siteId,
                'itemOffset' => 1,
                'batchSize' => 10,
                'rebuildLockAcquired' => true,
                'rebuildRunId' => 'stale-chain',
            ]);

            $job->execute(new NoopQueue());

            self::assertFalse($job->processedAnyItem, 'A superseded batch must not process items.');
            self::assertFalse($job->afterRan, 'A superseded batch must not spawn or run after().');
        } finally {
            $cache->delete($key);
        }
    }

    public function testFirstBatchIsNeverTreatedAsSuperseded(): void
    {
        // itemOffset === 0 is the batch that acquires the lock in before(); the supersede
        // guard only applies to continuation batches, so no lock needs to exist yet.
        $siteId = 9102;
        $key = "bramble-search:rebuild:$siteId";
        Craft::$app->getCache()->delete($key);

        $job = new SupersedeGuardRebuildIndexJob([
            'siteId' => $siteId,
            'itemOffset' => 0,
            'batchSize' => 10,
        ]);

        $job->execute(new NoopQueue());

        self::assertTrue($job->processedAnyItem);
    }

    public function testReleaseRebuildLockOnlyClearsALockThisChainOwns(): void
    {
        $siteId = 9103;
        $key = "bramble-search:rebuild:$siteId";
        $cache = Craft::$app->getCache();

        try {
            // A newer chain holds the lock; a superseded/stale chain must not clear it.
            $cache->set($key, ['time' => time(), 'runId' => 'newer-chain'], 3600);

            $staleJob = new SupersedeGuardRebuildIndexJob(['siteId' => $siteId, 'rebuildRunId' => 'stale-chain']);
            $staleJob->publicReleaseRebuildLock($siteId);

            self::assertSame('newer-chain', $cache->get($key)['runId'] ?? null, 'The newer chain\'s lock must survive.');

            // The chain that actually owns the current lock can clear it.
            $owningJob = new SupersedeGuardRebuildIndexJob(['siteId' => $siteId, 'rebuildRunId' => 'newer-chain']);
            $owningJob->publicReleaseRebuildLock($siteId);

            self::assertFalse($cache->get($key));
        } finally {
            $cache->delete($key);
        }
    }
}

/**
 * Exercises the real (unmocked) ownsCurrentRebuildLock()/releaseRebuildLock() logic
 * against Craft's cache, only stubbing out the batch-processing lifecycle so before()'s
 * site lookup and normal indexing aren't needed.
 */
final class SupersedeGuardRebuildIndexJob extends RebuildIndexJob
{
    public bool $processedAnyItem = false;
    public bool $afterRan = false;

    public function loadData(): Batchable
    {
        return new SingleItemBatchable();
    }

    protected function before(): void
    {
    }

    public function beforeBatch(): void
    {
    }

    public function afterBatch(): void
    {
    }

    public function processItem(mixed $item): void
    {
        $this->processedAnyItem = true;
    }

    protected function after(): void
    {
        $this->afterRan = true;
    }

    public function publicReleaseRebuildLock(?int $siteId): void
    {
        $this->releaseRebuildLock($siteId);
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

    public function publicIsExpiredRebuildLock(mixed $lockValue): bool
    {
        return $this->isExpiredRebuildLock($lockValue);
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
