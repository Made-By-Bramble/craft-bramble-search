<?php

namespace MadeByBramble\BrambleSearch\adapters;

use MadeByBramble\BrambleSearch\Plugin;
use Redis;
use craft\helpers\App;

/**
 * Redis Search Adapter
 *
 * Implements the search adapter using Redis as the storage backend.
 * Provides better performance and persistence than the Craft Cache adapter.
 * Recommended for production sites.
 */
class RedisSearchAdapter extends BaseSearchAdapter
{
    // =========================================================================
    // PROPERTIES
    // =========================================================================

    /**
     * Redis connection instance
     */
    protected Redis $redis;

    // =========================================================================
    // INITIALIZATION METHODS
    // =========================================================================

    /**
     * Initialize the Redis connection
     */
    public function init(): void
    {
        parent::init();
        $settings = Plugin::getInstance()->getSettings();
        $redisHost = App::parseEnv('BRAMBLE_SEARCH_REDIS_HOST') ? $settings->redisHost : 'localhost';
        $redisPort = (int)(App::parseEnv('BRAMBLE_SEARCH_REDIS_PORT') ? $settings->redisPort : 6379);
        $redisPassword = App::parseEnv('BRAMBLE_SEARCH_REDIS_PASSWORD') ? $settings->redisPassword : null;

        $this->redis = new Redis();
        $this->redis->connect($redisHost, $redisPort);
        if ($redisPassword) {
            $this->redis->auth($redisPassword);
        }
    }

    // =========================================================================
    // DOCUMENT OPERATIONS
    // =========================================================================

    /**
     * Get all terms for a document from Redis
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return array The terms and their frequencies
     */
    protected function getDocumentTerms(int $siteId, int $elementId): array
    {
        $docKey = $this->prefix . "doc:{$siteId}:{$elementId}";
        $terms = $this->redis->hGetAll($docKey);

        // Handle false return
        if ($terms === false || !is_array($terms)) {
            return [];
        }

        // Remove the _length key which isn't a term
        if (isset($terms['_length'])) {
            unset($terms['_length']);
        }

        return $terms;
    }



