<?php

namespace MadeByBramble\BrambleSearch\adapters;

use MadeByBramble\BrambleSearch\Plugin;
use Craft;
use craft\helpers\App;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;

/**
 * MongoDB Search Adapter
 *
 * Implements the search adapter using MongoDB as the storage backend.
 * Provides excellent performance and scalability for large content volumes.
 */
class MongoDbSearchAdapter extends BaseSearchAdapter
{
    // =========================================================================
    // PROPERTIES
    // =========================================================================

    /**
     * MongoDB client instance
     */
    protected Client $client;

    /**
     * MongoDB database instance
     */
    protected Database $database;

    /**
     * MongoDB documents collection
     */
    protected Collection $documentsCollection;

    /**
     * MongoDB terms collection
     */
    protected Collection $termsCollection;

    /**
     * MongoDB titles collection
     */
    protected Collection $titlesCollection;

    /**
     * MongoDB metadata collection
     */
    protected Collection $metadataCollection;

    // =========================================================================
    // INITIALIZATION METHODS
    // =========================================================================

    /**
     * Initialize the MongoDB connection and collections
     */
    public function init(): void
    {
        parent::init();
        $settings = Plugin::getInstance()->getSettings();

        // Get connection settings from environment or settings
        $mongoUri = App::parseEnv('$BRAMBLE_SEARCH_MONGODB_URI') ?: $settings->mongoDbUri;
        $databaseName = App::parseEnv('$BRAMBLE_SEARCH_MONGODB_DATABASE') ?: $settings->mongoDbDatabase;

        try {
            // Initialize MongoDB client
            $this->client = new Client($mongoUri);
            $this->database = $this->client->selectDatabase($databaseName);

            // Initialize collections (will create them if they don't exist)
            $this->documentsCollection = $this->database->selectCollection('documents');
            $this->termsCollection = $this->database->selectCollection('terms');
            $this->titlesCollection = $this->database->selectCollection('titles');
            $this->metadataCollection = $this->database->selectCollection('metadata');

            // Ensure indexes exist
            $this->ensureIndexes();

            Craft::info(
                "MongoDB adapter initialized with database: $databaseName",
                'bramble-search'
            );
        } catch (\Exception $e) {
            Craft::error(
                "Failed to initialize MongoDB adapter: " . $e->getMessage(),
                'bramble-search'
            );
            throw $e;
        }
    }

    /**
     * Ensure all required indexes exist for optimal performance
     */
    protected function ensureIndexes(): void
    {
        // Documents collection indexes
        $this->documentsCollection->createIndex(['docId' => 1], ['unique' => true]);

        // Terms collection indexes
        $this->termsCollection->createIndex(['term' => 1], ['unique' => true]);

        // Titles collection indexes
        $this->titlesCollection->createIndex(['docId' => 1], ['unique' => true]);

        // Metadata collection indexes
        $this->metadataCollection->createIndex(['key' => 1], ['unique' => true]);
    }

    // =========================================================================
    // DOCUMENT OPERATIONS
    // =========================================================================

    /**
     * Get all terms for a document from MongoDB
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return array The terms and their frequencies
     */
    protected function getDocumentTerms(int $siteId, int $elementId): array
    {
        $docId = "{$siteId}:{$elementId}";
        $document = $this->documentsCollection->findOne(
            ['docId' => $docId],
            ['projection' => ['terms' => 1, '_id' => 0]]
        );

        if (!$document || !isset($document['terms'])) {
            return [];
        }

        // Convert MongoDB\Model\BSONDocument to a regular PHP array
        $terms = [];
        foreach ($document['terms'] as $term => $freq) {
            $terms[$term] = (int)$freq;
        }

        return $terms;
    }

