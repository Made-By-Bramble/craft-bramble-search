<?php

namespace MadeByBramble\BrambleSearch\adapters;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
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
        $db = Craft::$app->getDb();
        $table = $this->tablePrefix . 'metadata}}';
        $now = new \DateTime();
        $dateTime = $now->format('Y-m-d H:i:s');
        $mutex = Craft::$app->getMutex();
        $mutexName = "bramble-search:metadata:$key";

        if (!$mutex->acquire($mutexName, 5)) {
            throw new \RuntimeException("Unable to acquire Bramble Search metadata lock for $key.");
        }

        try {
            $this->withDeadlockRetry(function() use ($db, $table, $key, $value, $dateTime) {
                $db->transaction(function() use ($db, $table, $key, $value, $dateTime) {
                    $ids = (new Query())
                        ->select(['id'])
                        ->from($table)
                        ->where(['key' => $key])
                        ->orderBy(['id' => SORT_ASC])
                        ->column($db);

                    if (empty($ids)) {
                        $db->createCommand()
                            ->insert($table, [
                                'key' => $key,
                                'value' => $value,
                                'dateCreated' => $dateTime,
                                'dateUpdated' => $dateTime,
                                'uid' => StringHelper::UUID(),
                            ])
                            ->execute();
                        return;
                    }

                    $keepId = (int)array_shift($ids);
                    if (!empty($ids)) {
                        $db->createCommand()
                            ->delete($table, ['id' => array_map('intval', $ids)])
                            ->execute();
                    }

                    $db->createCommand()
                        ->update($table, [
                            'value' => $value,
                            'dateUpdated' => $dateTime,
                        ], [
                            'id' => $keepId,
                        ])
                        ->execute();
                });
            });
        } finally {
            $mutex->release($mutexName);
        }
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
        $terms = (new Query())
            ->select(['term', 'frequency'])
            ->from($this->tablePrefix . 'documents}}')
            ->where(['siteId' => $siteId, 'elementId' => $elementId])
            ->all();

        if (empty($terms)) {
            return [];
        }

        $result = [];
        foreach ($terms as $term) {
            $result[$term['term']] = (int)$term['frequency'];
        }

        // Remove the _length key which isn't a term
        if (isset($result['_length'])) {
            unset($result['_length']);
        }

        return $result;
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
        [$siteId, $elementId] = explode(':', $docId);

        $result = (new Query())
            ->select(['frequency'])
            ->from($this->tablePrefix . 'documents}}')
            ->where([
                'siteId' => $siteId,
                'elementId' => $elementId,
                'term' => '_length',
            ])
            ->scalar();

        // Handle false return
        if ($result === false || !is_numeric($result)) {
            return 0;
        }

        return (int)$result;
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
        $now = new \DateTime();
        $dateTime = $now->format('Y-m-d H:i:s');
        $docId = "{$siteId}:{$elementId}";

        $this->withDeadlockRetry(function() use ($docId, $dateTime) {
            $db = Craft::$app->getDb();
            $db->transaction(function() use ($db, $docId, $dateTime) {
                $db->createCommand()
                    ->delete($this->tablePrefix . 'metadata}}', [
                        'key' => 'doc',
                        'value' => $docId,
                    ])
                    ->execute();

                $db->createCommand()
                    ->insert($this->tablePrefix . 'metadata}}', [
                        'key' => 'doc',
                        'value' => $docId,
                        'dateCreated' => $dateTime,
                        'dateUpdated' => $dateTime,
                        'uid' => StringHelper::UUID(),
                    ])
                    ->execute();
            });
        });
    }

    /**
     * Public wrapper for updateTotalDocCount, callable from queue jobs.
     */
    public function refreshTotalDocCount(): void
    {
        $this->updateTotalDocCount();
    }

    /**
     * Recalculate totalLength from actual stored document lengths.
     * Called once after a bulk rebuild to ensure consistency.
     */
    public function refreshTotalLength(): void
    {
        $result = (new Query())
            ->from($this->tablePrefix . 'documents}}')
            ->where(['term' => '_length'])
            ->sum('frequency');

        $totalLength = $result ? (int)$result : 0;
        $this->upsertSingletonMeta('totalLength', (string)$totalLength);
    }

    /**
     * Update the total document count in MySQL.
     * Uses UPDATE instead of DELETE+INSERT to avoid deadlocks under concurrency.
     */
    protected function updateTotalDocCount(): void
    {
        $count = (new Query())
            ->select(new Expression('COUNT(DISTINCT [[value]])'))
            ->from($this->tablePrefix . 'metadata}}')
            ->where(['key' => 'doc'])
            ->scalar();

        $this->upsertSingletonMeta('totalDocs', (string)((int)$count));
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
        $db = Craft::$app->getDb();
        $table = $this->tablePrefix . 'metadata}}';
        $now = new \DateTime();
        $dateTime = $now->format('Y-m-d H:i:s');
        $mutex = Craft::$app->getMutex();
        $mutexName = 'bramble-search:metadata:totalLength';

        if (!$mutex->acquire($mutexName, 5)) {
            throw new \RuntimeException('Unable to acquire Bramble Search metadata lock for totalLength.');
        }

        try {
            $this->withDeadlockRetry(function() use ($db, $table, $docLen, $dateTime) {
                $db->transaction(function() use ($db, $table, $docLen, $dateTime) {
                    $rows = (new Query())
                        ->select(['id', 'value'])
                        ->from($table)
                        ->where(['key' => 'totalLength'])
                        ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])
                        ->all($db);

                    if (empty($rows)) {
                        $db->createCommand()
                            ->insert($table, [
                                'key' => 'totalLength',
                                'value' => (string)max(0, $docLen),
                                'dateCreated' => $dateTime,
                                'dateUpdated' => $dateTime,
                                'uid' => StringHelper::UUID(),
                            ])
                            ->execute();
                        return;
                    }

                    $keep = array_shift($rows);
                    if (!empty($rows)) {
                        $db->createCommand()
                            ->delete($table, ['id' => array_map(fn(array $row): int => (int)$row['id'], $rows)])
                            ->execute();
                    }

                    $totalLength = max(0, (int)$keep['value'] + $docLen);
                    $db->createCommand()
                        ->update($table, [
                            'value' => (string)$totalLength,
                            'dateUpdated' => $dateTime,
                        ], [
                            'id' => (int)$keep['id'],
                        ])
                        ->execute();
                });
            });
        } finally {
            $mutex->release($mutexName);
        }
    }

    /**
     * Get the total document count from MySQL
     *
     * @return int The total document count
     */
    protected function getTotalDocCount(): int
    {
        $result = (new Query())
            ->select(['value'])
            ->from($this->tablePrefix . 'metadata}}')
            ->where(['key' => 'totalDocs'])
            ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])
            ->scalar();

        // Handle false return
        if ($result === false || !is_numeric($result)) {
            return 0;
        }

        return (int)$result;
    }

    /**
     * Get the total token length from MySQL
     *
     * @return int The total token length
     */
    protected function getTotalLength(): int
    {
        $result = (new Query())
            ->select(['value'])
            ->from($this->tablePrefix . 'metadata}}')
            ->where(['key' => 'totalLength'])
            ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])
            ->scalar();

        // Handle false return
        if ($result === false || !is_numeric($result)) {
            return 0;
        }

        return (int)$result;
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
        $now = new \DateTime();
        $dateTime = $now->format('Y-m-d H:i:s');

        Craft::$app->getDb()->createCommand()
            ->insert($this->tablePrefix . 'terms}}', [
                'term' => $term,
                'docId' => "{$siteId}:{$elementId}",
                'frequency' => $freq,
                'dateCreated' => $dateTime,
                'dateUpdated' => $dateTime,
                'uid' => StringHelper::UUID(),
            ])
            ->execute();
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
        $results = (new Query())
            ->select(['value'])
            ->distinct()
            ->from($this->tablePrefix . 'metadata}}')
            ->where([
                'AND',
                ['key' => 'doc'],
                ['LIKE', 'value', "$siteId:%", false],
            ])
            ->column();

        if (empty($results)) {
            return [];
        }

        return array_values(array_map('strval', $results));
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
    public function pruneIndexForSite(int $siteId, array $activeElementIds): bool
    {
        try {
            $activeDocIds = [];
            foreach ($activeElementIds as $elementId) {
                $elementId = (int)$elementId;
                if ($elementId > 0) {
                    $activeDocIds["$siteId:$elementId"] = true;
                }
            }

            $staleDocIds = array_values(array_diff($this->getSiteDocuments($siteId), array_keys($activeDocIds)));
            if (empty($staleDocIds)) {
                $this->updateTotalDocCount();
                Craft::info("Search index pruned for site ID: $siteId; removed 0 stale documents", __METHOD__);
                return true;
            }

            $db = Craft::$app->getDb();
            $staleElementIds = array_values(array_unique(array_map(
                fn(string $docId): int => (int)explode(':', $docId, 2)[1],
                $staleDocIds
            )));

            $totalLength = (new Query())
                ->from($this->tablePrefix . 'documents}}')
                ->where([
                    'siteId' => $siteId,
                    'elementId' => $staleElementIds,
                    'term' => '_length',
                ])
                ->sum('frequency');

            foreach (array_chunk($staleDocIds, 500) as $docIdChunk) {
                $db->createCommand()
                    ->delete($this->tablePrefix . 'terms}}', ['docId' => $docIdChunk])
                    ->execute();

                $db->createCommand()
                    ->delete($this->tablePrefix . 'metadata}}', [
                        'key' => 'doc',
                        'value' => $docIdChunk,
                    ])
                    ->execute();
            }

            foreach (array_chunk($staleElementIds, 500) as $elementIdChunk) {
                $db->createCommand()
                    ->delete($this->tablePrefix . 'documents}}', [
                        'siteId' => $siteId,
                        'elementId' => $elementIdChunk,
                    ])
                    ->execute();

                $db->createCommand()
                    ->delete($this->tablePrefix . 'titles}}', [
                        'siteId' => $siteId,
                        'elementId' => $elementIdChunk,
                    ])
                    ->execute();
            }

            if ($totalLength) {
                $this->updateTotalLength(-(int)$totalLength);
            }

            $this->updateTotalDocCount();

            Craft::info(
                sprintf(
                    'Search index pruned for site ID: %d; removed %d stale document%s',
                    $siteId,
                    count($staleDocIds),
                    count($staleDocIds) === 1 ? '' : 's'
                ),
                __METHOD__
            );

            return true;
        } catch (\Throwable $e) {
            Craft::error("Error pruning search index: " . $e->getMessage(), __METHOD__);
            return false;
        }
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
        if (!$element->id || !$element->siteId) {
            return true;
        }

        if (($element->dateDeleted ?? null) !== null || !$element->enabled || !$element->getEnabledForSite()) {
            return $this->removeElementFromIndexAndUpdateMetadata($element);
        }

        if (ElementHelper::isDraftOrRevision($element)) {
            return $this->removeElementFromIndexAndUpdateMetadata($element);
        }

        if (property_exists($element, 'isProvisionalDraft') && $element->isProvisionalDraft) {
            return $this->removeElementFromIndexAndUpdateMetadata($element);
        }

        $elementType = get_class($element);
        if ($elementType::hasTitles() && empty($element->title)) {
            return $this->removeElementFromIndexAndUpdateMetadata($element);
        }

        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $title = $element->title ?? '';
        $titleTokens = $this->tokenize($title);
        $titleTerms = array_flip($titleTokens);

        $fieldHandles = $this->resolveFieldHandlesForIndexing($element, $fieldHandles);
        $attributesOnly = $fieldHandles === [];
        $termSources = [];

        $text = '';
        foreach (ElementHelper::searchableAttributes($element) as $attribute) {
            $value = $this->normalizeIndexKeywords($element, $element->getSearchKeywords($attribute), $attribute);
            if (!empty($value)) {
                $text .= ' ' . $value;
                foreach ($this->tokenize($value) as $sourceTerm) {
                    $termSources[$sourceTerm][] = "attr:$attribute";
                }
            }
        }

        if (!$attributesOnly) {
            foreach ($fieldHandles as $handle) {
                $fieldLayout = $element->getFieldLayout();
                $field = $fieldLayout?->getFieldByHandle($handle);
                if (!$field || !$field->searchable) {
                    continue;
                }

                $fieldValue = $element->getFieldValue($handle);
                if ($fieldValue) {
                    $keywords = $this->normalizeIndexKeywords(
                        $element,
                        $field->getSearchKeywords($fieldValue, $element),
                        null,
                        (int)$field->id
                    );
                    if (!empty($keywords)) {
                        $text .= ' ' . $keywords;
                        foreach ($this->tokenize($keywords) as $sourceTerm) {
                            $termSources[$sourceTerm][] = "field:$handle";
                        }
                    }
                }
            }
        } else {
            $termSources = $this->mergeTermSourcesWithExistingFieldTerms($element->siteId, $element->id, $termSources);
            $text .= $this->getExistingFieldTextFromIndex($element->siteId, $element->id);
        }

        $termFreqs = $this->buildIndexedTermFrequencies($text, $titleTokens);
        $docLen = array_sum($termFreqs);

        $siteId = $element->siteId;
        $elementId = $element->id;
        $oldDocLength = $this->getDocumentLength("$siteId:$elementId");

        $db->createCommand()->delete($this->tablePrefix . 'terms}}', [
            'docId' => "$siteId:$elementId",
        ])->execute();
        $this->deleteDocument($siteId, $elementId);
        $this->deleteTitleTerms($siteId, $elementId);
        $this->removeDocumentFromIndex($siteId, $elementId);

        $this->storeDocument($siteId, $elementId, $termFreqs, $docLen);
        $this->storeTitleTerms($siteId, $elementId, $titleTerms);
        $this->storeDocumentTermSources($siteId, $elementId, $termSources);

        if (!empty($termFreqs)) {
            $termBatch = [];
            foreach ($termFreqs as $term => $freq) {
                $termBatch[] = [
                    'term' => $term,
                    'docId' => "$siteId:$elementId",
                    'frequency' => $freq,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ];
            }
            $db->createCommand()->batchInsert(
                $this->tablePrefix . 'terms}}',
                ['term', 'docId', 'frequency', 'dateCreated', 'dateUpdated', 'uid'],
                $termBatch
            )->execute();
        }

        $ngramBatch = [];
        $ngramIndexBatch = [];
        foreach (array_keys($termFreqs) as $term) {
            // Same stale-n-gram guard as the base indexElementAttributesUnlocked(): existence
            // alone isn't enough after an ngramSizes setting change.
            if (!$this->termNgramsCurrent($term, $siteId)) {
                $ngrams = $this->generateNgrams($term);
                if (!empty($ngrams)) {
                    foreach ($ngrams as $ngram) {
                        $ngramBatch[] = [
                            'ngram' => $ngram,
                            'term' => $term,
                            'ngram_type' => mb_strlen($ngram, 'UTF-8'),
                            'siteId' => $siteId,
                            'dateCreated' => $now,
                            'dateUpdated' => $now,
                            'uid' => StringHelper::UUID(),
                        ];
                    }
                    $ngramIndexBatch[] = [
                        'term' => $term,
                        'ngram_count' => count($ngrams),
                        'siteId' => $siteId,
                        'dateCreated' => $now,
                        'dateUpdated' => $now,
                        'uid' => StringHelper::UUID(),
                    ];
                }
            }
        }
        if (!empty($ngramBatch)) {
            $db->createCommand()->batchInsert(
                $this->tablePrefix . 'ngrams}}',
                ['ngram', 'term', 'ngram_type', 'siteId', 'dateCreated', 'dateUpdated', 'uid'],
                $ngramBatch
            )->execute();
        }
        if (!empty($ngramIndexBatch)) {
            foreach ($ngramIndexBatch as $row) {
                $db->createCommand()->upsert(
                    $this->tablePrefix . 'ngram_index}}',
                    $row,
                    ['ngram_count' => $row['ngram_count'], 'dateUpdated' => $now]
                )->execute();
            }
        }

        $this->addDocumentToIndex($siteId, $elementId);
        $this->updateTotalLength($docLen - $oldDocLength);

        if (!$this->bulkMode) {
            $this->updateTotalDocCount();
        }

        return true;
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
            $db = Craft::$app->getDb();

            // Bulk delete all documents for this site
            $db->createCommand()
                ->delete($this->tablePrefix . 'documents}}', ['siteId' => $siteId])
                ->execute();

            // Bulk delete all titles for this site
            $db->createCommand()
                ->delete($this->tablePrefix . 'titles}}', ['siteId' => $siteId])
                ->execute();

            // Bulk delete all terms whose docId starts with this siteId
            $db->createCommand()
                ->delete($this->tablePrefix . 'terms}}', [
                    'LIKE', 'docId', "$siteId:%", false,
                ])
                ->execute();

            // Bulk delete metadata doc entries for this site
            $db->createCommand()
                ->delete($this->tablePrefix . 'metadata}}', [
                    'AND',
                    ['key' => 'doc'],
                    ['LIKE', 'value', "$siteId:%", false],
                ])
                ->execute();

            // Clear n-grams for this site
            $this->clearNgrams($siteId);

            // Refresh totals across all remaining sites
            $this->refreshTotalLength();
            $this->updateTotalDocCount();

            Craft::info("Search index cleared (bulk) for site ID: $siteId", __METHOD__);
            return true;
        } catch (\Throwable $e) {
            Craft::error("Error clearing search index: " . $e->getMessage(), __METHOD__);
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
        $value = (new Query())
            ->select(['value'])
            ->from($this->tablePrefix . 'metadata}}')
            ->where(['key' => "term_sources:$siteId:$elementId"])
            ->scalar();

        if (!$value) {
            return [];
        }

        return json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR) ?: [];
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
