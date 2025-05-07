<?php

namespace MadeByBramble\BrambleSearch\adapters;

use MadeByBramble\BrambleSearch\Plugin;
use Redis;

class RedisSearchAdapter extends BaseSearchAdapter
{
    protected Redis $redis;

    public function init(): void
    {
        parent::init();
        $settings = Plugin::getInstance()->getSettings();

        $this->redis = new Redis();
        $this->redis->connect($settings->redisHost, $settings->redisPort);
        if ($settings->redisPassword) {
            $this->redis->auth($settings->redisPassword);
        }
    }

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

    protected function removeTermDocument(string $term, int $siteId, int $elementId): void
    {
        $termKey = $this->prefix . "term:$term";
        $this->redis->zRem($termKey, "{$siteId}:{$elementId}");
    }

    protected function deleteDocument(int $siteId, int $elementId): void
    {
        $docKey = $this->prefix . "doc:{$siteId}:{$elementId}";
        $this->redis->del($docKey);
    }

    protected function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLen): void
    {
        $docKey = $this->prefix . "doc:{$siteId}:{$elementId}";

        foreach ($termFreqs as $term => $freq) {
            $this->redis->hSet($docKey, $term, $freq);
        }

        $this->redis->hSet($docKey, '_length', $docLen);
    }

    protected function storeTermDocument(string $term, int $siteId, int $elementId, int $freq): void
    {
        $termKey = $this->prefix . "term:$term";
        $this->redis->zAdd($termKey, $freq, "{$siteId}:{$elementId}");
    }

    protected function addDocumentToIndex(int $siteId, int $elementId): void
    {
        $metaKey = $this->prefix . "meta";
        $this->redis->sAdd($metaKey . ':docs', "{$siteId}:{$elementId}");
    }

    protected function updateTotalDocCount(): void
    {
        $metaKey = $this->prefix . "meta";
        $docsKey = $metaKey . ':docs';
        $totalDocs = $this->redis->sCard($docsKey);
        $this->redis->set($metaKey . ':totalDocs', $totalDocs);
    }

    protected function updateTotalLength(int $docLen): void
    {
        $metaKey = $this->prefix . "meta";
        $this->redis->incrBy($metaKey . ':totalLength', $docLen);
    }

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

    protected function getTitleTerms(string $docId): array
    {
        $titleKey = $this->prefix . "title:$docId";
        $terms = $this->redis->sMembers($titleKey);

        if ($terms === false || !is_array($terms)) {
            return [];
        }

        return array_flip($terms); // Convert to associative array for faster lookups
    }

    protected function deleteTitleTerms(int $siteId, int $elementId): void
    {
        $titleKey = $this->prefix . "title:{$siteId}:{$elementId}";
        $this->redis->del($titleKey);
    }

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
     * Remove a document from the index
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return void
     */
    protected function removeDocumentFromIndex(int $siteId, int $elementId): void
    {
        $metaKey = $this->prefix . "meta";
        $docsKey = $metaKey . ':docs';
        $this->redis->sRem($docsKey, "{$siteId}:{$elementId}");
    }

    /**
     * Reset the total length counter
     *
     * @return void
     */
    protected function resetTotalLength(): void
    {
        $metaKey = $this->prefix . "meta";
        $totalLengthKey = $metaKey . ':totalLength';
        $this->redis->set($totalLengthKey, 0);
    }

    /**
     * Remove a term from the index
     *
     * @param string $term The term to remove
     * @return void
     */
    protected function removeTermFromIndex(string $term): void
    {
        $termKey = $this->prefix . "term:$term";
        $this->redis->del($termKey);
    }
}
