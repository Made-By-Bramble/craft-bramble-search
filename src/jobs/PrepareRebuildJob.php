<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\jobs;

use Craft;
use craft\helpers\Db;
use craft\helpers\Queue as QueueHelper;
use craft\helpers\StringHelper;
use craft\queue\BaseJob;
use MadeByBramble\BrambleSearch\adapters\BaseSearchAdapter;
use MadeByBramble\BrambleSearch\helpers\IndexableElements;
use MadeByBramble\BrambleSearch\helpers\RebuildState;
use yii\queue\RetryableJobInterface;

class PrepareRebuildJob extends BaseJob implements RetryableJobInterface
{
    public ?int $siteId = null;
    public string $indexIdentity = '';
    public string $rebuildRunId = '';

    public function getTtr(): int
    {
        return 1800;
    }

    public function canRetry($attempt, $error): bool
    {
        return RebuildState::canRetry((int)$attempt, $this->indexIdentity, $this->siteId, $this->rebuildRunId);
    }

    public function execute($queue): void
    {
        IndexableElements::assertFreshReadContext();
        $adapter = Craft::$app->getSearch();
        if (!($adapter instanceof BaseSearchAdapter) || $adapter->getIndexIdentity() !== $this->indexIdentity || $this->siteId === null) {
            throw new \RuntimeException('Rebuild preparation does not match the active index namespace.');
        }
        RebuildState::withSiteGuard($this->indexIdentity, $this->siteId, function() use ($queue, $adapter): void {
            $adapter->withRebuildContext($this->siteId, $this->rebuildRunId, function() use ($queue, $adapter): void {
                $owner = RebuildState::owner($this->indexIdentity, $this->siteId);
                if ($owner === null) {
                    throw new \RuntimeException('Rebuild preparation has lost its durable owner.');
                }
                if ($owner['runId'] !== $this->rebuildRunId) {
                    Craft::warning('A superseded rebuild preparation was ignored.', __METHOD__);

                    return;
                }
                if ($owner['status'] === 'complete') {
                    return;
                }
                if ($owner['status'] === 'failed') {
                    throw new \RuntimeException('This rebuild owner exhausted its retry policy.');
                }
                if ($owner['status'] === 'building') {
                    if ($owner['lifecycle']['phase'] === 'retireSites') {
                        if (!$this->advanceRemovedSites($adapter, $owner['lifecycle']['retirement'] ?? [])) {
                            $this->queuePreparationContinuation($queue);
                            return;
                        }
                    }
                    $deadline = microtime(true) + RebuildState::WORK_SECONDS;
                    while (!$adapter->beginDerivedRebuild($this->siteId, $this->rebuildRunId)) {
                        if (microtime(true) >= $deadline) {
                            $this->queuePreparationContinuation($queue);
                            return;
                        }
                    }
                    RebuildState::update($this->indexIdentity, $this->siteId, $this->rebuildRunId, ['lifecycle' => ['phase' => 'capture']]);
                    Craft::$app->getDb()->createCommand()->delete(RebuildState::CHUNKS, ['runId' => $this->rebuildRunId])->execute();
                    $pending = [];
                    $chunk = 0;
                    $total = 0;
                    foreach ($owner['types'] as $typeIndex => $type) {
                        $last = 0;
                        do {
                            $ids = IndexableElements::query($type, $this->siteId)->andWhere(['>', 'elements.id', $last])->limit(1000)->ids();
                            foreach ($ids as $id) {
                                $id = (int)$id;
                                if ($id <= $last) {
                                    throw new \RuntimeException('Manifest capture did not advance its keyset.');
                                }
                                $pending[] = [$id, $typeIndex];
                                $last = $id;
                                $total++;
                                if (count($pending) === RebuildState::CHUNK_SIZE) {
                                    $this->storeChunk($chunk++, $pending);
                                    $pending = [];
                                }
                            }
                        } while (count($ids) === 1000);
                    }
                    if ($pending !== []) {
                        $this->storeChunk($chunk, $pending);
                    }
                    $owner['totalItems'] = $total;
                    RebuildState::assertManifest($owner);
                    RebuildState::update($this->indexIdentity, $this->siteId, $this->rebuildRunId, ['status' => 'active', 'totalItems' => $total, 'nextOffset' => 0, 'lifecycle' => ['phase' => 'indexing']]);
                }
                if (RebuildState::hasQueuedJob($this->indexIdentity, $this->siteId, $this->rebuildRunId, RebuildIndexJob::class)) {
                    return;
                }
                $id = QueueHelper::push(new RebuildIndexJob([
                    'siteId' => $this->siteId, 'indexIdentity' => $this->indexIdentity, 'rebuildRunId' => $this->rebuildRunId,
                ]), RebuildState::PRIORITY, 0, 1800, $queue);
                if ($id === null || $id === '') {
                    throw new \RuntimeException('The prepared rebuild batch was not queued.');
                }
            });
        });
    }

