<?php

namespace MadeByBramble\BrambleSearch\adapters;

use Craft;

/**
 * Craft Cache Search Adapter
 *
 * Implements the search adapter using Craft's built-in cache system.
 * Suitable for development and smaller sites.
 */
class CraftCacheSearchAdapter extends BaseSearchAdapter
{
    protected function getIndexedDocumentIds(): array
    {
        $ids = Craft::$app->getCache()->get($this->prefix . 'meta:docs');
        if ($ids === false) {
            return [];
        }
        if (!is_array($ids)) {
            throw new \RuntimeException('Invalid search document catalogue.');
        }

        return array_keys($ids);
    }
    protected function documentExists(int $siteId, int $elementId): bool
    {
        return Craft::$app->getCache()->exists($this->prefix . "doc:$siteId:$elementId");
    }

    protected function termNgramsCurrent(string $term, int $siteId): bool
    {
        return Craft::$app->getCache()->get($this->prefix . "ngrams:$siteId:$term") === $this->generateNgrams($term);
    }
    protected function getStorageNamespace(): array
    {
        $cache = Craft::$app->getCache();
        $properties = array_intersect_key(get_object_vars($cache), array_flip(['keyPrefix', 'cachePath', 'cacheTable']));
        if ($cache instanceof \yii\redis\Cache) {
            $connection = \yii\di\Instance::ensure($cache->redis, \yii\redis\ConnectionInterface::class);
            $connectionProperties = get_object_vars($connection);
            $properties['redis'] = [$connectionProperties['hostname'] ?? null, $connectionProperties['port'] ?? null, $connectionProperties['database'] ?? null];
        }

        return ['driver' => 'craft', 'backend' => $cache::class, 'namespace' => $properties];
    }

    protected function readIndexRecord(string $key): ?array
    {
        $value = Craft::$app->getCache()->get($this->prefix . 'state:' . $key);

        return $value === false ? null : $value;
    }

    protected function readIndexRecordsBatch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $cacheKeys = array_map(fn(string $key): string => $this->prefix . 'state:' . $key, $keys);
        $values = Craft::$app->getCache()->multiGet($cacheKeys);
        $records = [];
        foreach ($keys as $index => $key) {
            $records[$key] = ($values[$cacheKeys[$index]] ?? false) === false ? null : $values[$cacheKeys[$index]];
        }

