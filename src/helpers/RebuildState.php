<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\helpers;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Queue as QueueHelper;
use craft\helpers\StringHelper;
use craft\queue\Queue;
use MadeByBramble\BrambleSearch\adapters\BaseSearchAdapter;
use MadeByBramble\BrambleSearch\jobs\PrepareRebuildJob;
use MadeByBramble\BrambleSearch\jobs\RebuildIndexJob;
use MadeByBramble\BrambleSearch\Plugin;

final class RebuildState
{
    public const RUNS = '{{%bramble_search_rebuild_runs}}';
    public const CHUNKS = '{{%bramble_search_rebuild_chunks}}';
    public const CHUNK_SIZE = 20;
    public const PRIORITY = 4096;
    public const WORK_SECONDS = 5;

    public static function withSiteGuard(string $identity, int $siteId, callable $callback): mixed
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $identity) !== 1 || $siteId <= 0) {
            throw new \InvalidArgumentException('A rebuild requires a valid index identity and site ID.');
        }
        $mutex = Plugin::$plugin?->getIndexMutex() ?? Craft::createObject(App::dbMutexConfig());
        $name = 'bs:rebuild:' . substr($identity, 0, 32) . ':' . $siteId;
        if (!$mutex->acquire($name, 10)) {
            throw new \RuntimeException('Unable to acquire the rebuild execution guard.');
        }
        $result = null;
        $failure = null;
        try {
            $result = $callback();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        try {
            if (!$mutex->release($name)) {
                throw new \RuntimeException('Unable to release the rebuild execution guard.');
            }
        } catch (\Throwable $exception) {
            if ($failure !== null) {
                throw new \RuntimeException($exception->getMessage(), 0, $failure);
            }
            throw $exception;
        }
        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }

    public static function owner(string $identity, int $siteId): ?array
    {
        $row = (new Query())->from(self::RUNS)->where(['indexIdentity' => $identity, 'siteId' => $siteId])->one();
        if ($row === null || $row === false) {
            return null;
        }
        $types = json_decode($row['elementTypes'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($types) || !array_is_list($types) || preg_match('/\A[a-f0-9]{32}\z/D', $row['runId']) !== 1
            || !in_array($row['status'], ['building', 'active', 'complete', 'failed'], true)) {
            throw new \RuntimeException('Malformed rebuild owner state.');
        }
        foreach (['totalItems', 'nextOffset'] as $column) {
            $value = filter_var($row[$column], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($value === false) {
                throw new \RuntimeException('Malformed rebuild progress.');
            }
            $row[$column] = $value;
        }
        if ($row['nextOffset'] > $row['totalItems']) {
            throw new \RuntimeException('Rebuild progress exceeds its immutable manifest.');
        }
        foreach ($types as $type) {
            if (!is_string($type)) {
                throw new \RuntimeException('Malformed rebuild element type list.');
            }
            if (in_array($row['status'], ['building', 'active'], true)) {
                IndexableElements::validateType($type);
            }
        }
        $row['types'] = $types;
        $row['lifecycleRaw'] = $row['lifecycle'] ?? null;
        $lifecycle = isset($row['lifecycle']) ? json_decode($row['lifecycle'], true, 512, JSON_THROW_ON_ERROR) : ['phase' => 'indexing'];
        if (!is_array($lifecycle) || !in_array($lifecycle['phase'] ?? null, ['retireSites', 'begin', 'capture', 'indexing', 'catalogue', 'membership', 'pending', 'activate', 'cleanup', 'complete', 'cancelled'], true)) {
            throw new \RuntimeException('Malformed rebuild lifecycle checkpoint.');
        }
        $row['lifecycle'] = $lifecycle;

        return $row;
    }

    public static function update(string $identity, int $siteId, string $runId, array $values): void
    {
        $owner = self::owner($identity, $siteId);
        if ($owner === null || $owner['runId'] !== $runId) {
            throw new \RuntimeException('A rebuild cannot update another run owner.');
        }
        if ($owner['status'] === 'failed' && ($values !== ['status' => 'failed'])) {
            throw new \RuntimeException('A terminal rebuild cannot be made active by a stale continuation.');
        }
        if (isset($values['lifecycle']) && is_array($values['lifecycle'])) {
            $values['lifecycle'] = json_encode($values['lifecycle'], JSON_THROW_ON_ERROR);
        }
        $values['dateUpdated'] = Db::prepareDateForDb(new \DateTime());
        $affected = Craft::$app->getDb()->createCommand()->update(self::RUNS, $values, [
            'indexIdentity' => $identity, 'siteId' => $siteId, 'runId' => $runId,
            'status' => $owner['status'], 'lifecycle' => $owner['lifecycleRaw'],
        ])->execute();
        if ($affected === 0) {
            $current = (new Query())->from(self::RUNS)->where(['indexIdentity' => $identity, 'siteId' => $siteId, 'runId' => $runId])->one();
            foreach ($values as $key => $value) {
                if ($current === false || $current === null || (string)($current[$key] ?? '') !== (string)$value) {
                    throw new \RuntimeException('The rebuild checkpoint was cancelled or superseded.');
                }
            }
        }
    }

    public static function enqueue(BaseSearchAdapter $adapter, int $siteId): bool
    {
        $identity = $adapter->getIndexIdentity();

        return self::withSiteGuard($identity, $siteId, function() use ($identity, $siteId): bool {
            if (Craft::$app->getSites()->getSiteById($siteId) === null) {
                throw new \RuntimeException('The requested rebuild site does not exist.');
            }
            $owner = self::owner($identity, $siteId);
            if ($owner !== null && in_array($owner['status'], ['building', 'active'], true)
                && (!(Craft::$app->getQueue() instanceof Queue) || self::hasQueuedJob($identity, $siteId, $owner['runId']))) {
                return false;
            }
            $runId = bin2hex(random_bytes(16));
            $now = Db::prepareDateForDb(new \DateTime());
            $values = [
                'indexIdentity' => $identity, 'siteId' => $siteId, 'runId' => $runId, 'status' => 'building',
                'elementTypes' => json_encode(array_values(Craft::$app->getElements()->getAllElementTypes()), JSON_THROW_ON_ERROR),
                'totalItems' => 0, 'nextOffset' => 0, 'dateUpdated' => $now,
                'lifecycle' => json_encode(['phase' => 'retireSites'], JSON_THROW_ON_ERROR),
            ];
            Craft::$app->getDb()->transaction(function() use ($owner, $identity, $siteId, $values, $now): void {
                if ($owner === null) {
                    Craft::$app->getDb()->createCommand()->insert(self::RUNS, [...$values, 'dateCreated' => $now, 'uid' => StringHelper::UUID()])->execute();
                } else {
                    Craft::$app->getDb()->createCommand()->update(self::RUNS, $values, ['indexIdentity' => $identity, 'siteId' => $siteId, 'runId' => $owner['runId']])->execute();
                    if ($owner['status'] === 'complete') {
                        Craft::$app->getDb()->createCommand()->delete(self::CHUNKS, ['runId' => $owner['runId']])->execute();
                    }
                }
            });
            try {
                $id = QueueHelper::push(new PrepareRebuildJob(['indexIdentity' => $identity, 'siteId' => $siteId, 'rebuildRunId' => $runId]), self::PRIORITY, 0, 1800);
                if ($id === null || $id === '') {
                    throw new \RuntimeException('The rebuild preparation was not queued.');
                }
            } catch (\Throwable $error) {
                self::update($identity, $siteId, $runId, ['status' => 'failed']);
                throw $error;
            }

            return true;
        });
    }

    public static function hasQueuedJob(string $identity, int $siteId, string $runId, ?string $jobType = null): bool
    {
        $queue = Craft::$app->getQueue();
        if (!($queue instanceof Queue)) {
            return false;
        }
        $channel = $queue->channel;
        if ($channel === null) {
            foreach (Craft::$app->getComponents(false) as $id => $component) {
                if ($component === $queue) {
                    $channel = (string)$id;
                    break;
                }
            }
            $channel ??= 'queue';
        }
        foreach ((new Query())->select('job')->from($queue->tableName)->where(['channel' => $channel, 'fail' => false])->each(100, $queue->db) as $row) {
            $payload = $row['job'];
            if (is_resource($payload)) {
                $payload = stream_get_contents($payload);
                if (is_string($payload) && str_starts_with($payload, 'x') && ctype_xdigit(substr($payload, 1))) {
                    $payload = hex2bin(substr($payload, 1));
                }
            }
            [$job, $error] = $queue->unserializeMessage((string)$payload);
            if ($error === null && ($job instanceof PrepareRebuildJob || $job instanceof RebuildIndexJob)
                && ($jobType === null || $job instanceof $jobType)
                && $job->indexIdentity === $identity && $job->siteId === $siteId && $job->rebuildRunId === $runId) {
                return true;
            }
        }

        return false;
    }

    public static function canRetry(int $attempt, string $identity, ?int $siteId, string $runId): bool
    {
        if ($siteId === null || $siteId <= 0 || preg_match('/\A[a-f0-9]{64}\z/D', $identity) !== 1
            || preg_match('/\A[a-f0-9]{32}\z/D', $runId) !== 1) {
            return false;
        }
        $owner = self::owner($identity, $siteId);
        if ($owner === null || $owner['runId'] !== $runId || !in_array($owner['status'], ['building', 'active'], true)) {
            return false;
        }
        if ($attempt < 3) {
            return true;
        }
        try {
            self::withSiteGuard($identity, $siteId, function() use ($identity, $siteId, $runId): void {
                $owner = self::owner($identity, $siteId);
                if ($owner !== null && $owner['runId'] === $runId && in_array($owner['status'], ['building', 'active'], true)) {
                    self::update($identity, $siteId, $runId, ['status' => 'failed']);
                }
            });
        } catch (\Throwable $error) {
            Craft::error('Unable to record exhausted rebuild ownership: ' . $error->getMessage(), __METHOD__);
        }

        return false;
    }

    public static function fail(string $identity, int $siteId, string $runId): void
    {
        Craft::$app->getDb()->createCommand()->update(self::RUNS, [
            'status' => 'failed', 'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ], ['indexIdentity' => $identity, 'siteId' => $siteId, 'runId' => $runId, 'status' => ['building', 'active']])->execute();
    }

    public static function cancel(string $identity, int $siteId): void
    {
        $db = Craft::$app->getDb();
        if (!$db->tableExists(self::RUNS)) {
            return;
        }
        $db->createCommand()->update(self::RUNS, [
            'status' => 'failed', 'lifecycle' => json_encode(['phase' => 'cancelled'], JSON_THROW_ON_ERROR),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ], ['indexIdentity' => $identity, 'siteId' => $siteId, 'status' => ['building', 'active']])->execute();
    }

    public static function assertManifest(array $owner): void
    {
        $totals = (new Query())->select([
            'items' => new \yii\db\Expression('COALESCE(SUM([[itemCount]]), 0)'),
            'chunks' => new \yii\db\Expression('COUNT(*)'),
            'last' => new \yii\db\Expression('MAX([[chunkIndex]])'),
        ])->from(self::CHUNKS)->where(['runId' => $owner['runId']])->one();
        $chunks = (int)ceil($owner['totalItems'] / self::CHUNK_SIZE);
        if ((int)$totals['items'] !== $owner['totalItems'] || (int)$totals['chunks'] !== $chunks
            || ($chunks > 0 && (int)$totals['last'] !== $chunks - 1)) {
            throw new \RuntimeException('The immutable rebuild manifest is incomplete.');
        }
    }
}
