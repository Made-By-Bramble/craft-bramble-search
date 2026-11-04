<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\jobs;

use Craft;
use craft\base\Batchable;
use craft\helpers\Queue as QueueHelper;
use craft\queue\BaseBatchedJob;
use MadeByBramble\BrambleSearch\adapters\BaseSearchAdapter;
use MadeByBramble\BrambleSearch\helpers\IndexableElements;
use MadeByBramble\BrambleSearch\helpers\RebuildState;
use yii\queue\PushEvent;
use yii\queue\Queue;
use yii\queue\RetryableJobInterface;

class RebuildIndexJob extends BaseBatchedJob implements RetryableJobInterface
{
    public ?int $siteId = null;
    public string $indexIdentity = '';
    public string $rebuildRunId = '';
    public bool $rebuildLockAcquired = false;
    public int $batchSize = 100;

    private bool $afterBatchRan = false;
    private bool $continuationPushed = false;

    public function getTtr(): int
    {
        return 1800;
    }

    public function canRetry($attempt, $error): bool
    {
        if ($this->indexIdentity === '') {
            return false;
        }
        return RebuildState::canRetry((int)$attempt, $this->indexIdentity, $this->siteId, $this->rebuildRunId);
    }

    public function execute($queue): void
    {
        if ($this->indexIdentity === '') {
            throw new \RuntimeException('Legacy rebuild payloads must be drained before the manifest upgrade; queue a fresh rebuild after removing the identified legacy job.');
        }
        IndexableElements::assertFreshReadContext();
        $adapter = $this->getSearchAdapter();
        if ($this->siteId === null || $adapter->getIndexIdentity() !== $this->indexIdentity || !($queue instanceof Queue)) {
            throw new \RuntimeException('The rebuild does not match its active index or queue.');
        }
        RebuildState::withSiteGuard($this->indexIdentity, $this->siteId, function() use ($queue, $adapter): void {
            $adapter->withRebuildContext($this->siteId, $this->rebuildRunId, function() use ($queue, $adapter): void {
                $owner = RebuildState::owner($this->indexIdentity, $this->siteId);
                if ($owner === null) {
                    throw new \RuntimeException('The rebuild has lost its durable owner.');
                }
                if ($owner['runId'] !== $this->rebuildRunId) {
                    Craft::warning('A superseded search rebuild batch was ignored.', __METHOD__);

                    return;
                }
                if ($owner['status'] === 'complete') {
                    return;
                }
                if ($owner['status'] !== 'active') {
                    throw new \RuntimeException('The rebuild owner is not ready for execution.');
                }
                if ($owner['lifecycle']['phase'] !== 'indexing') {
                    $deadline = microtime(true) + RebuildState::WORK_SECONDS;
                    $checkpoint = $owner['lifecycle'];
                    do {
                        $checkpoint = $adapter->advanceDerivedRebuild($this->siteId, $this->rebuildRunId, $checkpoint);
                        RebuildState::update($this->indexIdentity, $this->siteId, $this->rebuildRunId, [
                            'status' => $checkpoint['phase'] === 'complete' ? 'complete' : 'active', 'lifecycle' => $checkpoint,
                        ]);
                    } while ($checkpoint['phase'] !== 'complete' && microtime(true) < $deadline);
                    if ($checkpoint['phase'] !== 'complete') {
                        $this->queueLifecycleContinuation($queue);
                    }
                    return;
                }
                RebuildState::assertManifest($owner);
                $this->itemOffset = $owner['nextOffset'];
                $this->priority ??= RebuildState::PRIORITY;
                $this->ttr ??= $this->getTtr();
                $this->afterBatchRan = false;
                $this->continuationPushed = false;
                $pushed = function(PushEvent $event): void {
                    $job = $event->job;
                    if ($job instanceof self && $job->indexIdentity === $this->indexIdentity && $job->siteId === $this->siteId
                        && $job->rebuildRunId === $this->rebuildRunId && $event->id !== null && (string)$event->id !== '') {
                        $this->continuationPushed = true;
                    }
                };
                $queue->on(Queue::EVENT_AFTER_PUSH, $pushed);
                try {
                    parent::execute($queue);
                    if ($this->afterBatchRan && $this->itemOffset < $owner['totalItems'] && !$this->continuationPushed) {
                        throw new \RuntimeException('The rebuild continuation was not queued after its durable checkpoint.');
                    }
                } catch (\Throwable $error) {
                    Craft::error('Search rebuild remains retryable after failure: ' . $error->getMessage(), __METHOD__);
                    throw $error;
                } finally {
                    $queue->off(Queue::EVENT_AFTER_PUSH, $pushed);
                    $this->setBulkMode($adapter, false);
                }
            });
        });
    }