    protected function advanceRemovedSites(BaseSearchAdapter $adapter, array $checkpoint): bool
    {
        if ($checkpoint !== []) {
            $restored = array_map('intval', (new \craft\db\Query())->select('id')->from('{{%sites}}')->where(['id' => $checkpoint['sites'], 'dateDeleted' => null])->column());
            $checkpoint['sites'] = array_values(array_diff($checkpoint['sites'], $restored));
        }
        if ($checkpoint === []) {
            $sites = $adapter->removedSiteIds();
            foreach ($sites as $siteId) {
                $adapter->fenceRemovedSite($siteId);
            }
            Craft::$app->getDb()->createCommand()->delete(RebuildState::CHUNKS, ['runId' => $this->rebuildRunId])->execute();
            $pending = [];
            $chunks = 0;
            if ($sites !== []) {
                foreach ($adapter->removedSiteDocumentReferences($sites) as $reference) {
                    $pending[] = $reference;
                    if (count($pending) === RebuildState::CHUNK_SIZE) {
                        $this->storeChunk($chunks++, $pending);
                        $pending = [];
                    }
                }
                if ($pending !== []) {
                    $this->storeChunk($chunks++, $pending);
                }
            }
            $checkpoint = ['stage' => 'primary', 'sites' => $sites, 'chunks' => $chunks, 'nextChunk' => 0];
        } elseif ($checkpoint['stage'] === 'primary') {
            if ($checkpoint['nextChunk'] < $checkpoint['chunks']) {
                $row = (new \craft\db\Query())->from(RebuildState::CHUNKS)->where(['runId' => $this->rebuildRunId, 'chunkIndex' => $checkpoint['nextChunk']])->one();
                if ($row === null || $row === false) {
                    throw new \RuntimeException('The removed-site cleanup manifest is incomplete.');
                }
                $adapter->deleteRemovedSiteReferences(json_decode($row['items'], true, 512, JSON_THROW_ON_ERROR));
                $checkpoint['nextChunk']++;
            } else {
                $checkpoint['stage'] = 'pending';
            }
        } elseif ($checkpoint['stage'] === 'pending') {
            $pending = $adapter->removedSitePendingReferences($checkpoint['sites']);
            $adapter->deleteRemovedSiteReferences($pending);
            if ($pending === []) {
                $checkpoint['stage'] = 'derived';
            }
        } elseif ($checkpoint['stage'] === 'derived') {
            if ($checkpoint['sites'] !== [] && $adapter->retireRemovedSiteStep($checkpoint['sites'][0])) {
                array_shift($checkpoint['sites']);
            }
        } else {
            throw new \RuntimeException('Invalid removed-site cleanup checkpoint.');
        }
        $done = $checkpoint['sites'] === [];
        if ($done) {
            Craft::$app->getDb()->createCommand()->delete(RebuildState::CHUNKS, ['runId' => $this->rebuildRunId])->execute();
        }
        RebuildState::update($this->indexIdentity, $this->siteId, $this->rebuildRunId, ['lifecycle' => $done ? ['phase' => 'begin'] : ['phase' => 'retireSites', 'retirement' => $checkpoint]]);
        return $done;
    }

    protected function queuePreparationContinuation($queue): void
    {
        $id = QueueHelper::push(new self([
            'siteId' => $this->siteId, 'indexIdentity' => $this->indexIdentity, 'rebuildRunId' => $this->rebuildRunId,
        ]), RebuildState::PRIORITY, 0, 1800, $queue);
        if ($id === null || $id === '') {
            throw new \RuntimeException('The rebuild preparation continuation was not queued.');
        }
    }

    protected function storeChunk(int $index, array $items): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        Craft::$app->getDb()->createCommand()->insert(RebuildState::CHUNKS, [
            'runId' => $this->rebuildRunId, 'chunkIndex' => $index, 'items' => json_encode($items, JSON_THROW_ON_ERROR),
            'itemCount' => count($items), 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
        ])->execute();
    }

    protected function defaultDescription(): string
    {
        return 'Preparing search index rebuild';
    }
}
