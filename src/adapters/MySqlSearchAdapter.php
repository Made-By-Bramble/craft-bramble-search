<?php

namespace MadeByBramble\BrambleSearch\adapters;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\helpers\StringHelper;
use craft\search\SearchQuery;
use yii\db\Expression;

/**
 * MySQL Search Adapter
 *
 * Implements the search adapter using MySQL as the storage backend.
 * Provides better performance and persistence than the Craft Cache adapter.
 * Recommended for production sites with large content volumes.
 */
class MySqlSearchAdapter extends BaseSearchAdapter
{
    protected function retireDerivedSiteStep(int $siteId): bool
    {
        return $this->deleteSiteSnapshot($siteId);
    }

    protected function documentIsCatalogued(int $siteId, int $elementId): bool
    {
        return $this->createIndexReadCommand((new Query())->select(new Expression('1'))->from($this->tablePrefix . 'metadata}}')->where(['key' => 'doc', 'value' => "$siteId:$elementId"])->limit(1))->queryScalar() !== false;
    }
    protected function repairIndexMetadata(array $publication, ?callable $progress = null): array
    {
        if ($publication['pending'] !== []) {
            return parent::repairIndexMetadata($publication, $progress);
        }
        if (!$publication['dirty'] && ($publication['initialized'] ?? false)) {
            return $publication;
        }
        $count = (int)$this->createIndexReadCommand((new Query())->select(new Expression('COUNT(DISTINCT [[value]])'))->from($this->tablePrefix . 'metadata}}')->where(['key' => 'doc']))->queryScalar();
        if ($progress !== null) {
            $progress(0, $count);
        }
        $catalogue = (new Query())->select(new Expression('1'))->from(['m' => $this->tablePrefix . 'metadata}}'])->where(['m.key' => 'doc'])->andWhere(new Expression("[[m.value]] = CONCAT([[d.siteId]], ':', [[d.elementId]])"));
        $length = (int)$this->createIndexReadCommand((new Query())->select(new Expression('SUM([[d.frequency]])'))->from(['d' => $this->tablePrefix . 'documents}}'])->where(['d.term' => '_length'])->andWhere(['exists', $catalogue]))->queryScalar();
        $this->writeIndexTotals($count, $length);
        $publication['dirty'] = false;
        $publication['initialized'] = true;
        $this->writeIndexRecord('publication', $publication);
        if ($progress !== null) {
            $progress($count, $count);
        }

        return $publication;
    }

    protected function refreshCommittedTotals(): void
    {
        $this->withPublicationGuard(fn(): bool => $this->runDocumentTransaction(function(): bool {
            $publication = $this->publicationState();
            $publication['dirty'] = true;
            $this->writeIndexRecord('publication', $publication);
            $this->repairIndexMetadata($publication);

            return true;
        }));
    }

    protected function deleteSiteSnapshot(int $siteId): bool
    {
        return $this->withPublicationGuard(fn(): bool => $this->runDocumentTransaction(function() use ($siteId): bool {
            \MadeByBramble\BrambleSearch\helpers\RebuildState::cancel($this->getIndexIdentity(), $siteId);
            $this->clearSearchMemo();
            $publication = $this->repairIndexMetadata($this->publicationState());
            if ($publication['pending'] !== []) {
                throw new \RuntimeException('SQL maintenance requires recovered source state.');
            }
            $count = $this->getTotalDocCount();
            $length = $this->getTotalLength();
            $db = Craft::$app->getDb();
            $removedCount = (int)$this->createIndexReadCommand((new Query())->select(new Expression('COUNT(DISTINCT [[value]])'))->from($this->tablePrefix . 'metadata}}')->where(['key' => 'doc'])->andWhere(['like', 'value', "$siteId:%", false]))->queryScalar();
            $removedLength = (int)$this->createIndexReadCommand((new Query())->select(new Expression('SUM([[frequency]])'))->from($this->tablePrefix . 'documents}}')->where(['siteId' => $siteId, 'term' => '_length']))->queryScalar();
            $db->createCommand()->delete($this->tablePrefix . 'terms}}', ['like', 'docId', "$siteId:%", false])->execute();
            foreach (['documents', 'titles'] as $table) {
                $db->createCommand()->delete($this->tablePrefix . $table . '}}', ['siteId' => $siteId])->execute();
            }
            $db->createCommand()->delete($this->tablePrefix . 'metadata}}', ['or',
                ['and', ['key' => 'doc'], ['like', 'value', "$siteId:%", false]],
                ['like', 'key', "term_sources:$siteId:%", false],
                ['like', 'key', "journal:$siteId:%", false],
                ['like', 'key', "ngram:$siteId:%", false],
            ])->execute();
            $this->clearNgrams($siteId);
            $this->mutationCheckpoint('bulkDeleted', $siteId, 0);
            $this->writeIndexTotals($count - $removedCount, $length - $removedLength);
            $this->writeIndexRecord('publication', $publication);
            $this->clearSearchMemo();

            return true;
        }));
    }
    private array $deferredIndexLocks = [];
    private ?string $memoTransactionSignature = null;
    private int $readMemoDepth = 0;

