<?php

namespace MadeByBramble\BrambleSearch\adapters;

use Craft;
use craft\services\Search;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\helpers\ElementHelper;
use yii\log\Logger;

/**
 * Base Search Adapter
 *
 * Abstract base class for all search adapters that implements common functionality
 * including BM25 scoring, fuzzy search, and title boosting. Defines abstract methods
 * that must be implemented by storage-specific adapters.
 */
abstract class BaseSearchAdapter extends Search
{
    // =========================================================================
    // PROPERTIES
    // =========================================================================

    /**
     * Key prefix for all stored search data
     */
    protected string $prefix = 'bramble_search:';

    /**
     * BM25 algorithm parameter: term saturation
     */
    protected float $k1 = 1.5;

    /**
     * BM25 algorithm parameter: document length normalization
     */
    protected float $b = 0.75;

    /**
     * Boost factor applied to terms found in title fields
     */
    protected float $titleBoostFactor = 5.0;

    /**
     * Boost factor applied to exact phrase matches
     */
    protected float $exactMatchBoostFactor = 3.0;

    /**
     * List of stop words to filter out during indexing and searching
     */
    protected array $stopWords = [];

    // =========================================================================
    // INITIALIZATION METHODS
    // =========================================================================

    /**
     * Initialize the adapter
     */
    public function init(): void
    {
        parent::init();
        $this->loadStopWords();
    }

    /**
     * Load stop words from language file
     */
    protected function loadStopWords(): void
    {
        $this->stopWords = require Craft::getAlias('@bramble_search/stopwords/en.php');
    }

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * Index an element's attributes for searching
     *
     * Processes an element's content, tokenizes it, and stores it in the search index
     * with special handling for title fields.
     *
     * @param ElementInterface $element The element to index
     * @param array|null $fieldHandles Specific field handles to index, or null for all
     * @return bool Whether the indexing was successful
     */
    public function indexElementAttributes(ElementInterface $element, array|null $fieldHandles = null): bool
    {
        // Skip elements without ID, site ID, or that are disabled
        if (!$element->id || !$element->siteId || !$element->enabled) {
            return true;
        }

        // Skip drafts and revisions
        if (ElementHelper::isDraftOrRevision($element)) {
            return true;
        }

        // Skip provisional drafts
        if (property_exists($element, 'isProvisionalDraft') && $element->isProvisionalDraft) {
            return true;
        }

        // Skip entries without titles (likely section entries in Craft 5)
        if (property_exists($element, 'title') && empty($element->title)) {
            return true;
        }

        // Prepare log data
        $logData = [
            'elementId' => $element->id,
            'siteId' => $element->siteId,
            'elementType' => get_class($element),
            'title' => $element->title ?? '(no title)',
            'fields' => []
        ];

        // Process title for special handling
        $title = $element->title ?? '';
        $titleTokens = $this->tokenize($title);
        $titleTokens = $this->filterStopWords($titleTokens);
        $titleTerms = array_flip($titleTokens); // Convert to associative array for faster lookups

        $logData['titleTokens'] = $titleTokens;

        // Process all content using Craft's searchable attributes
        $text = '';

        // Process element attributes
        foreach (ElementHelper::searchableAttributes($element) as $attribute) {
            $value = $element->getSearchKeywords($attribute);
            if (!empty($value)) {
                $text .= ' ' . $value;
                $logData['fields'][$attribute] = $value;
            }
        }

        // Process custom fields if specified
        if ($fieldHandles !== null) {
            foreach ($fieldHandles as $handle) {
                $fieldValue = $element->getFieldValue($handle);
                if ($fieldValue && $element->getFieldLayout()) {
                    $field = $element->getFieldLayout()->getFieldByHandle($handle);
                    if ($field && $field->searchable) {
                        $keywords = $field->getSearchKeywords($fieldValue, $element);
                        if (!empty($keywords)) {
                            $text .= ' ' . $keywords;
                            $logData['fields'][$handle] = $keywords;
                        }
                    }
                }
            }
        }

        $tokens = $this->tokenize($text);
        $tokens = $this->filterStopWords($tokens);
        $termFreqs = array_count_values($tokens);
        $docLen = count($tokens);

        // Add tokenization results to log data
        $logData['allTokens'] = $tokens;
        $logData['termFrequencies'] = $termFreqs;
        $logData['documentLength'] = $docLen;

        // Get old terms to clean up
        $oldTerms = $this->getDocumentTerms($element->siteId, $element->id);
        $logData['oldTerms'] = $oldTerms;

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

        // Log the indexing operation with all collected data
        Craft::getLogger()->log(
            $this->formatLogMessage($logData),
            Logger::LEVEL_TRACE,
            'bramble-search'
        );

        return true;
    }

