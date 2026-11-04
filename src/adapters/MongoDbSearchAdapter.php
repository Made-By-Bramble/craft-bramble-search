<?php

namespace MadeByBramble\BrambleSearch\adapters;

use Craft;
use craft\helpers\App;
use MadeByBramble\BrambleSearch\Plugin;
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
    protected function hasPrefixDictionary(): bool
    {
        return true;
    }

    protected function validatePrefixLifecycleCursors(array $state): void
    {
        if (($state['cleanup'] !== [] && (array_keys($state['cleanup']) !== ['stage'] || !is_int($state['cleanup']['stage']) || $state['cleanup']['stage'] < 0 || $state['cleanup']['stage'] > 2))
            || ($state['legacyCursor'] !== null && (!is_int($state['legacyCursor']) || $state['legacyCursor'] < 0 || $state['legacyCursor'] > 5))) {
            throw new \MadeByBramble\BrambleSearch\helpers\PrefixIntegrityException('Invalid MongoDB prefix cleanup checkpoint.');
        }
    }

    protected function prefixEpochCollections(): array
    {
        return [$this->prefixDictionaryCollection, $this->prefixMembershipCollection, $this->prefixDocumentsCollection];
    }

    protected function initializePrefixEpoch(int $siteId, string $epoch): void
    {
        foreach ($this->prefixEpochCollections() as $collection) {
            $collection->updateOne(['_id' => "epoch:$siteId:$epoch"], ['$setOnInsert' => [
                'siteId' => $siteId, 'epoch' => $epoch, 'version' => 2, 'kind' => 'epoch', 'term' => '', 'docId' => '',
            ]], ['upsert' => true]);
        }
        $this->assertPrefixEpoch($siteId, $epoch);
    }

    protected function assertPrefixEpoch(int $siteId, string $epoch): void
    {
        foreach ($this->prefixEpochCollections() as $collection) {
            $marker = $collection->findOne(['_id' => "epoch:$siteId:$epoch"]);
            if ($marker === null || ($marker['version'] ?? null) !== 2 || ($marker['siteId'] ?? null) !== $siteId || ($marker['epoch'] ?? null) !== $epoch || ($marker['kind'] ?? null) !== 'epoch') {
                throw new \MadeByBramble\BrambleSearch\helpers\PrefixIntegrityException('MongoDB prefix epoch marker is missing or incompatible.');
            }
        }
    }

    protected function prefixMembershipTerms(int $siteId, string $epoch, string $docId): array
    {
        $terms = [];
        foreach ($this->prefixMembershipCollection->find(['siteId' => $siteId, 'epoch' => $epoch, 'docId' => $docId], ['projection' => ['term' => 1], 'collation' => ['locale' => 'simple'], 'batchSize' => 500]) as $row) {
            $terms[] = (string)$row['term'];
        }

        return $this->normalizePrefixTerms($terms);
    }

    protected function publishPrefixEpochMembership(int $siteId, string $epoch, int $elementId, array $terms, bool $exists): void
    {
        $this->assertPrefixEpoch($siteId, $epoch);
        $docId = "$siteId:$elementId";
        $filter = ['siteId' => $siteId, 'epoch' => $epoch, 'docId' => $docId];
        $previous = $this->prefixDocumentsCollection->findOne($filter);
        $oldTerms = $this->prefixMembershipTerms($siteId, $epoch, $docId);
        if ($exists && $previous !== null && ($previous['pending'] ?? null) === false && $oldTerms === $terms) {
            return;
        }
        $journal = $this->readIndexRecord('journal:' . $docId);
        $affected = $this->normalizePrefixTerms([
            ...$oldTerms, ...$terms, ...($journal['oldTerms'] ?? []), ...($journal['intendedTerms'] ?? []),
            ...($previous === null ? [] : json_decode(json_encode($previous['affected'] ?? [], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)),
        ]);
        $this->prefixDocumentsCollection->updateOne($filter, ['$set' => ['affected' => $affected, 'pending' => true]], ['upsert' => true]);
        $this->mutationCheckpoint('prefixPending', $siteId, $elementId);
        $this->prefixMembershipCollection->deleteMany($filter);
        $this->mutationCheckpoint('prefixRemoved', $siteId, $elementId);
        foreach (array_chunk($terms, 500) as $chunk) {
            $operations = array_map(static fn(string $term): array => ['insertOne' => [[...$filter, 'term' => $term]]], $chunk);
            $this->prefixMembershipCollection->bulkWrite($operations, ['ordered' => true]);
        }
        $this->mutationCheckpoint('prefixRows', $siteId, $elementId);
        foreach (array_chunk($affected, 500) as $chunk) {
            $counts = [];
            foreach ($this->prefixMembershipCollection->aggregate([
                ['$match' => ['siteId' => $siteId, 'epoch' => $epoch, 'term' => ['$in' => $chunk]]],
                ['$group' => ['_id' => '$term', 'count' => ['$sum' => 1]]],
            ], ['collation' => ['locale' => 'simple']]) as $row) {
                $counts[(string)$row['_id']] = (int)$row['count'];
            }
            $operations = [];
            foreach ($chunk as $term) {
                $termFilter = ['siteId' => $siteId, 'epoch' => $epoch, 'term' => $term];
                $operations[] = isset($counts[$term])
                    ? ['updateOne' => [$termFilter, ['$set' => ['count' => $counts[$term]]], ['upsert' => true]]]
                    : ['deleteOne' => [$termFilter]];
            }
            $this->prefixDictionaryCollection->bulkWrite($operations, ['ordered' => true]);
        }
        $this->mutationCheckpoint('prefixCounts', $siteId, $elementId);
        if ($exists) {
            $this->prefixDocumentsCollection->updateOne($filter, ['$set' => ['pending' => false], '$unset' => ['affected' => '']]);
        } else {
            $this->prefixDocumentsCollection->deleteOne($filter);
        }
    }

    protected function getTermsByPrefix(string $prefix, int $siteId, int $limit = 100): array
    {
        if ($prefix === '' || $limit <= 0) {
            return [];
        }

        return $this->prefixTermsFromActiveEpoch($prefix, $siteId, $limit) ?? parent::getTermsByPrefix($prefix, $siteId, $limit);
    }

    protected function queryPrefixEpoch(int $siteId, string $epoch, string $prefix, int $limit): array
    {
        $matches = [];
        foreach ($this->prefixDictionaryCollection->find([
            'siteId' => $siteId, 'epoch' => $epoch, 'term' => new \MongoDB\BSON\Regex('^' . preg_quote($prefix, '/')),
        ], ['sort' => ['term' => 1], 'limit' => $limit, 'projection' => ['term' => 1, 'count' => 1], 'collation' => ['locale' => 'simple']]) as $row) {
            $count = $row['count'] ?? null;
            if (!is_int($count) || $count < 1) {
                throw new \MadeByBramble\BrambleSearch\helpers\PrefixIntegrityException('MongoDB prefix reference count is invalid.');
            }
            $term = (string)$row['term'];
            $matches[$term] = $this->calculateTypeaheadConfidence($prefix, $term);
        }
        arsort($matches);

        return $matches;
    }

    protected function prefixDocumentPage(int $siteId, ?string $epoch, mixed $cursor, int $limit): array
    {
        $collection = $epoch === null ? $this->documentsCollection : $this->prefixDocumentsCollection;
        $filter = ['docId' => ['$gt' => $cursor ?? '']];
        if ($siteId !== 0) {
            $filter['siteId'] = $siteId;
        }
        if ($epoch !== null) {
            $filter['epoch'] = $epoch;
        }
        $documents = [];
        foreach ($collection->find($filter, ['sort' => ['docId' => 1], 'limit' => $limit, 'projection' => ['docId' => 1], 'collation' => ['locale' => 'simple']]) as $row) {
            $documents[] = (string)$row['docId'];
        }

        return ['documents' => $documents, 'cursor' => $documents === [] ? $cursor : end($documents), 'done' => count($documents) < $limit];
    }

    protected function deletePrefixRowsPage(Collection $collection, array $filter, int $limit): bool
    {
        $ids = [];
        foreach ($collection->find($filter, ['projection' => ['_id' => 1], 'limit' => $limit]) as $row) {
            $ids[] = $row['_id'];
        }
        if ($ids !== []) {
            $collection->deleteMany(['_id' => ['$in' => $ids]]);
        }

        return count($ids) < $limit;
    }

    protected function cleanupPrefixEpochStep(int $siteId, string $epoch, array $checkpoint, int $limit): array
    {
        $stage = $checkpoint['stage'] ?? 0;
        if ($this->deletePrefixRowsPage($this->prefixEpochCollections()[$stage], ['siteId' => $siteId, 'epoch' => $epoch], $limit * 25)) {
            $stage++;
        }

        return ['done' => $stage === 3, 'checkpoint' => ['stage' => $stage]];
    }

    protected function cleanupLegacyPrefixStep(int $siteId, mixed $cursor): array
    {
        $stage = $cursor ?? 0;
        $collections = [$this->database->selectCollection('prefix_dictionary_v1'), $this->database->selectCollection('prefix_membership_v1'), ...$this->prefixEpochCollections()];
        $filter = ['siteId' => $siteId];
        if ($stage >= 2) {
            $state = $this->prefixLifecycleState($siteId);
            $filter['epoch'] = ['$nin' => array_values(array_filter([$state['active'], $state['building']], static fn(mixed $epoch): bool => $epoch !== null))];
        }
        if ($this->deletePrefixRowsPage($collections[$stage], $filter, 500)) {
            $stage++;
        }
        if ($stage === count($collections)) {
            $this->deleteIndexRecord('prefix:' . $siteId);
        }

        return ['done' => $stage === count($collections), 'cursor' => $stage];
    }

    protected function documentIsCatalogued(int $siteId, int $elementId): bool
    {
        return ($this->readIndexRecord("catalogue:$siteId:$elementId")['exists'] ?? false)
            || $this->metadataCollection->findOne(['key' => 'docs', 'values' => "$siteId:$elementId"], ['projection' => ['_id' => 1]]) !== null;
    }

    protected function publishDocumentCatalogue(int $siteId, int $elementId, bool $exists, int $length): void
    {
        if ($exists) {
            $this->writeIndexRecord("catalogue:$siteId:$elementId", ['exists' => true, 'length' => $length]);
            $this->addDocumentToIndex($siteId, $elementId);
        } else {
            $this->removeDocumentFromIndex($siteId, $elementId);
            $this->deleteIndexRecord("catalogue:$siteId:$elementId");
        }
    }
    protected function removePostingChanges(int $siteId, int $elementId, array $terms): void
    {
        if ($terms === []) {
            return;
        }
        $operations = [];
        foreach ($terms as $term) {
            $operations[] = ['updateOne' => [['term' => $term], ['$unset' => ["docs.$siteId:$elementId" => '']]]];
            $operations[] = ['deleteOne' => [['term' => $term, 'docs' => ['$eq' => new \stdClass()]]]];
        }
        $this->termsCollection->bulkWrite($operations);
    }
    private array $storageCoordinates = [];
    protected function getIndexedDocumentIds(): array
    {
        $record = $this->metadataCollection->findOne(['key' => 'docs']);
        $values = $record['values'] ?? [];

        return array_values(is_array($values) ? $values : iterator_to_array($values));
    }
    protected function documentExists(int $siteId, int $elementId): bool
    {
        return $this->documentsCollection->findOne(['docId' => "$siteId:$elementId", 'length' => ['$exists' => true]], ['projection' => ['_id' => 1]]) !== null;
    }
    protected function getStorageNamespace(): array
    {
        return $this->storageCoordinates;
    }

    protected function readIndexRecord(string $key): ?array
    {
        if (str_starts_with($key, 'term_sources:')) {
            $record = $this->documentsCollection->findOne(['docId' => substr($key, 13)], ['projection' => ['sourceState' => 1]]);
            $value = $record['sourceState'] ?? null;
        } else {
            $record = $this->metadataCollection->findOne(['key' => 'state:' . $key]);
            $value = $record['state'] ?? null;
        }

        $record = $value === null ? null : json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if ($record !== null && !is_array($record) && str_starts_with($key, 'prefix_lifecycle:')) {
            throw new \MadeByBramble\BrambleSearch\helpers\PrefixIntegrityException('Invalid MongoDB prefix lifecycle record.');
        }

        return $record;
    }

    protected function readIndexRecordsBatch(array $keys): array
    {
        $records = array_fill_keys($keys, null);
        $docKeys = [];
        $metaKeys = [];
        foreach ($keys as $key) {
            if (str_starts_with($key, 'term_sources:')) {
                $docKeys[] = substr($key, 13);
            } else {
                $metaKeys[] = 'state:' . $key;
            }
        }
        if ($docKeys !== []) {
            foreach ($this->documentsCollection->find(['docId' => ['$in' => $docKeys]], ['projection' => ['docId' => 1, 'sourceState' => 1]]) as $record) {
                $records['term_sources:' . $record['docId']] = isset($record['sourceState']) ? json_decode(json_encode($record['sourceState'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR) : null;
            }
        }
        if ($metaKeys !== []) {
            foreach ($this->metadataCollection->find(['key' => ['$in' => $metaKeys]]) as $record) {
                $records[substr($record['key'], 6)] = json_decode(json_encode($record['state'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $records;
    }

    protected function writeIndexRecord(string $key, array $value): void
    {
        if (str_starts_with($key, 'term_sources:')) {
            $this->documentsCollection->updateOne(['docId' => substr($key, 13)], ['$set' => ['sourceState' => $value]], ['upsert' => true]);
        } else {
            $this->metadataCollection->updateOne(['key' => 'state:' . $key], ['$set' => ['state' => $value]], ['upsert' => true]);
        }
    }

    protected function deleteIndexRecord(string $key): void
    {
        if (str_starts_with($key, 'term_sources:')) {
            $this->documentsCollection->updateOne(['docId' => substr($key, 13)], ['$unset' => ['sourceState' => '']]);
            $this->documentsCollection->deleteOne(['docId' => substr($key, 13), 'length' => ['$exists' => false]]);
        } else {
            $this->metadataCollection->deleteOne(['key' => 'state:' . $key]);
        }
    }

    protected function writeIndexTotals(int $documents, int $length): void
    {
        $this->metadataCollection->bulkWrite([
            ['updateOne' => [['key' => 'totalDocs'], ['$set' => ['value' => $documents]], ['upsert' => true]]],
            ['updateOne' => [['key' => 'totalLength'], ['$set' => ['value' => $length]], ['upsert' => true]]],
        ]);
    }

    protected function writePostingChanges(int $siteId, int $elementId, array $frequencies): void
    {
        if ($frequencies === []) {
            return;
        }
        $operations = [];
        foreach ($frequencies as $term => $frequency) {
            $operations[] = ['updateOne' => [['term' => (string)$term], ['$set' => ["docs.$siteId:$elementId" => $frequency]], ['upsert' => true]]];
        }
        $this->termsCollection->bulkWrite($operations);
    }
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
    protected Collection $prefixDictionaryCollection;
    protected Collection $prefixMembershipCollection;
    protected Collection $prefixDocumentsCollection;

    // =========================================================================
    // INITIALIZATION METHODS
    // =========================================================================

    /**
     * Initialize the MongoDB connection and collections
     */
    public function init(): void
    {
        parent::init();
        /** @var \MadeByBramble\BrambleSearch\models\Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        // Get connection settings from environment or settings
        $mongoUri = App::parseEnv('$BRAMBLE_SEARCH_MONGODB_URI') ?: $settings->mongoDbUri;
        $databaseName = App::parseEnv('$BRAMBLE_SEARCH_MONGODB_DATABASE') ?: $settings->mongoDbDatabase;
        [$scheme, $remainder] = explode('://', $mongoUri, 2);
        $authority = explode('/', $remainder, 2)[0];
        $authority = substr($authority, strrpos($authority, '@') === false ? 0 : strrpos($authority, '@') + 1);
        $endpoints = array_map(static function(string $endpoint) use ($scheme): string {
            if (str_contains(rawurldecode($endpoint), '/')) {
                return rawurldecode($endpoint);
            }
            $endpoint = strtolower($endpoint);

            return $scheme === 'mongodb' && (!str_contains($endpoint, ':') || str_ends_with($endpoint, ']')) ? $endpoint . ':27017' : $endpoint;
        }, explode(',', $authority));
        sort($endpoints, SORT_STRING);
        $this->storageCoordinates = ['driver' => 'mongodb', 'scheme' => strtolower($scheme), 'endpoints' => $endpoints, 'database' => $databaseName];

        try {
            // Initialize MongoDB client
            $this->client = new Client($mongoUri);
            $this->database = $this->client->selectDatabase($databaseName);

            // Initialize collections (will create them if they don't exist)
            $this->documentsCollection = $this->database->selectCollection('documents');
            $this->termsCollection = $this->database->selectCollection('terms');
            $this->titlesCollection = $this->database->selectCollection('titles');
            $this->metadataCollection = $this->database->selectCollection('metadata');
            $this->prefixDictionaryCollection = $this->database->selectCollection('prefix_dictionary_v2');
            $this->prefixMembershipCollection = $this->database->selectCollection('prefix_membership_v2');
            $this->prefixDocumentsCollection = $this->database->selectCollection('prefix_documents_v2');

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
        $this->documentsCollection->createIndex(['siteId' => 1, 'docId' => 1]);
        $this->prefixDictionaryCollection->createIndex(['siteId' => 1, 'epoch' => 1, 'term' => 1], ['unique' => true, 'collation' => ['locale' => 'simple']]);
        $this->prefixMembershipCollection->createIndex(['siteId' => 1, 'epoch' => 1, 'term' => 1, 'docId' => 1], ['unique' => true, 'collation' => ['locale' => 'simple']]);
        $this->prefixMembershipCollection->createIndex(['siteId' => 1, 'epoch' => 1, 'docId' => 1], ['collation' => ['locale' => 'simple']]);
        $this->prefixDocumentsCollection->createIndex(['siteId' => 1, 'epoch' => 1, 'docId' => 1], ['unique' => true, 'collation' => ['locale' => 'simple']]);
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
            if ($this->documentIsCatalogued($siteId, $elementId)) {
                $terms = [];
                foreach ($this->termsCollection->find(["docs.$docId" => ['$exists' => true]], ['projection' => ['term' => 1, 'docs.' . $docId => 1]]) as $posting) {
                    $terms[$posting['term']] = (int)$posting['docs'][$docId];
                }

                return $terms;
            }

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
                    'length' => $docLen,
                ],
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
            $catalogue = $this->readIndexRecord('catalogue:' . $docId);
            if ($catalogue !== null) {
                return $catalogue['length'];
            }
            [$siteId, $elementId] = array_map('intval', explode(':', $docId, 2));

            return $this->documentIsCatalogued($siteId, $elementId) ? array_sum($this->getDocumentTerms($siteId, $elementId)) : 0;
        }

        return (int)$document['length'];
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

        $cursor = $this->documentsCollection->find(
            ['docId' => ['$in' => $docIds]],
            ['projection' => ['docId' => 1, 'length' => 1, '_id' => 0]]
        );

        $lengths = [];
        
        // Initialize all to 0 first
        foreach ($docIds as $docId) {
            $lengths[$docId] = 0;
        }
        
        // Fill in actual values
        foreach ($cursor as $document) {
            if (isset($document['docId']) && isset($document['length'])) {
                $lengths[$document['docId']] = (int)$document['length'];
            }
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
        $this->ensureIndexMetadata();
        $record = $this->metadataCollection->findOne(['key' => 'totalDocs']);
        $value = $record['value'] ?? false;

        return $this->requireMetadataCounter($value);
    }

    /**
     * Get the total token length from MongoDB
     *
     * @return int The total token length
     */
    protected function getTotalLength(): int
    {
        $this->ensureIndexMetadata();
        $record = $this->metadataCollection->findOne(['key' => 'totalLength']);
        $value = $record['value'] ?? false;

        return $this->requireMetadataCounter($value);
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
        $this->termsCollection->deleteOne(['term' => $term, 'docs' => ['$eq' => new \stdClass()]]);
    }

    protected function getTermDocumentsBatch(array $terms): array
    {
        $terms = array_map('strval', $terms);
        $result = array_fill_keys($terms, []);
        if ($terms === []) {
            return $result;
        }
        foreach ($this->termsCollection->find(['term' => ['$in' => array_values($terms)]], ['projection' => ['term' => 1, 'docs' => 1], 'collation' => ['locale' => 'simple']]) as $row) {
            $result[$row['term']] = [];
            foreach ($row['docs'] ?? [] as $docId => $frequency) {
                $result[$row['term']][(string)$docId] = (int)$frequency;
            }
        }

        return $result;
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
                    'terms' => array_map('strval', array_keys($titleTerms)),
                ],
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
            if (is_string($term) || is_int($term)) {
                $flatTerms[] = (string)$term;
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

    // =========================================================================
    // N-GRAM OPERATIONS
    // =========================================================================

    /**
     * Store n-grams for a term in MongoDB
     *
     * @param string $term The term to store n-grams for
     * @param array $ngrams Array of n-grams for the term
     * @param int $siteId The site ID
     * @return void
     */
    protected function storeTermNgrams(string $term, array $ngrams, int $siteId): void
    {
        if (empty($ngrams)) {
            return;
        }

        $ngrams = array_values(array_unique($ngrams));

        // Create n-gram documents for the term
        $ngramDocs = [];
        foreach ($ngrams as $ngram) {
            $ngramDocs[] = [
                'ngram' => $ngram,
                'term' => $term,
                'siteId' => $siteId,
            ];
        }

        // Store n-grams in bulk
        $this->database->selectCollection('ngrams')->insertMany($ngramDocs);

        // Store n-gram count for the term
        $this->database->selectCollection('ngram_index')->updateOne(
            ['term' => $term, 'siteId' => $siteId],
            ['$set' => ['ngram_count' => count($ngrams)]],
            ['upsert' => true]
        );
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

        $ngrams = array_values(array_unique($ngrams));

        // Use aggregation pipeline to find similar terms
        $pipeline = [
            ['$match' => ['ngram' => ['$in' => $ngrams], 'siteId' => $siteId]],
            ['$group' => ['_id' => '$term', 'match_count' => ['$sum' => 1]]],
            ['$lookup' => [
                'from' => 'ngram_index',
                'localField' => '_id',
                'foreignField' => 'term',
                'as' => 'term_info',
            ]],
            ['$unwind' => '$term_info'],
            ['$match' => ['term_info.siteId' => $siteId]],
            ['$addFields' => [
                'jaccard_similarity' => [
                    '$divide' => [
                        '$match_count',
                        [
                            '$subtract' => [
                                ['$add' => ['$term_info.ngram_count', count($ngrams)]],
                                '$match_count',
                            ],
                        ],
                    ],
                ],
            ]],
            ['$match' => ['jaccard_similarity' => ['$gte' => $threshold]]],
            ['$sort' => ['jaccard_similarity' => -1, 'match_count' => -1]],
            ['$limit' => max(100, $this->fuzzySearchMaxCandidates * 5)],
        ];

        $cursor = $this->database->selectCollection('ngrams')->aggregate($pipeline);
        $results = [];

        foreach ($cursor as $doc) {
            $results[$doc['_id']] = (float)$doc['jaccard_similarity'];
        }

        return $results;
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
        $result = $this->database->selectCollection('ngram_index')->findOne([
            'term' => $term,
            'siteId' => $siteId,
        ]);

        return $result !== null;
    }

    /**
     * Check whether a term's stored n-gram count matches the currently configured ngramSizes.
     *
     * The ngram_index collection already stores the count from when the term was indexed, so
     * this is a single cheap findOne rather than regenerating and diffing the full n-gram set.
     *
     * @param string $term The term to check
     * @param int $siteId The site ID
     * @return bool Whether the term's stored n-gram count matches current-size generation
     */
    protected function termNgramsCurrent(string $term, int $siteId): bool
    {
        $result = $this->database->selectCollection('ngram_index')->findOne([
            'term' => $term,
            'siteId' => $siteId,
        ]);

        return $result !== null && (int)$result['ngram_count'] === count($this->generateNgrams($term));
    }

    /**
     * Clear all n-grams for a site
     *
     * @param int $siteId The site ID
     * @return void
     */
    protected function clearNgrams(int $siteId): void
    {
        $this->database->selectCollection('ngrams')->deleteMany(['siteId' => $siteId]);
        $this->database->selectCollection('ngram_index')->deleteMany(['siteId' => $siteId]);
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
        $this->database->selectCollection('ngrams')->deleteMany([
            'term' => $term,
            'siteId' => $siteId,
        ]);
        $this->database->selectCollection('ngram_index')->deleteOne([
            'term' => $term,
            'siteId' => $siteId,
        ]);
    }
}
