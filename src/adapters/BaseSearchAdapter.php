<?php

namespace MadeByBramble\BrambleSearch\adapters;

use Craft;
use craft\services\Search;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;

/**
 * Base Search Adapter
 *
 * Abstract base class for all search adapters that implements common functionality
 * and defines abstract methods that must be implemented by storage-specific adapters.
 */
abstract class BaseSearchAdapter extends Search
{
    protected string $prefix = 'bramble_search:';

    protected float $k1 = 1.5;
    protected float $b = 0.75;

    protected array $stopWords = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function init(): void
    {
        parent::init();
        $this->loadStopWords();
    }

    protected function loadStopWords(): void
    {
        $this->stopWords = require Craft::getAlias('@bramble_search/stopwords/en.php');
    }

    public function indexElementAttributes(ElementInterface $element, array|null $fieldHandles = null): bool
    {
        if (!$element->id || !$element->siteId || !$element->enabled) {
            return true;
        }

        $text = $element->title ?? '';

        foreach ($fieldHandles ?? [] as $handle) {
            $fieldValue = $element->getFieldValue($handle);
            if (is_string($fieldValue)) {
                $text .= ' ' . $fieldValue;
            }
        }

        $tokens = $this->tokenize($text);
        $tokens = $this->filterStopWords($tokens);
        $termFreqs = array_count_values($tokens);
        $docLen = count($tokens);

        // Get old terms to clean up
        $oldTerms = $this->getDocumentTerms($element->siteId, $element->id);
        foreach ($oldTerms as $term => $freq) {
            $this->removeTermDocument($term, $element->siteId, $element->id);
        }

        // Delete the old document
        $this->deleteDocument($element->siteId, $element->id);

        // Store new document data
        $this->storeDocument($element->siteId, $element->id, $termFreqs, $docLen);

        // Update term indices
        foreach ($termFreqs as $term => $freq) {
            $this->storeTermDocument($term, $element->siteId, $element->id, $freq);
        }

        // Update metadata
        $this->addDocumentToIndex($element->siteId, $element->id);
        $this->updateTotalDocCount();
        $this->updateTotalLength($docLen);

        return true;
    }

    public function searchElements(ElementQuery $elementQuery): array
    {
        $siteId = $elementQuery->siteId ?? Craft::$app->sites->currentSite->id;
        $tokens = $this->tokenize($elementQuery->search);
        $tokens = $this->filterStopWords($tokens);

        $totalDocs = max(1, $this->getTotalDocCount());
        $totalLength = max(1, $this->getTotalLength());
        $avgDocLength = $totalLength / $totalDocs;

        $docScores = [];

        foreach ($tokens as $term) {
            $termDocs = $this->getTermDocuments($term);

            // Exact match short-circuit
            if (!empty($termDocs)) {
                $docFreq = count($termDocs);
                foreach ($termDocs as $docId => $freq) {
                    if (!str_starts_with($docId, "$siteId:")) {
                        continue;
                    }

                    $docLen = max(1, $this->getDocumentLength($docId));

                    $score = $this->bm25($freq, $docFreq, $docLen, $avgDocLength);
                    $docScores[$docId] = ($docScores[$docId] ?? 0) + $score;
                }

                continue;
            }

            // Fuzzy fallback
            $fuzzyTerms = $this->findFuzzyMatches($term);
            foreach ($fuzzyTerms as $fuzzy) {
                $fuzzyDocs = $this->getTermDocuments($fuzzy);
                $docFreq = count($fuzzyDocs);

                foreach ($fuzzyDocs as $docId => $freq) {
                    if (!str_starts_with($docId, "$siteId:")) {
                        continue;
                    }

                    $docLen = max(1, $this->getDocumentLength($docId));

                    $score = $this->bm25($freq, $docFreq, $docLen, $avgDocLength);
                    $docScores[$docId] = ($docScores[$docId] ?? 0) + $score;
                }
            }
        }

        arsort($docScores);
        $results = [];

        // Convert our internal docId format (siteId:elementId) to Craft's expected format (elementId-siteId)
        foreach ($docScores as $docId => $score) {
            [$docSiteId, $elementId] = explode(':', $docId);
            $results["$elementId-$docSiteId"] = $score;
        }

        return $results;
    }

    protected function bm25($freq, $docFreq, $docLen, $avgDocLen): float
    {
        $totalDocs = $this->getTotalDocCount();
        $idf = log(1 + (($totalDocs - $docFreq + 0.5) / ($docFreq + 0.5)));
        return $idf * (($freq * ($this->k1 + 1)) / ($freq + $this->k1 * (1 - $this->b + $this->b * ($docLen / $avgDocLen))));
    }

    protected function findFuzzyMatches(string $term, int $maxDistance = 2): array
    {
        $matches = [];
        $allTerms = $this->getAllTerms();

        foreach ($allTerms as $candidate) {
            if (levenshtein($term, $candidate) <= $maxDistance) {
                $matches[] = $candidate;
            }
        }

        return $matches;
    }

    protected function tokenize(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        return array_filter(explode(' ', $text));
    }

    protected function filterStopWords(array $tokens): array
    {
        return array_filter($tokens, fn($t) => !in_array($t, $this->stopWords));
    }

    // Abstract methods that must be implemented by storage-specific adapters

    /**
     * Get all terms for a document
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return array The terms and their frequencies
     */
    abstract protected function getDocumentTerms(int $siteId, int $elementId): array;

    /**
     * Remove a term-document association
     *
     * @param string $term The term
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return void
     */
    abstract protected function removeTermDocument(string $term, int $siteId, int $elementId): void;

    /**
     * Delete a document from the index
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return void
     */
    abstract protected function deleteDocument(int $siteId, int $elementId): void;

    /**
     * Store a document in the index
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param array $termFreqs The terms and their frequencies
     * @param int $docLen The document length
     * @return void
     */
    abstract protected function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLen): void;

    /**
     * Store a term-document association
     *
     * @param string $term The term
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param int $freq The term frequency
     * @return void
     */
    abstract protected function storeTermDocument(string $term, int $siteId, int $elementId, int $freq): void;

    /**
     * Add a document to the index
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return void
     */
    abstract protected function addDocumentToIndex(int $siteId, int $elementId): void;

    /**
     * Update the total document count
     *
     * @return void
     */
    abstract protected function updateTotalDocCount(): void;

    /**
     * Update the total length
     *
     * @param int $docLen The document length to add
     * @return void
     */
    abstract protected function updateTotalLength(int $docLen): void;

    /**
     * Get the total document count
     *
     * @return int The total document count
     */
    abstract protected function getTotalDocCount(): int;

    /**
     * Get the total length
     *
     * @return int The total length
     */
    abstract protected function getTotalLength(): int;

    /**
     * Get all documents for a term
     *
     * @param string $term The term
     * @return array The documents and their frequencies
     */
    abstract protected function getTermDocuments(string $term): array;

    /**
     * Get the document length
     *
     * @param string $docId The document ID (siteId:elementId)
     * @return int The document length
     */
    abstract protected function getDocumentLength(string $docId): int;

    /**
     * Get all terms in the index
     *
     * @return array All terms
     */
    abstract protected function getAllTerms(): array;
}
