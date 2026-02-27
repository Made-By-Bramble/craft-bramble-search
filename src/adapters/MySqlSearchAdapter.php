<?php

namespace MadeByBramble\BrambleSearch\adapters;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use yii\db\Expression;
use yii\log\Logger;

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

        $this->withDeadlockRetry(function () use ($db, $table, $key, $value, $dateTime) {
            $affected = $db->createCommand()
                ->update($table, [
                    'value' => $value,
                    'dateUpdated' => $dateTime,
                ], [
                    'key' => $key,
                ])
                ->execute();

            // Row does not exist yet (first indexing run) — insert it
            if ($affected === 0) {
                $db->createCommand()
                    ->insert($table, [
                        'key' => $key,
                        'value' => $value,
                        'dateCreated' => $dateTime,
                        'dateUpdated' => $dateTime,
                        'uid' => StringHelper::UUID(),
                    ])
                    ->execute();
            }
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

        Craft::$app->getDb()->createCommand()
            ->insert($this->tablePrefix . 'metadata}}', [
                'key' => 'doc',
                'value' => "{$siteId}:{$elementId}",
                'dateCreated' => $dateTime,
                'dateUpdated' => $dateTime,
                'uid' => StringHelper::UUID(),
            ])
            ->execute();
    }

    /**
     * Public wrapper for updateTotalDocCount, callable from queue jobs.
     */
    public function refreshTotalDocCount(): void
    {
        $this->updateTotalDocCount();
    }

    /**
     * Update the total document count in MySQL.
     * Uses UPDATE instead of DELETE+INSERT to avoid deadlocks under concurrency.
     */
    protected function updateTotalDocCount(): void
    {
        $count = (new Query())
            ->from($this->tablePrefix . 'metadata}}')
            ->where(['key' => 'doc'])
            ->count();

        $this->upsertSingletonMeta('totalDocs', (string)$count);
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

        $this->withDeadlockRetry(function () use ($db, $table, $docLen, $dateTime) {
            // Atomic increment — no read-then-write race condition
            $affected = $db->createCommand()
                ->update($table, [
                    'value' => new Expression('CAST([[value]] AS SIGNED) + :docLen', [':docLen' => $docLen]),
                    'dateUpdated' => $dateTime,
                ], [
                    'key' => 'totalLength',
                ])
                ->execute();

            // Row does not exist yet (first indexing run) — insert it
            if ($affected === 0) {
                $db->createCommand()
                    ->insert($table, [
                        'key' => 'totalLength',
                        'value' => (string)$docLen,
                        'dateCreated' => $dateTime,
                        'dateUpdated' => $dateTime,
                        'uid' => StringHelper::UUID(),
                    ])
                    ->execute();
            }
        });
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
            ->from($this->tablePrefix . 'metadata}}')
            ->where(['key' => 'doc'])
            ->column();

        if (empty($results)) {
            return [];
        }

        // Filter documents by site ID
        $sitePrefix = "$siteId:";
        $siteDocs = [];

        foreach ($results as $docId) {
            if (strpos($docId, $sitePrefix) === 0) {
                $siteDocs[] = $docId;
            }
        }

        return $siteDocs;
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
            ->limit(100)
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
    public function indexElementAttributes(ElementInterface $element, array|null $fieldHandles = null): bool
    {
        if (!$element->id || !$element->siteId || !$element->enabled) {
            return true;
        }

        if (ElementHelper::isDraftOrRevision($element)) {
            return true;
        }

        if (property_exists($element, 'isProvisionalDraft') && $element->isProvisionalDraft) {
            return true;
        }

        $elementType = get_class($element);
        if ($elementType::hasTitles() && empty($element->title)) {
            return true;
        }

        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        // --- Tokenize title ---
        $title = $element->title ?? '';
        $titleTokens = $this->tokenize($title);
        $titleTokens = $this->filterStopWords($titleTokens);
        $titleTerms = array_flip($titleTokens);

        // --- Build full text from searchable attributes + fields ---
        $text = '';
        foreach (ElementHelper::searchableAttributes($element) as $attribute) {
            $value = $element->getSearchKeywords($attribute);
            if (!empty($value)) {
                $text .= ' ' . $value;
            }
        }

        if ($fieldHandles !== null) {
            foreach ($fieldHandles as $handle) {
                $fieldValue = $element->getFieldValue($handle);
                if ($fieldValue && $element->getFieldLayout()) {
                    $field = $element->getFieldLayout()->getFieldByHandle($handle);
                    if ($field && $field->searchable) {
                        $keywords = $field->getSearchKeywords($fieldValue, $element);
                        if (!empty($keywords)) {
                            $text .= ' ' . $keywords;
                        }
                    }
                }
            }
        }

        $tokens = $this->tokenize($text);
        $tokens = $this->filterStopWords($tokens);
        $termFreqs = array_count_values($tokens);
        $docLen = count($tokens);

        $siteId = $element->siteId;
        $elementId = $element->id;

        // --- Clean up old data (bulk deletes) ---
        $db->createCommand()->delete($this->tablePrefix . 'terms}}', [
            'docId' => "$siteId:$elementId",
        ])->execute();
        $this->deleteDocument($siteId, $elementId);
        $this->deleteTitleTerms($siteId, $elementId);

        // --- Store document ---
        $this->storeDocument($siteId, $elementId, $termFreqs, $docLen);

        // --- Store title terms ---
        $this->storeTitleTerms($siteId, $elementId, $titleTerms);

        // --- Batch insert all term-document associations ---
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

        // --- Batch n-grams: collect all new terms, generate ngrams, batch insert ---
        $ngramBatch = [];
        $ngramIndexBatch = [];
        foreach (array_keys($termFreqs) as $term) {
            if (!$this->termHasNgrams($term, $siteId)) {
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

        // --- Metadata ---
        $this->addDocumentToIndex($siteId, $elementId);
        $this->updateTotalLength($docLen);

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

            // Reset totals
            $this->resetTotalLength();
            $this->updateTotalDocCount();

            Craft::info("Search index cleared (bulk) for site ID: $siteId", __METHOD__);
            return true;
        } catch (\Throwable $e) {
            Craft::error("Error clearing search index: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