    /**
     * Delete a document from MongoDB
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function deleteDocument(int $siteId, int $elementId): void
    {
        $docId = "{$siteId}:{$elementId}";
        $this->documentsCollection->deleteOne(['docId' => $docId]);
    }

    /**
     * Store a document in MongoDB
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param array $termFreqs The terms and their frequencies
     * @param int $docLen The document length
     */
    protected function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLen): void
    {
        $docId = "{$siteId}:{$elementId}";

        $this->documentsCollection->updateOne(
            ['docId' => $docId],
            [
                '$set' => [
                    'siteId' => $siteId,
                    'elementId' => $elementId,
                    'terms' => $termFreqs,
                    'length' => $docLen
                ]
            ],
            ['upsert' => true]
        );
    }

    /**
     * Get the length of a document in tokens from MongoDB
     *
     * @param string $docId The document ID (siteId:elementId)
     * @return int The document length
     */
    protected function getDocumentLength(string $docId): int
    {
        $document = $this->documentsCollection->findOne(
            ['docId' => $docId],
            ['projection' => ['length' => 1, '_id' => 0]]
        );

        if (!$document || !isset($document['length'])) {
            return 0;
        }

        return (int)$document['length'];
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
        $docId = "{$siteId}:{$elementId}";

        $this->metadataCollection->updateOne(
            ['key' => 'docs'],
            ['$addToSet' => ['values' => $docId]],
            ['upsert' => true]
        );
    }

    /**
     * Update the total document count in MongoDB
     */
    protected function updateTotalDocCount(): void
    {
        $docsMetadata = $this->metadataCollection->findOne(['key' => 'docs']);
        $totalDocs = 0;

        if ($docsMetadata && isset($docsMetadata['values'])) {
            $totalDocs = count($docsMetadata['values']);
        }

        $this->metadataCollection->updateOne(
            ['key' => 'totalDocs'],
            ['$set' => ['value' => $totalDocs]],
            ['upsert' => true]
        );
    }

    /**
     * Update the total token length in MongoDB
     *
     * @param int $docLen The document length to add
     */
    protected function updateTotalLength(int $docLen): void
    {
        $this->metadataCollection->updateOne(
            ['key' => 'totalLength'],
            ['$inc' => ['value' => $docLen]],
            ['upsert' => true]
        );
    }

    /**
     * Get the total document count from MongoDB
     *
     * @return int The total document count
     */
    protected function getTotalDocCount(): int
    {
        $metadata = $this->metadataCollection->findOne(
            ['key' => 'totalDocs'],
            ['projection' => ['value' => 1, '_id' => 0]]
        );

        if (!$metadata || !isset($metadata['value'])) {
            return 0;
        }

        return (int)$metadata['value'];
    }

    /**
     * Get the total token length from MongoDB
     *
     * @return int The total token length
     */
    protected function getTotalLength(): int
    {
        $metadata = $this->metadataCollection->findOne(
            ['key' => 'totalLength'],
            ['projection' => ['value' => 1, '_id' => 0]]
        );

        if (!$metadata || !isset($metadata['value'])) {
            return 0;
        }

        return (int)$metadata['value'];
    }

    // =========================================================================
    // TERM OPERATIONS
    // =========================================================================

    /**
     * Store a term-document association in MongoDB
     *
     * @param string $term The term
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param int $freq The term frequency
     */
    protected function storeTermDocument(string $term, int $siteId, int $elementId, int $freq): void
    {
        $docId = "{$siteId}:{$elementId}";
        $updateField = "docs.$docId";

        $this->termsCollection->updateOne(
            ['term' => $term],
            ['$set' => [$updateField => $freq]],
            ['upsert' => true]
        );
    }

    /**
     * Remove a term-document association from MongoDB
     *
     * @param string $term The term
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function removeTermDocument(string $term, int $siteId, int $elementId): void
    {
        $docId = "{$siteId}:{$elementId}";
        $updateField = "docs.$docId";

        $this->termsCollection->updateOne(
            ['term' => $term],
            ['$unset' => [$updateField => ""]]
        );

        // Clean up empty terms (where docs is empty)
        $this->termsCollection->deleteMany(['docs' => ['$eq' => new \stdClass()]]);
    }

    /**
     * Get all documents containing a specific term from MongoDB
     *
     * @param string $term The term to look up
     * @return array The documents containing the term and their frequencies
     */
    protected function getTermDocuments(string $term): array
    {
        $termDoc = $this->termsCollection->findOne(
            ['term' => $term],
            ['projection' => ['docs' => 1, '_id' => 0]]
        );

        if (!$termDoc || !isset($termDoc['docs'])) {
            return [];
        }

        // Convert MongoDB\Model\BSONDocument to a regular PHP array
        $docs = [];
        foreach ($termDoc['docs'] as $docId => $freq) {
            $docs[$docId] = (int)$freq;
        }

        return $docs;
    }

    /**
     * Get all terms in the index from MongoDB
     *
     * @return array All terms in the index
     */
    protected function getAllTerms(): array
    {
        $cursor = $this->termsCollection->find(
            [],
            ['projection' => ['term' => 1, '_id' => 0]]
        );

        $terms = [];
        foreach ($cursor as $doc) {
            // Ensure we're working with a string
            $terms[] = (string)$doc['term'];
        }

        return $terms;
    }

    /**
     * Remove a term from the index in MongoDB
     *
     * @param string $term The term to remove
     */
    protected function removeTermFromIndex(string $term): void
    {
        $this->termsCollection->deleteOne(['term' => $term]);
    }

    // =========================================================================
    // TITLE OPERATIONS
    // =========================================================================

    /**
     * Store title terms for a document in MongoDB
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param array $titleTerms The title terms
     */
    protected function storeTitleTerms(int $siteId, int $elementId, array $titleTerms): void
    {
        $docId = "{$siteId}:{$elementId}";

        $this->titlesCollection->updateOne(
            ['docId' => $docId],
            [
                '$set' => [
                    'siteId' => $siteId,
                    'elementId' => $elementId,
                    'terms' => array_keys($titleTerms)
                ]
            ],
            ['upsert' => true]
        );
    }

    /**
     * Get title terms for a document from MongoDB
     *
     * @param string $docId The document ID (siteId:elementId)
     * @return array The title terms
     */
    protected function getTitleTerms(string $docId): array
    {
        $titleDoc = $this->titlesCollection->findOne(
            ['docId' => $docId],
            ['projection' => ['terms' => 1, '_id' => 0]]
        );

        if (!$titleDoc || !isset($titleDoc['terms'])) {
            return [];
        }

        // Convert MongoDB\Model\BSONArray to a regular PHP array first
        $terms = (array)$titleDoc['terms'];

        // Make sure we have a flat, indexed array before flipping
        $flatTerms = [];
        foreach ($terms as $term) {
            if (is_string($term)) {
                $flatTerms[] = $term;
            }
        }

        // Convert to associative array for faster lookups
        return array_flip($flatTerms);
    }

    /**
     * Delete title terms for a document from MongoDB
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function deleteTitleTerms(int $siteId, int $elementId): void
    {
        $docId = "{$siteId}:{$elementId}";
        $this->titlesCollection->deleteOne(['docId' => $docId]);
    }

    // =========================================================================
    // SITE OPERATIONS
    // =========================================================================

    /**
     * Get all documents for a specific site from MongoDB
     *
     * @param int $siteId The site ID
     * @return array The document IDs
     */
    protected function getSiteDocuments(int $siteId): array
    {
        $docsMetadata = $this->metadataCollection->findOne(['key' => 'docs']);

        if (!$docsMetadata || !isset($docsMetadata['values'])) {
            return [];
        }

        // Filter documents by site ID
        $sitePrefix = "$siteId:";
        $siteDocs = [];

        // Convert MongoDB\Model\BSONArray to a regular PHP array and filter
        foreach ($docsMetadata['values'] as $docId) {
            // Ensure we're working with a string
            $docId = (string)$docId;
            if (strpos($docId, $sitePrefix) === 0) {
                $siteDocs[] = $docId;
            }
        }

        return $siteDocs;
    }

    /**
     * Remove a document from the index metadata in MongoDB
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function removeDocumentFromIndex(int $siteId, int $elementId): void
    {
        $docId = "{$siteId}:{$elementId}";

        $this->metadataCollection->updateOne(
            ['key' => 'docs'],
            ['$pull' => ['values' => $docId]]
        );
    }

    /**
     * Reset the total length counter to zero in MongoDB
     */
    protected function resetTotalLength(): void
    {
        $this->metadataCollection->updateOne(
            ['key' => 'totalLength'],
            ['$set' => ['value' => 0]],
            ['upsert' => true]
        );
    }
}