    private function withReadMemoScope(callable $callback): mixed
    {
        $outer = $this->readMemoDepth === 0;
        if ($outer && Craft::$app->getDb()->getTransaction()?->getIsActive() === true) {
            $this->clearSearchMemo();
        }
        $this->readMemoDepth++;
        try {
            return $callback();
        } finally {
            $this->readMemoDepth--;
            if ($outer && Craft::$app->getDb()->getTransaction()?->getIsActive() === true) {
                $this->clearSearchMemo();
            }
        }
    }

    public function searchElements(ElementQuery $elementQuery): array
    {
        return $this->withReadMemoScope(fn(): array => parent::searchElements($elementQuery));
    }

    public function createDbQuery(string|array|SearchQuery $searchQuery, ElementQuery $elementQuery): Query|false
    {
        return $this->withReadMemoScope(fn(): Query|false => parent::createDbQuery($searchQuery, $elementQuery));
    }

    protected function getSearchStatistics(): array
    {
        return $this->withReadMemoScope(fn(): array => parent::getSearchStatistics());
    }

    protected function synchronizeSearchMemo(): void
    {
        $transaction = Craft::$app->getDb()->getTransaction();
        $signature = $transaction === null ? 'none' : spl_object_id($transaction) . ':' . $transaction->getLevel();
        if ($signature !== $this->memoTransactionSignature || ($transaction !== null && $this->readMemoDepth === 0)) {
            $this->clearSearchMemo();
            $this->memoTransactionSignature = $signature;
        }
    }

    protected function withDocumentIndexLock(int $siteId, int $elementId, callable $callback): bool
    {
        return (bool)$this->withPublicationGuard($callback);
    }

    protected function createIndexReadCommand(Query $query): \yii\db\Command
    {
        $db = Craft::$app->getDb();
        $command = $query->createCommand($db);
        $ownsNamespace = $this->publicationDepth > 0 || isset($this->deferredIndexLocks['bs:publish:' . substr($this->getIndexIdentity(), 0, 32)]);
        if ($ownsNamespace && $db->getTransaction()?->getIsActive() === true) {
            return $db->createCommand($command->getSql() . ' FOR UPDATE', $command->params);
        }

        return $command;
    }

    protected function withPublicationGuard(callable $callback): mixed
    {
        $name = 'bs:publish:' . substr($this->getIndexIdentity(), 0, 32);
        if (!isset($this->deferredIndexLocks[$name])) {
            return parent::withPublicationGuard($callback);
        }
        $this->publicationDepth++;
        try {
            return $callback();
        } catch (\Throwable $exception) {
            if (isset($this->deferredIndexLocks[$name])) {
                $this->deferredIndexLocks[$name]['failure'] = $exception;
            }
            throw $exception;
        } finally {
            $this->publicationDepth--;
        }
    }

    protected function releaseIndexLock(\yii\mutex\Mutex $mutex, string $name, callable $onReleased, ?\Throwable $failure = null): void
    {
        $db = Craft::$app->getDb();
        if ($db->getTransaction()?->getIsActive() !== true) {
            parent::releaseIndexLock($mutex, $name, $onReleased, $failure);

            return;
        }
        $this->deferredIndexLocks[$name] = ['failure' => $failure];
        $release = null;
        $release = function() use ($db, $mutex, $name, $onReleased, &$release): void {
            $db->off(\yii\db\Connection::EVENT_COMMIT_TRANSACTION, $release);
            $db->off(\yii\db\Connection::EVENT_ROLLBACK_TRANSACTION, $release);
            $failure = $this->deferredIndexLocks[$name]['failure'];
            unset($this->deferredIndexLocks[$name]);
            parent::releaseIndexLock($mutex, $name, $onReleased, $failure);
            $this->clearSearchMemo();
        };
        $db->on(\yii\db\Connection::EVENT_COMMIT_TRANSACTION, $release);
        $db->on(\yii\db\Connection::EVENT_ROLLBACK_TRANSACTION, $release);
    }
    protected function removePostingChanges(int $siteId, int $elementId, array $terms): void
    {
        if ($terms !== []) {
            Craft::$app->getDb()->createCommand()->delete($this->tablePrefix . 'terms}}', ['docId' => "$siteId:$elementId", 'term' => $terms])->execute();
        }
    }

    protected function cleanupRemovedTermState(int $siteId, array $terms): void
    {
        if ($terms === []) {
            return;
        }
        $remaining = $this->createIndexReadCommand((new Query())->select(['term', 'docId'])->from($this->tablePrefix . 'terms}}')->where(['term' => $terms]))->queryAll();
        $siteTerms = [];
        foreach ($remaining as $row) {
            if (str_starts_with($row['docId'], "$siteId:")) {
                $siteTerms[$row['term']] = true;
            }
        }
        $removed = array_values(array_diff($terms, array_map('strval', array_keys($siteTerms))));
        if ($removed === []) {
            return;
        }
        $db = Craft::$app->getDb();
        foreach (['ngrams', 'ngram_index'] as $table) {
            $db->createCommand()->delete($this->tablePrefix . $table . '}}', ['siteId' => $siteId, 'term' => $removed])->execute();
        }
        $db->createCommand()->delete($this->tablePrefix . 'metadata}}', ['key' => array_map(static fn(string $term): string => "ngram:$siteId:$term", $removed)])->execute();
    }
    protected function getIndexedDocumentIds(): array
    {
        return $this->createIndexReadCommand((new Query())->select('value')->distinct()->from($this->tablePrefix . 'metadata}}')->where(['key' => 'doc']))->queryColumn();
    }
    protected function documentExists(int $siteId, int $elementId): bool
    {
        return $this->createIndexReadCommand((new Query())->select(new Expression('1'))->from($this->tablePrefix . 'documents}}')->where(['siteId' => $siteId, 'elementId' => $elementId, 'term' => '_length'])->limit(1))->queryScalar() !== false;
    }
    protected function getStorageNamespace(): array
    {
        $db = Craft::$app->getDb();

        return ['driver' => 'mysql', 'dsn' => preg_replace('/;(?:user(?:name)?|password)=[^;]*/i', '', $db->dsn), 'tables' => $db->getSchema()->getRawTableName($this->tablePrefix . 'metadata}}')];
    }