    /**
     * Search for elements matching a query
     *
     * Implements BM25 scoring algorithm with title boosting and exact phrase matching.
     * For multiple search terms, requires ALL terms to be present in a document (AND logic).
     *
     * @param ElementQuery $elementQuery The element query containing search parameters
     * @return array Element IDs and their relevance scores
     */
    public function searchElements(ElementQuery $elementQuery): array
    {
        $siteId = $elementQuery->siteId ?? Craft::$app->sites->currentSite->id;
        $searchQuery = $elementQuery->search;
        $tokens = $this->tokenize($searchQuery);
        $tokens = $this->filterStopWords($tokens);

        // If no valid tokens after filtering stop words, return empty results
        if (empty($tokens)) {
            return [];
        }

        $totalDocs = max(1, $this->getTotalDocCount());
        $totalLength = max(1, $this->getTotalLength());
        $avgDocLength = $totalLength / $totalDocs;

        // Track which documents match each term
        $termMatches = [];
        // Track scores for each document
        $docScores = [];
        // Track which terms matched for each document (for exact phrase matching)
        $matchedTerms = [];

        // First pass: collect all documents matching each term
        foreach ($tokens as $termIndex => $term) {
            $termMatches[$termIndex] = [];

            // Try exact match first
            $termDocs = $this->getTermDocuments($term);

            if (!empty($termDocs)) {
                $docFreq = count($termDocs);
                foreach ($termDocs as $docId => $freq) {
                    if (!str_starts_with($docId, "$siteId:")) {
                        continue;
                    }

                    $termMatches[$termIndex][$docId] = true;
                    $matchedTerms[$docId][$term] = true;

                    $docLen = max(1, $this->getDocumentLength($docId));
                    $score = $this->bm25($freq, $docFreq, $docLen, $avgDocLength);

                    // Apply title boost if term is in title
                    if ($this->isTermInTitle($term, $docId)) {
                        $score *= $this->titleBoostFactor;
                    }

                    $docScores[$docId] = ($docScores[$docId] ?? 0) + $score;
                }
            } else {
                // Fuzzy fallback if no exact matches
                $fuzzyTerms = $this->findFuzzyMatches($term);
                foreach ($fuzzyTerms as $fuzzy) {
                    $fuzzyDocs = $this->getTermDocuments($fuzzy);
                    if (empty($fuzzyDocs)) {
                        continue;
                    }

                    $docFreq = count($fuzzyDocs);
                    foreach ($fuzzyDocs as $docId => $freq) {
                        if (!str_starts_with($docId, "$siteId:")) {
                            continue;
                        }

                        $termMatches[$termIndex][$docId] = true;
                        $matchedTerms[$docId][$term] = true;

                        $docLen = max(1, $this->getDocumentLength($docId));
                        $score = $this->bm25($freq, $docFreq, $docLen, $avgDocLength);

                        // Apply title boost if term is in title
                        if ($this->isTermInTitle($fuzzy, $docId)) {
                            $score *= $this->titleBoostFactor;
                        }

                        $docScores[$docId] = ($docScores[$docId] ?? 0) + $score;
                    }
                }
            }
        }

        // If we have multiple terms, find the intersection of all term matches
        // This implements AND logic - a document must match ALL search terms
        $validDocs = null;
        foreach ($termMatches as $docs) {
            if ($validDocs === null) {
                $validDocs = array_keys($docs);
            } else {
                $validDocs = array_intersect($validDocs, array_keys($docs));
            }

            // Early exit if no documents match all terms so far
            if (empty($validDocs)) {
                return [];
            }
        }

        // Filter scores to only include documents that matched all terms
        $filteredScores = [];
        foreach ($validDocs as $docId) {
            $filteredScores[$docId] = $docScores[$docId];
        }

        // Apply exact match boosting for multi-term queries
        if (count($tokens) > 1) {
            foreach ($filteredScores as $docId => $score) {
                // Check if the document contains the exact phrase
                if ($this->containsExactPhrase($docId, $searchQuery)) {
                    $filteredScores[$docId] *= $this->exactMatchBoostFactor;
                }
            }
        }

        // Sort by score (highest first)
        arsort($filteredScores);

        $results = [];

        // Convert our internal docId format (siteId:elementId) to Craft's expected format (elementId-siteId)
        foreach ($filteredScores as $docId => $score) {
            [$docSiteId, $elementId] = explode(':', $docId);
            $results["$elementId-$docSiteId"] = $score;
        }

        return $results;
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Calculate BM25 relevance score for a term in a document
     *
     * @param int $freq Term frequency in the document
     * @param int $docFreq Number of documents containing the term
     * @param int $docLen Document length in tokens
     * @param float $avgDocLen Average document length across the index
     * @return float BM25 score
     */
    protected function bm25($freq, $docFreq, $docLen, $avgDocLen): float
    {
        $totalDocs = $this->getTotalDocCount();
        $idf = log(1 + (($totalDocs - $docFreq + 0.5) / ($docFreq + 0.5)));
        return $idf * (($freq * ($this->k1 + 1)) / ($freq + $this->k1 * (1 - $this->b + $this->b * ($docLen / $avgDocLen))));
    }

    /**
     * Find terms that are similar to the given term using Levenshtein distance
     *
     * @param string $term The term to find matches for
     * @param int $maxDistance Maximum Levenshtein distance for matches
     * @return array List of matching terms
     */
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

    /**
     * Tokenize text into searchable terms
     *
     * @param string $text Text to tokenize
     * @return array Array of tokens
     */
    protected function tokenize(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        return array_filter(explode(' ', $text));
    }

    /**
     * Remove stop words from an array of tokens
     *
     * @param array $tokens Array of tokens to filter
     * @return array Filtered tokens
     */
    protected function filterStopWords(array $tokens): array
    {
        return array_filter($tokens, fn($t) => !in_array($t, $this->stopWords));
    }

    /**
     * Format log data into a readable message for debugging
     *
     * @param array $logData The log data to format
     * @return string The formatted log message
     */
    protected function formatLogMessage(array $logData): string
    {
        $message = "Indexing Element: ID={$logData['elementId']}, Site={$logData['siteId']}, Type={$logData['elementType']}\n";
        $message .= "Title: \"{$logData['title']}\"\n";

        // Title tokens
        $message .= "Title Tokens: " . (empty($logData['titleTokens']) ? '(none)' : implode(', ', $logData['titleTokens'])) . "\n";

        // Fields
        $message .= "Fields:\n";
        if (empty($logData['fields'])) {
            $message .= "  (no fields)\n";
        } else {
            foreach ($logData['fields'] as $handle => $value) {
                // Truncate long field values for readability
                if (is_string($value) && strlen($value) > 100) {
                    $value = substr($value, 0, 97) . '...';
                }
                $message .= "  {$handle}: \"{$value}\"\n";
            }
        }

        // Tokens and frequencies
        $message .= "Document Length: {$logData['documentLength']} tokens\n";

        $message .= "Term Frequencies:\n";
        if (empty($logData['termFrequencies'])) {
            $message .= "  (no terms)\n";
        } else {
            foreach ($logData['termFrequencies'] as $term => $freq) {
                $message .= "  {$term}: {$freq}\n";
            }
        }

        // Old terms (if any)
        $message .= "Old Terms:\n";
        if (empty($logData['oldTerms'])) {
            $message .= "  (no previous terms)\n";
        } else {
            foreach ($logData['oldTerms'] as $term => $freq) {
                $message .= "  {$term}: {$freq}\n";
            }
        }

        return $message;
    }

    /**
     * Check if a term appears in the title of a document
     * Used for title boosting in search results
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
     * Check if a document contains the exact search phrase
     * Used for exact match boosting in search results
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

    // =========================================================================
    // ABSTRACT METHODS - DOCUMENT OPERATIONS
    // =========================================================================

    /**
     * Get all indexed terms for a document with their frequencies
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

    // =========================================================================
    // ABSTRACT METHODS - METADATA OPERATIONS
    // =========================================================================

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

    // =========================================================================
    // ABSTRACT METHODS - TERM OPERATIONS
    // =========================================================================

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
     * Clear the search index for a specific site
     *
     * Removes all documents for the specified site and cleans up orphaned terms.
     *
     * @param int $siteId The site ID to clear the index for
     * @return bool Whether the operation was successful
     */
    public function clearIndex(int $siteId): bool
    {
        try {
            // Get all documents for this site
            $documents = $this->getSiteDocuments($siteId);

            // Track the total length we're removing
            $totalLengthToRemove = 0;

            // We'll clean up orphaned terms after deleting documents

            // Delete each document
            foreach ($documents as $docId) {
                [$docSiteId, $elementId] = explode(':', $docId);
                $elementId = (int)$elementId;
                $docSiteId = (int)$docSiteId;

                // Get document length before deleting
                $docLength = $this->getDocumentLength("$docSiteId:$elementId");
                $totalLengthToRemove += $docLength;

                // Delete the element from the index
                $this->deleteElementFromIndex($elementId, $docSiteId);
            }

            // Reset the total length
            $this->resetTotalLength();

            // Clear all terms that no longer have documents
            $this->cleanupOrphanedTerms();

            // Update metadata
            $this->updateTotalDocCount();

            Craft::info("Search index cleared for site ID: $siteId", __METHOD__);
            return true;
        } catch (\Throwable $e) {
            Craft::error("Error clearing search index: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Delete an element from the index completely
     *
     * Removes all references to the element from the search index,
     * including term associations and metadata.
     *
     * @param int $elementId The element ID
     * @param int $siteId The site ID
     * @return bool Whether the operation was successful
     */
    public function deleteElementFromIndex(int $elementId, int $siteId): bool
    {
        try {
            // Get all terms for this document
            $terms = $this->getDocumentTerms($siteId, $elementId);

            // Remove the document from each term's document list
            foreach (array_keys($terms) as $term) {
                $this->removeTermDocument($term, $siteId, $elementId);
            }

            // Delete the document and title terms
            $this->deleteDocument($siteId, $elementId);
            $this->deleteTitleTerms($siteId, $elementId);

            // Remove from the document index
            $this->removeDocumentFromIndex($siteId, $elementId);

            return true;
        } catch (\Throwable $e) {
            Craft::error("Error deleting element from index: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    // =========================================================================
    // ABSTRACT METHODS - TITLE OPERATIONS
    // =========================================================================

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

    // =========================================================================
    // ABSTRACT METHODS - SITE OPERATIONS
    // =========================================================================

    /**
     * Get all documents for a specific site
     *
     * @param int $siteId The site ID
     * @return array The document IDs
     */
    abstract protected function getSiteDocuments(int $siteId): array;

    /**
     * Remove a document from the index
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return void
     */
    abstract protected function removeDocumentFromIndex(int $siteId, int $elementId): void;

    /**
     * Reset the total length counter
     *
     * @return void
     */
    abstract protected function resetTotalLength(): void;

    /**
     * Clean up orphaned terms (terms with no associated documents)
     *
     * Improves index efficiency by removing terms that no longer
     * have any documents associated with them.
     */
    public function cleanupOrphanedTerms(): void
    {
        $allTerms = $this->getAllTerms();

        foreach ($allTerms as $term) {
            $docs = $this->getTermDocuments($term);

            if (empty($docs)) {
                $this->removeTermFromIndex($term);
            }
        }
    }

    /**
     * Remove a term from the index
     *
     * @param string $term The term to remove
     * @return void
     */
    abstract protected function removeTermFromIndex(string $term): void;
}
