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

    // Boost factors for title and exact match
    protected float $titleBoostFactor = 5.0;
    protected float $exactMatchBoostFactor = 3.0;

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

        // Process title separately
        $title = $element->title ?? '';
        $titleTokens = $this->tokenize($title);
        $titleTokens = $this->filterStopWords($titleTokens);
        $titleTerms = array_flip($titleTokens); // Convert to associative array for faster lookups

        // Process all content including title
        $text = $title;
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

        // Delete the old document and title terms
        $this->deleteDocument($element->siteId, $element->id);
        $this->deleteTitleTerms($element->siteId, $element->id);

        // Store new document data and title terms
        $this->storeDocument($element->siteId, $element->id, $termFreqs, $docLen);
        $this->storeTitleTerms($element->siteId, $element->id, $titleTerms);

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
        $searchQuery = $elementQuery->search;
        $tokens = $this->tokenize($searchQuery);
        $tokens = $this->filterStopWords($tokens);

        $totalDocs = max(1, $this->getTotalDocCount());
        $totalLength = max(1, $this->getTotalLength());
        $avgDocLength = $totalLength / $totalDocs;

        $docScores = [];
        $matchedDocs = []; // Track which docs matched for exact phrase matching

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

                    // Apply title boost if term is in title
                    if ($this->isTermInTitle($term, $docId)) {
                        $score *= $this->titleBoostFactor;
                    }

                    $docScores[$docId] = ($docScores[$docId] ?? 0) + $score;
                    $matchedDocs[$docId][$term] = true;
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

                    // Apply title boost if term is in title
                    if ($this->isTermInTitle($fuzzy, $docId)) {
                        $score *= $this->titleBoostFactor;
                    }

                    $docScores[$docId] = ($docScores[$docId] ?? 0) + $score;
                    $matchedDocs[$docId][$term] = true;
                }
            }
        }

        // Apply exact match boosting
        if (count($tokens) > 1) {
            foreach ($matchedDocs as $docId => $docTerms) {
                // If document contains all search terms, apply exact match boost
                if (count($docTerms) === count($tokens)) {
                    // Check if the document contains the exact phrase
                    if ($this->containsExactPhrase($docId, $searchQuery)) {
                        $docScores[$docId] *= $this->exactMatchBoostFactor;
                    }
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

    /**
     * Check if a term is in the title of a document
     *
     * @param string $term The term to check
     * @param string $docId The document ID (siteId:elementId)
     * @return bool Whether the term is in the title
     */
    protected function isTermInTitle(string $term, string $docId): bool
    {
        $titleTerms = $this->getTitleTerms($docId);
        return isset($titleTerms[$term]);
    }

    /**
     * Check if a document contains the exact phrase
     *
     * @param string $docId The document ID (siteId:elementId)
     * @param string $phrase The phrase to check
     * @return bool Whether the document contains the exact phrase
     */
    protected function containsExactPhrase(string $docId, string $phrase): bool
    {
        // This is a simplified implementation
        // For a more accurate implementation, we would need to store position information
        // For now, we'll just check if all the terms are present
        $tokens = $this->tokenize($phrase);
        $tokens = $this->filterStopWords($tokens);

        [$siteId, $elementId] = explode(':', $docId);
        $docTerms = $this->getDocumentTerms((int)$siteId, (int)$elementId);

        foreach ($tokens as $token) {
            if (!isset($docTerms[$token])) {
                return false;
            }
        }

        return true;
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

    /**
     * Store title terms for a document
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param array $titleTerms The title terms
     * @return void
     */
    abstract protected function storeTitleTerms(int $siteId, int $elementId, array $titleTerms): void;

    /**
     * Get title terms for a document
     *
     * @param string $docId The document ID (siteId:elementId)
     * @return array The title terms
     */
    abstract protected function getTitleTerms(string $docId): array;

    /**
     * Delete title terms for a document
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return void
     */
    abstract protected function deleteTitleTerms(int $siteId, int $elementId): void;
}