    protected function readIndexRecord(string $key): ?array
    {
        $value = $this->createIndexReadCommand((new Query())->select('value')->from($this->tablePrefix . 'metadata}}')->where(['key' => $key]))->queryScalar();

        return $value === false ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function readIndexRecordsBatch(array $keys): array
    {
        $rows = $this->createIndexReadCommand((new Query())->select(['key', 'value'])->from($this->tablePrefix . 'metadata}}')->where(['key' => $keys]))->queryAll();
        $records = array_fill_keys($keys, null);
        foreach ($rows as $row) {
            $records[$row['key']] = json_decode($row['value'], true, 512, JSON_THROW_ON_ERROR);
        }

        return $records;
    }

    protected function writeIndexRecord(string $key, array $value): void
    {
        $this->upsertSingletonMeta($key, json_encode($value, JSON_THROW_ON_ERROR));
    }

    protected function deleteIndexRecord(string $key): void
    {
        Craft::$app->getDb()->createCommand()->delete($this->tablePrefix . 'metadata}}', ['key' => $key])->execute();
    }

    protected function writeIndexTotals(int $documents, int $length): void
    {
        $this->upsertSingletonMeta('totalDocs', (string)$documents);
        $this->upsertSingletonMeta('totalLength', (string)$length);
    }

    protected function runDocumentTransaction(callable $callback): bool
    {
        try {
            return $this->withDeadlockRetry(fn(): bool => Craft::$app->getDb()->transaction($callback));
        } finally {
            $this->clearSearchMemo();
        }
    }

    protected function writeDocumentNgrams(int $siteId, array $terms, string $fingerprint): void
    {
        if ($terms === []) {
            return;
        }
        $terms = array_map('strval', $terms);
        $records = $this->readIndexRecordsBatch(array_map(static fn(string $term): string => "ngram:$siteId:$term", $terms));
        $counts = array_column($this->createIndexReadCommand((new Query())->select(['term', 'count' => new Expression('COUNT(DISTINCT [[ngram]])')])->from($this->tablePrefix . 'ngrams}}')->where(['siteId' => $siteId, 'term' => $terms])->groupBy('term'))->queryAll(), null, 'term');
        $changed = [];
        $ngramRows = [];
        $indexRows = [];
        $stateRows = [];
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        foreach ($terms as $term) {
            $ngrams = $this->generateNgrams($term);
            if (($records["ngram:$siteId:$term"]['fingerprint'] ?? null) === $fingerprint && (int)($counts[$term]['count'] ?? 0) === count($ngrams)) {
                continue;
            }
            $changed[] = $term;
            foreach ($ngrams as $ngram) {
                $ngramRows[] = [$ngram, $term, mb_strlen($ngram, 'UTF-8'), $siteId, $now, $now, StringHelper::UUID()];
            }
            $indexRows[] = [$term, count($ngrams), $siteId, $now, $now, StringHelper::UUID()];
            $stateRows[] = ["ngram:$siteId:$term", json_encode(['fingerprint' => $fingerprint], JSON_THROW_ON_ERROR), $now, $now, StringHelper::UUID()];
        }
        if ($changed === []) {
            return;
        }
        $db = Craft::$app->getDb();
        foreach (['ngrams', 'ngram_index'] as $table) {
            $db->createCommand()->delete($this->tablePrefix . $table . '}}', ['siteId' => $siteId, 'term' => $changed])->execute();
        }
        $db->createCommand()->delete($this->tablePrefix . 'metadata}}', ['key' => array_column($stateRows, 0)])->execute();
        foreach ([
            ['ngrams', ['ngram', 'term', 'ngram_type', 'siteId', 'dateCreated', 'dateUpdated', 'uid'], $ngramRows],
            ['ngram_index', ['term', 'ngram_count', 'siteId', 'dateCreated', 'dateUpdated', 'uid'], $indexRows],
            ['metadata', ['key', 'value', 'dateCreated', 'dateUpdated', 'uid'], $stateRows],
        ] as [$table, $columns, $rows]) {
            foreach (array_chunk($rows, 1000) as $chunk) {
                $db->createCommand()->batchInsert($this->tablePrefix . $table . '}}', $columns, $chunk)->execute();
            }
        }
    }

    protected function writePostingChanges(int $siteId, int $elementId, array $frequencies): void
    {
        if ($frequencies === []) {
            return;
        }
        $db = Craft::$app->getDb();
        $docId = "$siteId:$elementId";
        $db->createCommand()->delete($this->tablePrefix . 'terms}}', ['docId' => $docId, 'term' => array_map('strval', array_keys($frequencies))])->execute();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($frequencies as $term => $frequency) {
            $rows[] = [(string)$term, $docId, $frequency, $now, $now, StringHelper::UUID()];
        }
        $db->createCommand()->batchInsert($this->tablePrefix . 'terms}}', ['term', 'docId', 'frequency', 'dateCreated', 'dateUpdated', 'uid'], $rows)->execute();
    }
    // =========================================================================
    // PROPERTIES
    // =========================================================================

    /**
     * Table name prefix for all search tables
     */
    protected string $tablePrefix = '{{%bramble_search_';

    /**
     * When true, skip per-element updateTotalDocCount (bulk rebuild mode).
     */
    public bool $bulkMode = false;

    // =========================================================================
    // INITIALIZATION METHODS
    // =========================================================================

    /**
     * Initialize the MySQL connection
     */
    public function init(): void
    {
        parent::init();
    }

    // =========================================================================
    // CONCURRENCY HELPERS
    // =========================================================================

    /**
     * Execute a callback with automatic retry on MySQL deadlock (error 1213).
     *
     * @param callable $callback The operation to execute
     * @param int $maxRetries Maximum number of retry attempts
     * @return mixed The callback return value
     */
    private function withDeadlockRetry(callable $callback, int $maxRetries = 3): mixed
    {
        $attempts = 0;
        while (true) {
            try {
                return $callback();
            } catch (\yii\db\Exception $e) {
                if (str_contains($e->getMessage(), '1213') && $attempts < $maxRetries) {
                    $attempts++;
                    usleep(random_int(10000, 100000)); // 10–100 ms random back-off
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Atomically set a singleton metadata key (totalDocs, totalLength).
     * Uses UPDATE first (no gap-lock), falls back to INSERT on first run.
     *
     * @param string $key The metadata key
     * @param string $value The new value
     */
    private function upsertSingletonMeta(string $key, string $value): void
    {
        $this->withPublicationGuard(function() use ($key, $value): void {
            $db = Craft::$app->getDb();
            $table = $this->tablePrefix . 'metadata}}';
            $ids = $this->createIndexReadCommand((new Query())->select('id')->from($table)->where(['key' => $key])->orderBy(['id' => SORT_ASC]))->queryColumn();
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            if ($ids === []) {
                $db->createCommand()->insert($table, ['key' => $key, 'value' => $value, 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID()])->execute();

                return;
            }
            $keep = (int)array_shift($ids);
            if ($ids !== []) {
                $db->createCommand()->delete($table, ['id' => array_map('intval', $ids)])->execute();
            }
            $db->createCommand()->update($table, ['value' => $value, 'dateUpdated' => $now], ['id' => $keep])->execute();
        });
    }

    // =========================================================================
    // DOCUMENT OPERATIONS
    // =========================================================================

    /**
     * Get all terms for a document from MySQL
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return array The terms and their frequencies
     */
    protected function getDocumentTerms(int $siteId, int $elementId): array
    {
        $rows = $this->createIndexReadCommand((new Query())->select(['term', 'frequency'])->from($this->tablePrefix . 'documents}}')->where(['siteId' => $siteId, 'elementId' => $elementId]))->queryAll();
        $terms = array_map('intval', array_column($rows, 'frequency', 'term'));
        unset($terms['_length']);

        return $terms;
    }

    /**
     * Delete a document from MySQL
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function deleteDocument(int $siteId, int $elementId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete($this->tablePrefix . 'documents}}', [
                'siteId' => $siteId,
                'elementId' => $elementId,
            ])
            ->execute();
    }

    /**
     * Store a document in MySQL
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param array $termFreqs The terms and their frequencies
     * @param int $docLen The document length
     */
    protected function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLen): void
    {
        $db = Craft::$app->getDb();
        $batch = [];
        $now = new \DateTime();
        $dateTime = $now->format('Y-m-d H:i:s');

        foreach ($termFreqs as $term => $freq) {
            $batch[] = [
                'siteId' => $siteId,
                'elementId' => $elementId,
                'term' => $term,
                'frequency' => $freq,
                'dateCreated' => $dateTime,
                'dateUpdated' => $dateTime,
                'uid' => StringHelper::UUID(),
            ];
        }

        // Add document length as a special term
        $batch[] = [
            'siteId' => $siteId,
            'elementId' => $elementId,
            'term' => '_length',
            'frequency' => $docLen,
            'dateCreated' => $dateTime,
            'dateUpdated' => $dateTime,
            'uid' => StringHelper::UUID(),
        ];

        $db->createCommand()
            ->batchInsert(
                $this->tablePrefix . 'documents}}',
                ['siteId', 'elementId', 'term', 'frequency', 'dateCreated', 'dateUpdated', 'uid'],
                $batch
            )
            ->execute();
    }

    /**
     * Get the length of a document in tokens from MySQL
     *
     * @param string $docId The document ID (siteId:elementId)
     * @return int The document length
     */
    protected function getDocumentLength(string $docId): int
    {
        [$siteId, $elementId] = array_map('intval', explode(':', $docId, 2));
        $length = $this->createIndexReadCommand((new Query())->select('frequency')->from($this->tablePrefix . 'documents}}')->where(['siteId' => $siteId, 'elementId' => $elementId, 'term' => '_length']))->queryScalar();

        return $length === false ? 0 : (int)$length;
    }

    /**
     * Get document lengths for multiple documents in a single batch operation
     *
     * @param array $docIds Array of document IDs
     * @return array Associative array with docId => length
     */
    protected function getDocumentLengthsBatch(array $docIds): array
    {
        if (empty($docIds)) {
            return [];
        }

        $conditions = [];
        foreach ($docIds as $docId) {
            [$siteId, $elementId] = explode(':', $docId);
            $conditions[] = ['siteId' => $siteId, 'elementId' => $elementId];
        }

        $results = (new Query())
            ->select(['siteId', 'elementId', 'frequency'])
            ->from($this->tablePrefix . 'documents}}')
            ->where(['term' => '_length'])
            ->andWhere(['or', ...$conditions])
            ->all();

        $lengths = [];
        
        // Initialize all to 0 first
        foreach ($docIds as $docId) {
            $lengths[$docId] = 0;
        }
        
        // Fill in actual values
        foreach ($results as $row) {
            $docId = $row['siteId'] . ':' . $row['elementId'];
            $lengths[$docId] = is_numeric($row['frequency']) ? (int)$row['frequency'] : 0;
        }

        return $lengths;
    }

    // =========================================================================
    // METADATA OPERATIONS
    // =========================================================================

    /**
     * Add a document to the index metadata
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function addDocumentToIndex(int $siteId, int $elementId): void
    {
        $this->withPublicationGuard(function() use ($siteId, $elementId): void {
            $table = $this->tablePrefix . 'metadata}}';
            $docId = "$siteId:$elementId";
            if ($this->createIndexReadCommand((new Query())->select(new Expression('1'))->from($table)->where(['key' => 'doc', 'value' => $docId])->limit(1))->queryScalar() !== false) {
                return;
            }
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            Craft::$app->getDb()->createCommand()->insert($table, ['key' => 'doc', 'value' => $docId, 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID()])->execute();
        });
    }

    /**
     * Public wrapper for updateTotalDocCount, callable from queue jobs.
     */
    public function refreshTotalDocCount(): void
    {
        $this->refreshCommittedTotals();
    }

    /**
     * Recalculate totalLength from actual stored document lengths.
     * Called once after a bulk rebuild to ensure consistency.
     */
    public function refreshTotalLength(): void
    {
        $this->refreshCommittedTotals();
    }

    /**
     * Update the total document count in MySQL.
     * Uses UPDATE instead of DELETE+INSERT to avoid deadlocks under concurrency.
     */
    protected function updateTotalDocCount(): void
    {
        $this->refreshCommittedTotals();
    }

    /**
     * Update the total token length in MySQL.
     * Uses an atomic SQL increment to avoid both deadlocks and lost-update
     * race conditions when multiple indexing jobs run concurrently.
     *
     * @param int $docLen The document length to add
     */
    protected function updateTotalLength(int $docLen): void
    {
        $this->withPublicationGuard(function() use ($docLen): void {
            $this->repairIndexMetadata($this->publicationState());
            $this->upsertSingletonMeta('totalLength', (string)max(0, $this->getTotalLength() + $docLen));
        });
    }

    /**
     * Get the total document count from MySQL
     *
     * @return int The total document count
     */
    protected function getTotalDocCount(): int
    {
        $this->ensureIndexMetadata();
        $value = $this->createIndexReadCommand((new Query())->select('value')->from($this->tablePrefix . 'metadata}}')->where(['key' => 'totalDocs'])->limit(1))->queryScalar();

        return $this->requireMetadataCounter($value);
    }

    /**
     * Get the total token length from MySQL
     *
     * @return int The total token length
     */
    protected function getTotalLength(): int
    {
        $this->ensureIndexMetadata();
        $value = $this->createIndexReadCommand((new Query())->select('value')->from($this->tablePrefix . 'metadata}}')->where(['key' => 'totalLength'])->limit(1))->queryScalar();

        return $this->requireMetadataCounter($value);
    }

    // =========================================================================
    // TERM OPERATIONS
    // =========================================================================

    /**
     * Store a term-document association in MySQL
     *
     * @param string $term The term
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param int $freq The term frequency
     */
    protected function storeTermDocument(string $term, int $siteId, int $elementId, int $freq): void
    {
        $this->withPublicationGuard(function() use ($term, $siteId, $elementId, $freq): void {
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            Craft::$app->getDb()->createCommand()->upsert($this->tablePrefix . 'terms}}', [
                'term' => $term,
                'docId' => "$siteId:$elementId",
                'frequency' => $freq,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ], ['frequency' => $freq, 'dateUpdated' => $now])->execute();
        });
    }

    /**
     * Remove a term-document association from MySQL
     *
     * @param string $term The term
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function removeTermDocument(string $term, int $siteId, int $elementId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete($this->tablePrefix . 'terms}}', [
                'term' => $term,
                'docId' => "{$siteId}:{$elementId}",
            ])
            ->execute();
    }

    protected function getTermDocumentsBatch(array $terms): array
    {
        $terms = array_map('strval', $terms);
        $result = array_fill_keys($terms, []);
        if ($terms === []) {
            return $result;
        }
        foreach ($this->createIndexReadCommand((new Query())->select(['term', 'docId', 'frequency'])->from($this->tablePrefix . 'terms}}')->where(['term' => $terms]))->queryAll() as $row) {
            $result[$row['term']][$row['docId']] = (int)$row['frequency'];
        }

        return $result;
    }

    /**
     * Get all documents containing a specific term from MySQL
     *
     * @param string $term The term to look up
     * @return array The documents containing the term and their frequencies
     */
    protected function getTermDocuments(string $term): array
    {
        $results = (new Query())
            ->select(['docId', 'frequency'])
            ->from($this->tablePrefix . 'terms}}')
            ->where(['term' => $term])
            ->all();

        if (empty($results)) {
            return [];
        }

        $docs = [];
        foreach ($results as $result) {
            $docs[$result['docId']] = (int)$result['frequency'];
        }

        return $docs;
    }

    /**
     * Get all terms in the index from MySQL
     *
     * @return array All terms in the index
     */
    protected function getAllTerms(): array
    {
        $results = (new Query())
            ->select(['term'])
            ->distinct()
            ->from($this->tablePrefix . 'terms}}')
            ->column();

        return $results ?: [];
    }

    /**
     * Get indexed terms that start with a prefix from MySQL.
     *
     * @param string $prefix Prefix to match
     * @param int $siteId Active site ID
     * @param int $limit Maximum terms to return
     * @return array Matching terms keyed to confidence scores
     */
    protected function getTermsByPrefix(string $prefix, int $siteId, int $limit = 100): array
    {
        if ($prefix === '' || $limit <= 0) {
            return [];
        }
        $terms = (new Query())
            ->select(['term'])
            ->distinct()
            ->from($this->tablePrefix . 'terms}}')
            ->where([
                'AND',
                ['LIKE', 'term', $prefix . '%', false],
                ['LIKE', 'docId', "$siteId:%", false],
            ])
            ->orderBy(['term' => SORT_ASC])
            ->limit($limit)
            ->column();

        if (empty($terms)) {
            return [];
        }

        $matches = [];
        foreach ($terms as $term) {
            $term = (string)$term;
            $matches[$term] = $this->calculateTypeaheadConfidence($prefix, $term);
        }

        arsort($matches);

        return $matches;
    }

    /**
     * Remove a term from the index in MySQL
     *
     * @param string $term The term to remove
     */
    protected function removeTermFromIndex(string $term): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete($this->tablePrefix . 'terms}}', [
                'term' => $term,
            ])
            ->execute();
    }

    // =========================================================================
    // TITLE OPERATIONS
    // =========================================================================

    /**
     * Store title terms for a document in MySQL
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param array $titleTerms The title terms
     */
    protected function storeTitleTerms(int $siteId, int $elementId, array $titleTerms): void
    {
        $db = Craft::$app->getDb();
        $now = new \DateTime();
        $dateTime = $now->format('Y-m-d H:i:s');

        // Delete existing title terms
        $db->createCommand()
            ->delete($this->tablePrefix . 'titles}}', [
                'siteId' => $siteId,
                'elementId' => $elementId,
            ])
            ->execute();

        // Store new title terms
        if (!empty($titleTerms)) {
            $batch = [];
            foreach (array_keys($titleTerms) as $term) {
                $batch[] = [
                    'siteId' => $siteId,
                    'elementId' => $elementId,
                    'term' => $term,
                    'dateCreated' => $dateTime,
                    'dateUpdated' => $dateTime,
                    'uid' => StringHelper::UUID(),
                ];
            }

            $db->createCommand()
                ->batchInsert(
                    $this->tablePrefix . 'titles}}',
                    ['siteId', 'elementId', 'term', 'dateCreated', 'dateUpdated', 'uid'],
                    $batch
                )
                ->execute();
        }
    }

    /**
     * Get title terms for a document from MySQL
     *
     * @param string $docId The document ID (siteId:elementId)
     * @return array The title terms
     */
    protected function getTitleTerms(string $docId): array
    {
        [$siteId, $elementId] = explode(':', $docId);

        $terms = (new Query())
            ->select(['term'])
            ->from($this->tablePrefix . 'titles}}')
            ->where([
                'siteId' => $siteId,
                'elementId' => $elementId,
            ])
            ->column();

        if (empty($terms)) {
            return [];
        }

        return array_flip($terms); // Convert to associative array for faster lookups
    }

    /**
     * Delete title terms for a document from MySQL
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function deleteTitleTerms(int $siteId, int $elementId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete($this->tablePrefix . 'titles}}', [
                'siteId' => $siteId,
                'elementId' => $elementId,
            ])
            ->execute();
    }

    // =========================================================================
    // SITE OPERATIONS
    // =========================================================================

    /**
     * Get all documents for a specific site from MySQL
     *
     * @param int $siteId The site ID
     * @return array The document IDs
     */
    protected function getSiteDocuments(int $siteId): array
    {
        return $this->createIndexReadCommand((new Query())->select('value')->distinct()->from($this->tablePrefix . 'metadata}}')->where(['key' => 'doc'])->andWhere(['like', 'value', "$siteId:%", false]))->queryColumn();
    }

    /**
     * Remove a document from the index metadata in MySQL
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function removeDocumentFromIndex(int $siteId, int $elementId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete($this->tablePrefix . 'metadata}}', [
                'key' => 'doc',
                'value' => "{$siteId}:{$elementId}",
            ])
            ->execute();
    }

    /**
     * Reset the total length counter to zero in MySQL.
     * Uses UPDATE instead of DELETE+INSERT to avoid deadlocks.
     */
    protected function resetTotalLength(): void
    {
        $this->upsertSingletonMeta('totalLength', '0');
    }

    /**
     * Remove stale indexed documents for a site using bulk SQL operations.
     *
     * @param int $siteId The site ID to prune
     * @param array<int> $activeElementIds Element IDs that should remain indexed
     * @return bool Whether the operation was successful
     */
    public function pruneIndexForSite(int $siteId, array $activeElementIds, ?callable $progress = null): bool
    {
        return parent::pruneIndexForSite($siteId, $activeElementIds, $progress);
    }

    // =========================================================================
    // N-GRAM OPERATIONS
    // =========================================================================

    /**
     * Store n-grams for a term in MySQL
     *
     * @param string $term The term to store n-grams for
     * @param array $ngrams Array of n-grams for the term
     * @param int $siteId The site ID
     * @return void
     */
    protected function storeTermNgrams(string $term, array $ngrams, int $siteId): void
    {
        $db = Craft::$app->getDb();
        $now = new \DateTime();
        $dateTime = $now->format('Y-m-d H:i:s');

        // First, delete any existing n-grams for this term
        $this->removeTermNgrams($term, $siteId);

        if (empty($ngrams)) {
            return;
        }

        // Prepare batch data for n-grams
        $batch = [];
        foreach ($ngrams as $ngram) {
            $batch[] = [
                'ngram' => $ngram,
                'term' => $term,
                'ngram_type' => mb_strlen($ngram, 'UTF-8'),
                'siteId' => $siteId,
                'dateCreated' => $dateTime,
                'dateUpdated' => $dateTime,
                'uid' => StringHelper::UUID(),
            ];
        }

        // Batch insert n-grams
        $db->createCommand()
            ->batchInsert(
                $this->tablePrefix . 'ngrams}}',
                ['ngram', 'term', 'ngram_type', 'siteId', 'dateCreated', 'dateUpdated', 'uid'],
                $batch
            )
            ->execute();

        // Update or insert n-gram count
        $db->createCommand()
            ->upsert(
                $this->tablePrefix . 'ngram_index}}',
                [
                    'term' => $term,
                    'siteId' => $siteId,
                    'ngram_count' => count($ngrams),
                    'dateCreated' => $dateTime,
                    'dateUpdated' => $dateTime,
                    'uid' => StringHelper::UUID(),
                ],
                [
                    'ngram_count' => count($ngrams),
                    'dateUpdated' => $dateTime,
                ]
            )
            ->execute();
    }

    /**
     * Get terms that have similar n-grams to the search term
     *
     * @param array $ngrams N-grams of the search term
     * @param int $siteId The site ID
     * @param float $threshold Minimum similarity threshold (0.0 - 1.0)
     * @return array Array of [term => similarity_score]
     */
    protected function getTermsByNgramSimilarity(array $ngrams, int $siteId, float $threshold): array
    {
        if (empty($ngrams)) {
            return [];
        }

        $searchCount = count($ngrams);

        // Use SQL to find terms with overlapping n-grams and calculate Jaccard similarity
        $query = (new Query())
            ->select([
                'n.term',
                'COUNT(DISTINCT n.ngram) as match_count',
                'i.ngram_count',
                '(COUNT(DISTINCT n.ngram) / (i.ngram_count + :searchCount - COUNT(DISTINCT n.ngram))) as jaccard_similarity',
            ])
            ->from($this->tablePrefix . 'ngrams}} n')
            ->innerJoin($this->tablePrefix . 'ngram_index}} i', 'n.term = i.term AND n.siteId = i.siteId')
            ->where(['n.ngram' => $ngrams, 'n.siteId' => $siteId])
            ->groupBy(['n.term', 'i.ngram_count'])
            ->having('jaccard_similarity >= :threshold')
            ->orderBy(['match_count' => SORT_DESC, 'jaccard_similarity' => SORT_DESC])
            ->limit(max(100, $this->fuzzySearchMaxCandidates * 5))
            ->params([
                ':searchCount' => $searchCount,
                ':threshold' => $threshold,
            ]);

        $results = $query->all();
        $termSimilarities = [];

        foreach ($results as $result) {
            $termSimilarities[$result['term']] = (float)$result['jaccard_similarity'];
        }

        return $termSimilarities;
    }

    /**
     * Check if a term already has n-grams stored
     *
     * @param string $term The term to check
     * @param int $siteId The site ID
     * @return bool Whether the term has n-grams
     */
    protected function termHasNgrams(string $term, int $siteId): bool
    {
        $result = (new Query())
            ->select(['id'])
            ->from($this->tablePrefix . 'ngram_index}}')
            ->where(['term' => $term, 'siteId' => $siteId])
            ->exists();

        return (bool)$result;
    }

    /**
     * Check whether a term's stored n-gram count matches the currently configured ngramSizes.
     *
     * The ngram_index table already stores the count from when the term was indexed, so this
     * is a single cheap scalar query rather than regenerating and diffing the full n-gram set.
     *
     * @param string $term The term to check
     * @param int $siteId The site ID
     * @return bool Whether the term's stored n-gram count matches current-size generation
     */
    protected function termNgramsCurrent(string $term, int $siteId): bool
    {
        $storedCount = (new Query())
            ->select(['ngram_count'])
            ->from($this->tablePrefix . 'ngram_index}}')
            ->where(['term' => $term, 'siteId' => $siteId])
            ->scalar();

        return $storedCount !== false && (int)$storedCount === count($this->generateNgrams($term));
    }

    /**
     * Clear all n-grams for a site
     *
     * @param int $siteId The site ID
     * @return void
     */
    protected function clearNgrams(int $siteId): void
    {
        $db = Craft::$app->getDb();

        // Delete all n-grams for this site
        $db->createCommand()
            ->delete($this->tablePrefix . 'ngrams}}', [
                'siteId' => $siteId,
            ])
            ->execute();

        // Delete all n-gram index entries for this site
        $db->createCommand()
            ->delete($this->tablePrefix . 'ngram_index}}', [
                'siteId' => $siteId,
            ])
            ->execute();
    }

    /**
     * Remove n-grams for a specific term
     *
     * @param string $term The term to remove n-grams for
     * @param int $siteId The site ID
     * @return void
     */
    protected function removeTermNgrams(string $term, int $siteId): void
    {
        $db = Craft::$app->getDb();

        // Delete n-grams for this term
        $db->createCommand()
            ->delete($this->tablePrefix . 'ngrams}}', [
                'term' => $term,
                'siteId' => $siteId,
            ])
            ->execute();

        // Delete n-gram index entry for this term
        $db->createCommand()
            ->delete($this->tablePrefix . 'ngram_index}}', [
                'term' => $term,
                'siteId' => $siteId,
            ])
            ->execute();
    }

    // =========================================================================
    // OPTIMIZED ELEMENT INDEXING (BULK OVERRIDE)
    // =========================================================================

    /**
     * Index element attributes using batch SQL operations.
     *
     * Overrides the base implementation which executes one INSERT per term
     * and one COUNT(*) per element, causing extreme slowness on large indexes.
     *
     * @param ElementInterface $element The element to index
     * @param array|null $fieldHandles The field handles to index
     * @return bool Whether the indexing was successful
     */
    protected function indexElementAttributesUnlocked(ElementInterface $element, array|null $fieldHandles = null): bool
    {
        return $this->mutateDocument($element, $fieldHandles);
    }

    // =========================================================================
    // INDEX CLEARING (BULK OVERRIDE)
    // =========================================================================

    /**
     * Clear the search index for a specific site using bulk SQL operations.
     *
     * Overrides the base implementation which deletes documents one by one,
     * causing timeouts on large indexes (250K+ documents).
     *
     * @param int $siteId The site ID
     * @return bool Whether the operation was successful
     */
    public function clearIndex(int $siteId): bool
    {
        try {
            return $this->deleteSiteSnapshot($siteId);
        } catch (\Throwable $exception) {
            Craft::error('Unable to clear the search index: ' . $exception->getMessage(), __METHOD__);

            return false;
        }
    }

    /**
     * @param array<string, list<string>> $termSources
     */
    protected function storeDocumentTermSources(int $siteId, int $elementId, array $termSources): void
    {
        $key = "term_sources:$siteId:$elementId";
        Craft::$app->getDb()->createCommand()
            ->delete($this->tablePrefix . 'metadata}}', ['key' => $key])
            ->execute();

        if ($termSources === []) {
            return;
        }

        $now = (new \DateTime())->format('Y-m-d H:i:s');
        Craft::$app->getDb()->createCommand()
            ->insert($this->tablePrefix . 'metadata}}', [
                'key' => $key,
                'value' => json_encode($termSources, JSON_THROW_ON_ERROR),
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])
            ->execute();
    }

    /**
     * @return array<string, list<string>>
     */
    protected function getDocumentTermSources(int $siteId, int $elementId): array
    {
        return parent::getDocumentTermSources($siteId, $elementId);
    }

    protected function deleteDocumentTermSources(int $siteId, int $elementId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete($this->tablePrefix . 'metadata}}', [
                'key' => "term_sources:$siteId:$elementId",
            ])
            ->execute();
    }

    protected function deleteOrphanedIndexesFromAdapter(): void
    {
        $db = Craft::$app->getDb();
        $metadataTable = $this->tablePrefix . 'metadata}}';
        $elementsSitesTable = '{{%elements_sites}}';

        $docIds = (new Query())
            ->select(['value'])
            ->from($metadataTable)
            ->where(['key' => 'doc'])
            ->column();

        foreach ($docIds as $docId) {
            if (!is_string($docId) || !str_contains($docId, ':')) {
                continue;
            }

            [$siteId, $elementId] = explode(':', $docId, 2);
            $exists = (new Query())
                ->from($elementsSitesTable)
                ->where(['elementId' => (int)$elementId, 'siteId' => (int)$siteId])
                ->exists($db);

            if (!$exists) {
                $this->deleteElementFromIndex((int)$elementId, (int)$siteId);
            }
        }
    }
}
