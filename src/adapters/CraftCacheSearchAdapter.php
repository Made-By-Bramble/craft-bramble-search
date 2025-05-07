<?php

namespace MadeByBramble\BrambleSearch\adapters;

use Craft;

class CraftCacheSearchAdapter extends BaseSearchAdapter
{
    public function __construct()
    {
        parent::__construct();
    }

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

    protected function deleteDocument(int $siteId, int $elementId): void
    {
        $docKey = $this->prefix . "doc:{$siteId}:{$elementId}";
        Craft::$app->cache->delete($docKey);
    }

    protected function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLen): void
    {
        $docKey = $this->prefix . "doc:{$siteId}:{$elementId}";
        $docData = [
            'terms' => $termFreqs,
            '_length' => $docLen
        ];

        Craft::$app->cache->set($docKey, $docData);
    }

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

    protected function getTotalDocCount(): int
    {
        $metaKey = $this->prefix . "meta";
        $totalDocsKey = $metaKey . ':totalDocs';

        return (int)(Craft::$app->cache->get($totalDocsKey) ?? 0);
    }

    protected function getTotalLength(): int
    {
        $metaKey = $this->prefix . "meta";
        $totalLengthKey = $metaKey . ':totalLength';

        return (int)(Craft::$app->cache->get($totalLengthKey) ?? 0);
    }

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
     * @return void
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

    protected function storeTitleTerms(int $siteId, int $elementId, array $titleTerms): void
    {
        $titleKey = $this->prefix . "title:{$siteId}:{$elementId}";
        Craft::$app->cache->set($titleKey, $titleTerms);
    }

    protected function getTitleTerms(string $docId): array
    {
        $titleKey = $this->prefix . "title:$docId";
        $terms = Craft::$app->cache->get($titleKey);

        if ($terms === false || !is_array($terms)) {
            return [];
        }

        return $terms;
    }

    protected function deleteTitleTerms(int $siteId, int $elementId): void
    {
        $titleKey = $this->prefix . "title:{$siteId}:{$elementId}";
        Craft::$app->cache->delete($titleKey);
    }
}