    protected function before(): void
    {
        if (Craft::$app->getSites()->getSiteById($this->siteId) === null) {
            throw new \RuntimeException('The rebuild site no longer exists.');
        }
    }

    protected function beforeBatch(): void
    {
        $this->setBulkMode($this->getSearchAdapter(), true);
    }

    protected function afterBatch(): void
    {
        $this->setBulkMode($this->getSearchAdapter(), false);
        RebuildState::update($this->indexIdentity, $this->siteId, $this->rebuildRunId, ['nextOffset' => $this->itemOffset]);
        $this->afterBatchRan = true;
    }

    public function loadData(): Batchable
    {
        if ($this->indexIdentity === '') {
            return new MultiElementTypeBatcher([]);
        }
        if ($this->siteId === null) {
            throw new \RuntimeException('The rebuild requires a site ID.');
        }

        return new RebuildManifestBatcher($this->indexIdentity, $this->siteId, $this->rebuildRunId);
    }

    public function processItem(mixed $item): void
    {
        if (!is_array($item) || !is_int($item['elementId'] ?? null) || $item['elementId'] <= 0 || !is_string($item['elementType'] ?? null)) {
            throw new \RuntimeException('The rebuild yielded an invalid scalar reference.');
        }
        if (!$this->getSearchAdapter()->indexPersistedElement($item['elementType'], $item['elementId'], $this->siteId, null)) {
            throw new \RuntimeException('The rebuild could not publish a current element.');
        }
    }

    protected function after(): void
    {
        $owner = RebuildState::owner($this->indexIdentity, $this->siteId);
        if ($owner === null || $owner['runId'] !== $this->rebuildRunId || $owner['status'] !== 'active') {
            throw new \RuntimeException('The rebuild lost ownership before finalization.');
        }
        RebuildState::assertManifest($owner);
        $adapter = $this->getSearchAdapter();
        if (!$adapter->pruneIndexForSite($this->siteId, IndexableElements::currentIds($owner['types'], $this->siteId))) {
            throw new \RuntimeException('The rebuild could not prune current ineligible documents.');
        }
        $adapter->auditIndexMetadata();
        RebuildState::update($this->indexIdentity, $this->siteId, $this->rebuildRunId, ['nextOffset' => $owner['totalItems'], 'lifecycle' => ['phase' => 'catalogue']]);
        $this->queueLifecycleContinuation(Craft::$app->getQueue());
    }

    protected function queueLifecycleContinuation(Queue $queue): void
    {
        $id = QueueHelper::push(new self([
            'siteId' => $this->siteId, 'indexIdentity' => $this->indexIdentity, 'rebuildRunId' => $this->rebuildRunId,
            'batchSize' => $this->batchSize,
        ]), $this->priority ?? RebuildState::PRIORITY, 0, $this->getTtr(), $queue);
        if ($id === null || $id === '') {
            throw new \RuntimeException('The rebuild lifecycle continuation was not queued.');
        }
    }

    protected function getSearchAdapter(): BaseSearchAdapter
    {
        $adapter = Craft::$app->getSearch();
        if (!($adapter instanceof BaseSearchAdapter)) {
            throw new \RuntimeException('Bramble Search is not the active search service.');
        }

        return $adapter;
    }

    protected function setBulkMode(BaseSearchAdapter $adapter, bool $enabled): void
    {
        if (property_exists($adapter, 'bulkMode')) {
            $adapter->bulkMode = $enabled;
        }
    }

    protected function defaultDescription(): string
    {
        return 'Rebuilding search index';
    }
}