    /**
     * Delete a document from Redis
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function deleteDocument(int $siteId, int $elementId): void
    {
        $docKey = $this->prefix . "doc:{$siteId}:{$elementId}";
        $this->redis->del($docKey);
    }

    /**
     * Store a document in Redis using hash
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param array $termFreqs The terms and their frequencies
     * @param int $docLen The document length
     */
    protected function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLen): void
    {
        $docKey = $this->prefix . "doc:{$siteId}:{$elementId}";

        foreach ($termFreqs as $term => $freq) {
            $this->redis->hSet($docKey, $term, $freq);
        }

        $this->redis->hSet($docKey, '_length', $docLen);
    }

    /**
     * Get the length of a document in tokens from Redis
     *
     * @param string $docId The document ID (siteId:elementId)
     * @return int The document length
     */
    protected function getDocumentLength(string $docId): int
    {
        $docKey = $this->prefix . "doc:$docId";
        $result = $this->redis->hGet($docKey, '_length');

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
        $metaKey = $this->prefix . "meta";
        $this->redis->sAdd($metaKey . ':docs', "{$siteId}:{$elementId}");
    }

    /**
     * Update the total document count in Redis
     */
    protected function updateTotalDocCount(): void
    {
        $metaKey = $this->prefix . "meta";
        $docsKey = $metaKey . ':docs';
        $totalDocs = $this->redis->sCard($docsKey);
        $this->redis->set($metaKey . ':totalDocs', $totalDocs);
    }

    /**
     * Update the total token length in Redis
     *
     * @param int $docLen The document length to add
     */
    protected function updateTotalLength(int $docLen): void
    {
        $metaKey = $this->prefix . "meta";
        $this->redis->incrBy($metaKey . ':totalLength', $docLen);
    }

    /**
     * Get the total document count from Redis
     *
     * @return int The total document count
     */
    protected function getTotalDocCount(): int
    {
        $metaKey = $this->prefix . "meta";
        $result = $this->redis->get($metaKey . ':totalDocs');

        // Handle false return
        if ($result === false || !is_numeric($result)) {
            return 0;
        }

        return (int)$result;
    }

    /**
     * Get the total token length from Redis
     *
     * @return int The total token length
     */
    protected function getTotalLength(): int
    {
        $metaKey = $this->prefix . "meta";
        $result = $this->redis->get($metaKey . ':totalLength');

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
     * Store a term-document association in Redis using sorted set
     *
     * @param string $term The term
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param int $freq The term frequency
     */
    protected function storeTermDocument(string $term, int $siteId, int $elementId, int $freq): void
    {
        $termKey = $this->prefix . "term:$term";
        $this->redis->zAdd($termKey, $freq, "{$siteId}:{$elementId}");
    }

    /**
     * Remove a term-document association from Redis
     *
     * @param string $term The term
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function removeTermDocument(string $term, int $siteId, int $elementId): void
    {
        $termKey = $this->prefix . "term:$term";
        $this->redis->zRem($termKey, "{$siteId}:{$elementId}");
    }

    /**
     * Get all documents containing a specific term from Redis
     *
     * @param string $term The term to look up
     * @return array The documents containing the term and their frequencies
     */
    protected function getTermDocuments(string $term): array
    {
        $termKey = $this->prefix . "term:$term";
        $result = $this->redis->zRange($termKey, 0, -1, true);

        // Ensure we always return an array
        if ($result === false || !is_array($result)) {
            return [];
        }

        return $result;
    }



    /**
     * Get all terms in the index from Redis
     *
     * @return array All terms in the index
     */
    protected function getAllTerms(): array
    {
        $allKeys = $this->redis->keys($this->prefix . 'term:*');

        // Handle false return
        if ($allKeys === false || !is_array($allKeys)) {
            return [];
        }

        $terms = [];

        foreach ($allKeys as $key) {
            $terms[] = substr($key, strlen($this->prefix . 'term:'));
        }

        return $terms;
    }

    /**
     * Remove a term from the index in Redis
     *
     * @param string $term The term to remove
     */
    protected function removeTermFromIndex(string $term): void
    {
        $termKey = $this->prefix . "term:$term";
        $this->redis->del($termKey);
    }

    // =========================================================================
    // TITLE OPERATIONS
    // =========================================================================

    /**
     * Store title terms for a document in Redis
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param array $titleTerms The title terms
     */
    protected function storeTitleTerms(int $siteId, int $elementId, array $titleTerms): void
    {
        $titleKey = $this->prefix . "title:{$siteId}:{$elementId}";

        // Delete existing title terms
        $this->redis->del($titleKey);

        // Store new title terms
        if (!empty($titleTerms)) {
            foreach (array_keys($titleTerms) as $term) {
                $this->redis->sAdd($titleKey, $term);
            }
        }
    }

    /**
     * Get title terms for a document from Redis
     *
     * @param string $docId The document ID (siteId:elementId)
     * @return array The title terms
     */
    protected function getTitleTerms(string $docId): array
    {
        $titleKey = $this->prefix . "title:$docId";
        $terms = $this->redis->sMembers($titleKey);

        if ($terms === false || !is_array($terms)) {
            return [];
        }

        return array_flip($terms); // Convert to associative array for faster lookups
    }

    /**
     * Delete title terms for a document from Redis
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function deleteTitleTerms(int $siteId, int $elementId): void
    {
        $titleKey = $this->prefix . "title:{$siteId}:{$elementId}";
        $this->redis->del($titleKey);
    }

    // =========================================================================
    // SITE OPERATIONS
    // =========================================================================

    /**
     * Get all documents for a specific site from Redis
     *
     * @param int $siteId The site ID
     * @return array The document IDs
     */
    protected function getSiteDocuments(int $siteId): array
    {
        $metaKey = $this->prefix . "meta";
        $docsKey = $metaKey . ':docs';
        $allDocs = $this->redis->sMembers($docsKey);

        // Handle false return
        if ($allDocs === false || !is_array($allDocs)) {
            return [];
        }

        // Filter documents by site ID
        $sitePrefix = "$siteId:";
        $siteDocs = [];

        foreach ($allDocs as $docId) {
            if (strpos($docId, $sitePrefix) === 0) {
                $siteDocs[] = $docId;
            }
        }

        return $siteDocs;
    }

    /**
     * Remove a document from the index metadata in Redis
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function removeDocumentFromIndex(int $siteId, int $elementId): void
    {
        $metaKey = $this->prefix . "meta";
        $docsKey = $metaKey . ':docs';
        $this->redis->sRem($docsKey, "{$siteId}:{$elementId}");
    }

    /**
     * Reset the total length counter to zero in Redis
     */
    protected function resetTotalLength(): void
    {
        $metaKey = $this->prefix . "meta";
        $totalLengthKey = $metaKey . ':totalLength';
        $this->redis->set($totalLengthKey, 0);
    }
}