        return $records;
    }

    protected function writeIndexRecord(string $key, array $value): void
    {
        $this->setPersistent($this->prefix . 'state:' . $key, $value);
    }

    protected function deleteIndexRecord(string $key): void
    {
        $cacheKey = $this->prefix . 'state:' . $key;
        $cache = Craft::$app->getCache();
        if ($cache->exists($cacheKey) && !$cache->delete($cacheKey)) {
            throw new \RuntimeException('Unable to remove search state from cache.');
        }
    }

    protected function writeIndexTotals(int $documents, int $length): void
    {
        if (Craft::$app->getCache()->multiSet([$this->prefix . 'meta:totalDocs' => $documents, $this->prefix . 'meta:totalLength' => $length], 0) !== []) {
            throw new \RuntimeException('Unable to publish search cache metadata.');
        }
    }

    private function setPersistent(string $key, mixed $value): void
    {
        if (!Craft::$app->getCache()->set($key, $value, 0)) {
            throw new \RuntimeException('Unable to persist search cache data.');
        }
    }

    private function deletePersistent(string $key): void
    {
        $cache = Craft::$app->getCache();
        if ($cache->exists($key) && !$cache->delete($key)) {
            throw new \RuntimeException('Unable to remove search cache data.');
        }
    }

    protected function writePostingChanges(int $siteId, int $elementId, array $frequencies): void
    {
        if ($frequencies === []) {
            return;
        }
        $cache = Craft::$app->getCache();
        $keys = array_map(fn(string|int $term): string => $this->prefix . 'term:' . $term, array_keys($frequencies));
        $values = $cache->multiGet($keys);
        $writes = [];
        foreach ($frequencies as $term => $frequency) {
            $key = $this->prefix . 'term:' . $term;
            $documents = $values[$key] === false ? [] : $values[$key];
            $documents["$siteId:$elementId"] = $frequency;
            $writes[$key] = $documents;
        }
        $catalogueKey = $this->prefix . 'all_terms';
        $catalogue = $cache->get($catalogueKey);
        $catalogue = is_array($catalogue) ? array_map('strval', $catalogue) : [];
        $writes[$catalogueKey] = array_values(array_unique([...$catalogue, ...array_map('strval', array_keys($frequencies))]));
        if ($cache->multiSet($writes, 0) !== []) {
            throw new \RuntimeException('Unable to publish search cache postings.');
        }
    }
    // =========================================================================
    // DOCUMENT OPERATIONS
    // =========================================================================

    /**
     * Get all terms for a document from Craft cache
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return array The terms and their frequencies
     */
    protected function getDocumentTerms(int $siteId, int $elementId): array
    {
        $docKey = $this->prefix . "doc:{$siteId}:{$elementId}";
        $docData = Craft::$app->cache->get($docKey);

        // Ensure we handle false return from cache
        if ($docData === false || !is_array($docData)) {
            return [];
        }

        return $docData['terms'] ?? [];
    }

    /**
     * Get the length of a document in tokens
     *
     * @param string $docId The document ID (siteId:elementId)
     * @return int The document length
     */
    protected function getDocumentLength(string $docId): int
    {
        $docKey = $this->prefix . "doc:$docId";
        $docData = Craft::$app->cache->get($docKey);

        // Handle false return from cache
        if ($docData === false || !is_array($docData)) {
            return 0;
        }

        return (int)($docData['_length'] ?? 0);
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

        $lengths = [];

        // Note: Craft cache doesn't support batch operations, so we iterate
        foreach ($docIds as $docId) {
            $docKey = $this->prefix . "doc:$docId";
            $docData = Craft::$app->cache->get($docKey);

            if ($docData === false || !is_array($docData)) {
                $lengths[$docId] = 0;
            } else {
                $lengths[$docId] = (int)($docData['_length'] ?? 0);
            }
        }

        return $lengths;
    }

    /**
     * Delete a document from the cache
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function deleteDocument(int $siteId, int $elementId): void
    {
        $docKey = $this->prefix . "doc:{$siteId}:{$elementId}";
        $this->deletePersistent($docKey);
    }

    /**
     * Store a document in the cache
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param array $termFreqs The terms and their frequencies
     * @param int $docLen The document length
     */
    protected function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLen): void
    {
        $docKey = $this->prefix . "doc:{$siteId}:{$elementId}";
        $docData = [
            'terms' => $termFreqs,
            '_length' => $docLen,
        ];

        $this->setPersistent($docKey, $docData);
    }



    // =========================================================================
    // TERM OPERATIONS
    // =========================================================================

    protected function getTermDocumentsBatch(array $terms): array
    {
        $terms = array_map('strval', $terms);
        if ($terms === []) {
            return [];
        }
        $keys = array_map(fn(string $term): string => $this->prefix . 'term:' . $term, $terms);
        $values = Craft::$app->getCache()->multiGet($keys);
        $result = [];
        foreach ($terms as $index => $term) {
            $value = $values[$keys[$index]] ?? false;
            $result[$term] = is_array($value) ? $value : [];
        }

        return $result;
    }

    /**
     * Get all documents containing a specific term
     *
     * @param string $term The term to look up
     * @return array The documents containing the term and their frequencies
     */
    protected function getTermDocuments(string $term): array
    {
        $termKey = $this->prefix . "term:$term";
        $result = Craft::$app->cache->get($termKey);

        // Ensure we always return an array, even if the cache returns false
        if ($result === false || !is_array($result)) {
            return [];
        }

        return $result;
    }

    /**
     * Get all terms in the index
     *
     * Uses a cached list of terms or rebuilds it if not available
     *
     * @return array All terms in the index
     */
    protected function getAllTerms(): array
    {
        return $this->withPublicationGuard(function(): array {
            $allTermsKey = $this->prefix . 'all_terms';
            $terms = Craft::$app->cache->get($allTermsKey);

            if ($terms === false || !is_array($terms)) {
                // This is a fallback method to find all terms
                // It's less efficient but necessary for the first run
                $terms = [];
                $docs = Craft::$app->cache->get($this->prefix . 'meta:docs');

                if ($docs === false || !is_array($docs)) {
                    return [];
                }

                foreach (array_keys($docs) as $docId) {
                    $docKey = $this->prefix . "doc:$docId";
                    $docData = Craft::$app->cache->get($docKey);

                    if ($docData === false || !is_array($docData)) {
                        continue;
                    }

                    if (isset($docData['terms']) && is_array($docData['terms'])) {
                        $terms = array_merge($terms, array_keys($docData['terms']));
                    }
                }

                $terms = array_unique($terms);
                // Cache the terms for future use
                $this->setPersistent($allTermsKey, $terms);
            }

            return $terms;
        });
    }

    /**
     * Add a term to the index of all terms
     *
     * @param string $term The term to add
     */
    protected function addTermToIndex(string $term): void
    {
        $this->withPublicationGuard(function() use ($term): void {
            $allTermsKey = $this->prefix . 'all_terms';
            $terms = Craft::$app->cache->get($allTermsKey);

            if ($terms === false || !is_array($terms)) {
                $terms = [];
            }

            $terms = array_map('strval', $terms);
            if (!in_array($term, $terms, true)) {
                $terms[] = $term;
                $this->setPersistent($allTermsKey, $terms);
            }
        });
    }

    /**
     * Remove a term-document association from the cache
     *
     * @param string $term The term
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function removeTermDocument(string $term, int $siteId, int $elementId): void
    {
        $this->withPublicationGuard(function() use ($term, $siteId, $elementId): void {
            $termKey = $this->prefix . "term:$term";
            $termDocs = Craft::$app->cache->get($termKey);

            // Handle false return from cache
            if ($termDocs === false || !is_array($termDocs)) {
                return;
            }

            if (isset($termDocs["{$siteId}:{$elementId}"])) {
                unset($termDocs["{$siteId}:{$elementId}"]);
                $this->setPersistent($termKey, $termDocs);
            }
        });
    }

    /**
     * Store a term-document association in the cache
     *
     * @param string $term The term
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param int $freq The term frequency
     */
    protected function storeTermDocument(string $term, int $siteId, int $elementId, int $freq): void
    {
        $this->withPublicationGuard(function() use ($term, $siteId, $elementId, $freq): void {
            $termKey = $this->prefix . "term:$term";
            $termDocs = Craft::$app->cache->get($termKey);

            // Handle false return from cache
            if ($termDocs === false || !is_array($termDocs)) {
                $termDocs = [];
            }

            $termDocs["{$siteId}:{$elementId}"] = $freq;

            $this->setPersistent($termKey, $termDocs);

            // Update the all terms index
            $this->addTermToIndex($term);
        });
    }



    /**
     * Remove a term from the index completely
     *
     * @param string $term The term to remove
     */
    protected function removeTermFromIndex(string $term): void
    {
        $this->withPublicationGuard(function() use ($term): void {
            // Remove the term from the all_terms list
            $allTermsKey = $this->prefix . 'all_terms';
            $terms = Craft::$app->cache->get($allTermsKey);

            if ($terms !== false && is_array($terms)) {
                $terms = array_map('strval', $terms);
                $key = array_search($term, $terms, true);
                if ($key !== false) {
                    unset($terms[$key]);
                    $this->setPersistent($allTermsKey, array_values($terms));
                }
            }

            // Delete the term's document list
            $termKey = $this->prefix . "term:$term";
            $this->deletePersistent($termKey);
        });
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
            $metaKey = $this->prefix . "meta";
            $docsKey = $metaKey . ':docs';
            $docs = Craft::$app->cache->get($docsKey);

            // Handle false return from cache
            if ($docs === false || !is_array($docs)) {
                $docs = [];
            }

            $docs["{$siteId}:{$elementId}"] = true;

            $this->setPersistent($docsKey, $docs);
        });
    }

    /**
     * Update the total document count in the index metadata
     */
    protected function updateTotalDocCount(): void
    {
        $this->withPublicationGuard(function(): void {
            $metaKey = $this->prefix . "meta";
            $docsKey = $metaKey . ':docs';
            $docs = Craft::$app->cache->get($docsKey);

            // Handle false return from cache
            if ($docs === false || !is_array($docs)) {
                $docs = [];
            }

            $totalDocs = count($docs);

            $this->setPersistent($metaKey . ':totalDocs', $totalDocs);
        });
    }

    /**
     * Update the total token length in the index metadata
     *
     * @param int $docLen The document length to add
     */
    protected function updateTotalLength(int $docLen): void
    {
        $this->withPublicationGuard(function() use ($docLen): void {
            $metaKey = $this->prefix . "meta";
            $totalLengthKey = $metaKey . ':totalLength';
            $totalLength = Craft::$app->cache->get($totalLengthKey);

            // Handle false return from cache
            if ($totalLength === false || !is_numeric($totalLength)) {
                $totalLength = 0;
            }

            $this->setPersistent($totalLengthKey, $totalLength + $docLen);
        });
    }

    /**
     * Get the total document count from the index metadata
     *
     * @return int The total document count
     */
    protected function getTotalDocCount(): int
    {
        $this->ensureIndexMetadata();
        $value = Craft::$app->getCache()->get($this->prefix . 'meta:totalDocs');

        return $this->requireMetadataCounter($value);
    }

    /**
     * Get the total token length from the index metadata
     *
     * @return int The total token length
     */
    protected function getTotalLength(): int
    {
        $this->ensureIndexMetadata();
        $value = Craft::$app->getCache()->get($this->prefix . 'meta:totalLength');

        return $this->requireMetadataCounter($value);
    }

    // =========================================================================
    // TITLE OPERATIONS
    // =========================================================================

    /**
     * Store title terms for a document
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param array $titleTerms The title terms
     */
    protected function storeTitleTerms(int $siteId, int $elementId, array $titleTerms): void
    {
        $titleKey = $this->prefix . "title:{$siteId}:{$elementId}";
        $this->setPersistent($titleKey, $titleTerms);
    }

    /**
     * Get title terms for a document
     *
     * @param string $docId The document ID (siteId:elementId)
     * @return array The title terms
     */
    protected function getTitleTerms(string $docId): array
    {
        $titleKey = $this->prefix . "title:$docId";
        $terms = Craft::$app->cache->get($titleKey);

        if ($terms === false || !is_array($terms)) {
            return [];
        }

        return $terms;
    }

    /**
     * Delete title terms for a document
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function deleteTitleTerms(int $siteId, int $elementId): void
    {
        $titleKey = $this->prefix . "title:{$siteId}:{$elementId}";
        $this->deletePersistent($titleKey);
    }

    // =========================================================================
    // SITE OPERATIONS
    // =========================================================================

    /**
     * Get all documents for a specific site
     *
     * @param int $siteId The site ID
     * @return array The document IDs
     */
    protected function getSiteDocuments(int $siteId): array
    {
        $metaKey = $this->prefix . "meta";
        $docsKey = $metaKey . ':docs';
        $allDocs = Craft::$app->cache->get($docsKey);

        // Handle false return from cache
        if ($allDocs === false || !is_array($allDocs)) {
            return [];
        }

        // Filter documents by site ID
        $sitePrefix = "$siteId:";
        $siteDocs = [];

        foreach (array_keys($allDocs) as $docId) {
            if (strpos($docId, $sitePrefix) === 0) {
                $siteDocs[] = $docId;
            }
        }

        return $siteDocs;
    }

    /**
     * Remove a document from the index metadata
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function removeDocumentFromIndex(int $siteId, int $elementId): void
    {
        $this->withPublicationGuard(function() use ($siteId, $elementId): void {
            $metaKey = $this->prefix . "meta";
            $docsKey = $metaKey . ':docs';
            $docs = Craft::$app->cache->get($docsKey);

            // Handle false return from cache
            if ($docs === false || !is_array($docs)) {
                return;
            }

            $docId = "{$siteId}:{$elementId}";
            if (isset($docs[$docId])) {
                unset($docs[$docId]);
                $this->setPersistent($docsKey, $docs);
            }
        });
    }

    /**
     * Reset the total length counter to zero
     */
    protected function resetTotalLength(): void
    {
        $this->withPublicationGuard(function(): void {
            $metaKey = $this->prefix . "meta";
            $totalLengthKey = $metaKey . ':totalLength';
            $this->setPersistent($totalLengthKey, 0);
        });
    }

    // =========================================================================
    // N-GRAM OPERATIONS (Basic cache implementation)
    // =========================================================================

    /**
     * Store n-grams for a term in cache
     */
    protected function storeTermNgrams(string $term, array $ngrams, int $siteId): void
    {
        if (empty($ngrams)) {
            return;
        }

        $ngramKey = $this->prefix . "ngrams:{$siteId}:{$term}";
        $this->setPersistent($ngramKey, $ngrams);
    }

    /**
     * Get terms that have similar n-grams to the search term (basic implementation)
     */
    protected function getTermsByNgramSimilarity(array $ngrams, int $siteId, float $threshold): array
    {
        if (empty($ngrams)) {
            return [];
        }

        $ngrams = array_values(array_unique($ngrams));
        $matches = [];

        foreach ($this->getAllTerms() as $term) {
            $termNgrams = Craft::$app->cache->get($this->prefix . "ngrams:{$siteId}:{$term}");
            if ($termNgrams === false || !is_array($termNgrams)) {
                continue;
            }

            $similarity = $this->calculateNgramSimilarity($ngrams, array_values(array_unique($termNgrams)));
            if ($similarity >= $threshold) {
                $matches[$term] = $similarity;
            }
        }

        arsort($matches);

        return $matches;
    }

    /**
     * Check if a term already has n-grams stored
     */
    protected function termHasNgrams(string $term, int $siteId): bool
    {
        $ngramKey = $this->prefix . "ngrams:{$siteId}:{$term}";
        return Craft::$app->cache->exists($ngramKey);
    }

    /**
     * Clear all n-grams for a site
     */
    protected function clearNgrams(int $siteId): void
    {
        $this->withPublicationGuard(function() use ($siteId): void {
            foreach ($this->getAllTerms() as $term) {
                $this->removeTermNgrams((string)$term, $siteId);
            }
        });
    }

    /**
     * Remove n-grams for a specific term
     */
    protected function removeTermNgrams(string $term, int $siteId): void
    {
        $ngramKey = $this->prefix . "ngrams:{$siteId}:{$term}";
        $this->deletePersistent($ngramKey);
    }
}
