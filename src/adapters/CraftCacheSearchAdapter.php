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
        Craft::$app->cache->delete($docKey);
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

        Craft::$app->cache->set($docKey, $docData);
    }



    // =========================================================================
    // TERM OPERATIONS
    // =========================================================================

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
            Craft::$app->cache->set($allTermsKey, $terms);
        }

        return $terms;
    }

    /**
     * Add a term to the index of all terms
     *
     * @param string $term The term to add
     */
    protected function addTermToIndex(string $term): void
    {
        $allTermsKey = $this->prefix . 'all_terms';
        $terms = Craft::$app->cache->get($allTermsKey);

        if ($terms === false || !is_array($terms)) {
            $terms = [];
        }

        if (!in_array($term, $terms)) {
            $terms[] = $term;
            Craft::$app->cache->set($allTermsKey, $terms);
        }
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
        $termKey = $this->prefix . "term:$term";
        $termDocs = Craft::$app->cache->get($termKey);

        // Handle false return from cache
        if ($termDocs === false || !is_array($termDocs)) {
            return;
        }

        if (isset($termDocs["{$siteId}:{$elementId}"])) {
            unset($termDocs["{$siteId}:{$elementId}"]);
            Craft::$app->cache->set($termKey, $termDocs);
        }
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
        $termKey = $this->prefix . "term:$term";
        $termDocs = Craft::$app->cache->get($termKey);

        // Handle false return from cache
        if ($termDocs === false || !is_array($termDocs)) {
            $termDocs = [];
        }

        $termDocs["{$siteId}:{$elementId}"] = $freq;

        Craft::$app->cache->set($termKey, $termDocs);

        // Update the all terms index
        $this->addTermToIndex($term);
    }



    /**
     * Remove a term from the index completely
     *
     * @param string $term The term to remove
     */
    protected function removeTermFromIndex(string $term): void
    {
        // Remove the term from the all_terms list
        $allTermsKey = $this->prefix . 'all_terms';
        $terms = Craft::$app->cache->get($allTermsKey);

        if ($terms !== false && is_array($terms)) {
            $key = array_search($term, $terms);
            if ($key !== false) {
                unset($terms[$key]);
                Craft::$app->cache->set($allTermsKey, array_values($terms));
            }
        }

        // Delete the term's document list
        $termKey = $this->prefix . "term:$term";
        Craft::$app->cache->delete($termKey);
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
        $metaKey = $this->prefix . "meta";
        $docsKey = $metaKey . ':docs';
        $docs = Craft::$app->cache->get($docsKey);

        // Handle false return from cache
        if ($docs === false || !is_array($docs)) {
            $docs = [];
        }

        $docs["{$siteId}:{$elementId}"] = true;

        Craft::$app->cache->set($docsKey, $docs);
    }

    /**
     * Update the total document count in the index metadata
     */
    protected function updateTotalDocCount(): void
    {
        $metaKey = $this->prefix . "meta";
        $docsKey = $metaKey . ':docs';
        $docs = Craft::$app->cache->get($docsKey);

        // Handle false return from cache
        if ($docs === false || !is_array($docs)) {
            $docs = [];
        }

        $totalDocs = count($docs);

        Craft::$app->cache->set($metaKey . ':totalDocs', $totalDocs);
    }

    /**
     * Update the total token length in the index metadata
     *
     * @param int $docLen The document length to add
     */
    protected function updateTotalLength(int $docLen): void
    {
        $metaKey = $this->prefix . "meta";
        $totalLengthKey = $metaKey . ':totalLength';
        $totalLength = Craft::$app->cache->get($totalLengthKey);

        // Handle false return from cache
        if ($totalLength === false || !is_numeric($totalLength)) {
            $totalLength = 0;
        }

        Craft::$app->cache->set($totalLengthKey, $totalLength + $docLen);
    }

    /**
     * Get the total document count from the index metadata
     *
     * @return int The total document count
     */
    protected function getTotalDocCount(): int
    {
        $metaKey = $this->prefix . "meta";
        $totalDocsKey = $metaKey . ':totalDocs';

        return (int)(Craft::$app->cache->get($totalDocsKey) ?? 0);
    }

    /**
     * Get the total token length from the index metadata
     *
     * @return int The total token length
     */
    protected function getTotalLength(): int
    {
        $metaKey = $this->prefix . "meta";
        $totalLengthKey = $metaKey . ':totalLength';

        return (int)(Craft::$app->cache->get($totalLengthKey) ?? 0);
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
        Craft::$app->cache->set($titleKey, $titleTerms);
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
        Craft::$app->cache->delete($titleKey);
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
            Craft::$app->cache->set($docsKey, $docs);
        }
    }

    /**
     * Reset the total length counter to zero
     */
    protected function resetTotalLength(): void
    {
        $metaKey = $this->prefix . "meta";
        $totalLengthKey = $metaKey . ':totalLength';
        Craft::$app->cache->set($totalLengthKey, 0);
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
        Craft::$app->cache->set($ngramKey, $ngrams);
    }

    /**
     * Get terms that have similar n-grams to the search term (basic implementation)
     */
    protected function getTermsByNgramSimilarity(array $ngrams, int $siteId, float $threshold): array
    {
        // Simple fallback - cache adapter will use brute force fuzzy search
        return [];
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
        // Cache doesn't support pattern-based deletion, so this is a no-op
        // N-grams will expire naturally or be overwritten
    }

    /**
     * Remove n-grams for a specific term
     */
    protected function removeTermNgrams(string $term, int $siteId): void
    {
        $ngramKey = $this->prefix . "ngrams:{$siteId}:{$term}";
        Craft::$app->cache->delete($ngramKey);
    }
}
