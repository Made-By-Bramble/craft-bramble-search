<?php

namespace MadeByBramble\BrambleSearch\adapters;

use MadeByBramble\BrambleSearch\Plugin;
use Craft;
use craft\helpers\App;
use craft\db\Query;
use craft\db\Table;

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

        foreach ($termFreqs as $term => $freq) {
            $batch[] = [
                'siteId' => $siteId,
                'elementId' => $elementId,
                'term' => $term,
                'frequency' => $freq,
            ];
        }

        // Add document length as a special term
        $batch[] = [
            'siteId' => $siteId,
            'elementId' => $elementId,
            'term' => '_length',
            'frequency' => $docLen,
        ];

        if (!empty($batch)) {
            $db->createCommand()
                ->batchInsert(
                    $this->tablePrefix . 'documents}}',
                    ['siteId', 'elementId', 'term', 'frequency'],
                    $batch
                )
                ->execute();
        }
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
        Craft::$app->getDb()->createCommand()
            ->insert($this->tablePrefix . 'metadata}}', [
                'key' => 'doc',
                'value' => "{$siteId}:{$elementId}",
            ])
            ->execute();
    }

    /**
     * Update the total document count in MySQL
     */
    protected function updateTotalDocCount(): void
    {
        $db = Craft::$app->getDb();
        $count = (new Query())
            ->from($this->tablePrefix . 'metadata}}')
            ->where(['key' => 'doc'])
            ->count();

        // Delete existing count
        $db->createCommand()
            ->delete($this->tablePrefix . 'metadata}}', [
                'key' => 'totalDocs',
            ])
            ->execute();

        // Insert new count
        $db->createCommand()
            ->insert($this->tablePrefix . 'metadata}}', [
                'key' => 'totalDocs',
                'value' => (string)$count,
            ])
            ->execute();
    }

    /**
     * Update the total token length in MySQL
     *
     * @param int $docLen The document length to add
     */
    protected function updateTotalLength(int $docLen): void
    {
        $db = Craft::$app->getDb();
        $currentLength = $this->getTotalLength();
        $newLength = $currentLength + $docLen;

        // Delete existing length
        $db->createCommand()
            ->delete($this->tablePrefix . 'metadata}}', [
                'key' => 'totalLength',
            ])
            ->execute();

        // Insert new length
        $db->createCommand()
            ->insert($this->tablePrefix . 'metadata}}', [
                'key' => 'totalLength',
                'value' => (string)$newLength,
            ])
            ->execute();
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
        Craft::$app->getDb()->createCommand()
            ->insert($this->tablePrefix . 'terms}}', [
                'term' => $term,
                'docId' => "{$siteId}:{$elementId}",
                'frequency' => $freq,
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
                ];
            }

            $db->createCommand()
                ->batchInsert(
                    $this->tablePrefix . 'titles}}',
                    ['siteId', 'elementId', 'term'],
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
     * Reset the total length counter to zero in MySQL
     */
    protected function resetTotalLength(): void
    {
        $db = Craft::$app->getDb();

        // Delete existing length
        $db->createCommand()
            ->delete($this->tablePrefix . 'metadata}}', [
                'key' => 'totalLength',
            ])
            ->execute();

        // Insert new length
        $db->createCommand()
            ->insert($this->tablePrefix . 'metadata}}', [
                'key' => 'totalLength',
                'value' => '0',
            ])
            ->execute();
    }
}
