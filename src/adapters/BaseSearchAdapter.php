<?php

namespace MadeByBramble\BrambleSearch\adapters;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\QueryAbortedException;
use craft\elements\db\ElementQuery;
use craft\helpers\ElementHelper;
use craft\search\SearchQuery;
use craft\services\Search;
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

    /**
     * N-gram sizes to generate for fuzzy matching
     */
    protected array $ngramSizes = [1, 2, 3];

    /**
     * Minimum n-gram similarity threshold for fuzzy search candidates
     */
    protected float $ngramSimilarityThreshold = 0.25;

    /**
     * Maximum number of candidates to process for fuzzy matching
     */
    protected int $fuzzySearchMaxCandidates = 100;

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
        $this->loadSettings();
    }

    /**
     * Load stop words from language file
     */
    protected function loadStopWords(): void
    {
        $this->stopWords = require Craft::getAlias('@bramble_search/stopwords/en.php');
    }

    /**
     * Load BM25 and boost parameters from plugin settings
     */
    protected function loadSettings(): void
    {
        $plugin = \MadeByBramble\BrambleSearch\Plugin::$plugin;
        if ($plugin) {
            /** @var \MadeByBramble\BrambleSearch\models\Settings $settings */
            $settings = $plugin->getSettings();
            $this->k1 = $settings->bm25K1;
            $this->b = $settings->bm25B;
            $this->titleBoostFactor = $settings->titleBoostFactor;
            $this->exactMatchBoostFactor = $settings->exactMatchBoostFactor;
            
            // Load n-gram settings (with defaults if not set)
            $this->ngramSizes = $settings->ngramSizes ?? [2, 3];
            $this->ngramSimilarityThreshold = $settings->ngramSimilarityThreshold ?? 0.4;
            $this->fuzzySearchMaxCandidates = $settings->fuzzySearchMaxCandidates ?? 100;
        }
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
        // Skip elements without ID or site ID
        if (!$element->id || !$element->siteId) {
            return true;
        }

        if (!$element->enabled || !$element->getEnabledForSite()) {
            return $this->removeElementFromIndexAndUpdateMetadata($element);
        }

        // Skip drafts and revisions
        if (ElementHelper::isDraftOrRevision($element)) {
            return $this->removeElementFromIndexAndUpdateMetadata($element);
        }

        // Skip provisional drafts
        if (property_exists($element, 'isProvisionalDraft') && $element->isProvisionalDraft) {
            return $this->removeElementFromIndexAndUpdateMetadata($element);
        }

        // Skip elements that should have titles but don't (e.g., section entries in Craft 5)
        // Only apply this check to element types that explicitly support titles
        $elementType = get_class($element);
        if ($elementType::hasTitles() && empty($element->title)) {
            return $this->removeElementFromIndexAndUpdateMetadata($element);
        }

        // Prepare log data
        $logData = [
            'elementId' => $element->id,
            'siteId' => $element->siteId,
            'elementType' => get_class($element),
            'title' => $element->title ?? '(no title)',
            'fields' => [],
        ];

        // Process title for special handling
        $title = $element->title ?? '';
        $titleTokens = $this->tokenize($title);
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
                $fieldLayout = $element->getFieldLayout();
                $field = $fieldLayout?->getFieldByHandle($handle);
                if (!$field || !$field->searchable) {
                    continue;
                }

                $fieldValue = $element->getFieldValue($handle);
                if ($fieldValue) {
                    $keywords = $field->getSearchKeywords($fieldValue, $element);
                    if (!empty($keywords)) {
                        $text .= ' ' . $keywords;
                        $logData['fields'][$handle] = $keywords;
                    }
                }
            }
        }

        $termFreqs = $this->buildIndexedTermFrequencies($text, $titleTokens);
        $docLen = array_sum($termFreqs);

        // Add tokenization results to log data
        $logData['allTokens'] = array_keys($termFreqs);
        $logData['termFrequencies'] = $termFreqs;
        $logData['documentLength'] = $docLen;

        $oldDocLength = $this->getDocumentLength("{$element->siteId}:{$element->id}");

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

        // Generate and store n-grams for fuzzy search
        foreach (array_keys($termFreqs) as $term) {
            // Only generate n-grams for terms that don't already have them
            if (!$this->termHasNgrams($term, $element->siteId)) {
                $ngrams = $this->generateNgrams($term);
                if (!empty($ngrams)) {
                    $this->storeTermNgrams($term, $ngrams, $element->siteId);
                }
            }
        }

        // Update metadata
        $this->addDocumentToIndex($element->siteId, $element->id);
        $this->updateTotalDocCount();
        $this->updateTotalLength($docLen - $oldDocLength);

        // Log the indexing operation with all collected data
        Craft::getLogger()->log(
            $this->formatLogMessage($logData),
            Logger::LEVEL_TRACE,
            'bramble-search'
        );

        return true;
    }

    /**
     * Remove an element that should no longer be indexed and keep metadata totals in sync.
     */
    protected function removeElementFromIndexAndUpdateMetadata(ElementInterface $element): bool
    {
        if (!$element->id || !$element->siteId) {
            return true;
        }

        $oldDocLength = $this->getDocumentLength("{$element->siteId}:{$element->id}");
        if (!$this->deleteElementFromIndex($element->id, $element->siteId)) {
            return false;
        }

        if ($oldDocLength > 0) {
            $this->updateTotalLength(-$oldDocLength);
        }
        $this->updateTotalDocCount();

        return true;
    }

    /**
     * Always use searchElements() for searches since we don't populate Craft's native searchindex table.
     *
     * Only returns true when there's actually a search query, to avoid Craft's code path that
     * assumes orderBy['score'] exists when shouldCallSearchElements() returns true.
     *
     * @param ElementQuery $elementQuery
     * @return bool
     */
    public function shouldCallSearchElements(ElementQuery $elementQuery): bool
    {
        // Only return true if there's actually a search query
        // This ensures we use searchElements() for all searches while avoiding
        // Craft's assumption that orderBy['score'] exists when this returns true
        return !empty($elementQuery->search);
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
        $searchQuery = $this->normalizeSearchQueryToString($elementQuery->search);
        $rawTokens = $this->tokenize($searchQuery);
        $isTypeaheadQuery = $this->isSearchAsYouTypeQuery($searchQuery, $rawTokens);
        $tokens = $this->getSearchTokens($searchQuery, $isTypeaheadQuery);

        // If no searchable tokens were provided, return empty results
        if (empty($tokens)) {
            return [];
        }

        // Cache statistics once for the entire search operation
        $searchStats = $this->getSearchStatistics();
        $totalDocs = $searchStats['totalDocs'];
        $avgDocLength = $searchStats['avgDocLength'];

        // Track which documents match each term
        $termMatches = [];
        // Track scores for each document
        $docScores = [];
        // Collect all matching documents and their term data for batch processing
        $allDocuments = [];
        $termData = []; // [termIndex][docId] = ['freq' => freq, 'docFreq' => docFreq, 'actualTerm' => term]
        $lastTokenIndex = array_key_last($tokens);

        // First pass: collect all documents matching each term
        foreach ($tokens as $termIndex => $term) {
            $termMatches[$termIndex] = [];
            $isTypeaheadTerm = $isTypeaheadQuery && $termIndex === $lastTokenIndex;

            // Try exact match first
            $termDocs = $this->filterDocumentsBySite($this->getTermDocuments($term), (int)$siteId);

            if (!empty($termDocs)) {
                $docFreq = count($termDocs);
                foreach ($termDocs as $docId => $freq) {
                    $termMatches[$termIndex][$docId] = true;
                    $allDocuments[$docId] = true;
                    $termData[$termIndex][$docId] = [
                        'freq' => $freq,
                        'docFreq' => $docFreq,
                        'actualTerm' => $term,
                        'confidence' => 1.0,
                    ];
                }
            }

            if ($isTypeaheadTerm) {
                $priorDocIds = $termIndex > 0 ? $this->getDocsMatchingPreviousTerms($termMatches, $termIndex) : null;
                $completedTerms = array_slice($tokens, 0, $termIndex);
                $prefixTerms = $this->findTypeaheadMatchScores($term, (int)$siteId, $priorDocIds, $completedTerms);

                foreach ($prefixTerms as $prefixTerm => $confidence) {
                    if ($prefixTerm === $term) {
                        continue;
                    }

                    $prefixDocs = $this->filterDocumentsBySite($this->getTermDocuments($prefixTerm), (int)$siteId);
                    if (empty($prefixDocs)) {
                        continue;
                    }

                    $docFreq = count($prefixDocs);
                    foreach ($prefixDocs as $docId => $freq) {
                        if ($this->shouldSkipNonTitleTypeaheadMatch($term, $prefixTerm, (string)$docId)) {
                            continue;
                        }

                        $docConfidence = $confidence;
                        if (!$this->isTermInTitle($prefixTerm, (string)$docId)) {
                            $docConfidence *= $this->getNonTitleTypeaheadConfidenceMultiplier($term);
                        }

                        $matchData = [
                            'freq' => $freq,
                            'docFreq' => $docFreq,
                            'actualTerm' => $prefixTerm,
                            'confidence' => $docConfidence,
                        ];

                        if (
                            isset($termData[$termIndex][$docId])
                            && !$this->shouldReplaceSearchTermMatch($docId, $termData[$termIndex][$docId], $matchData)
                        ) {
                            continue;
                        }

                        $termMatches[$termIndex][$docId] = true;
                        $allDocuments[$docId] = true;
                        $termData[$termIndex][$docId] = $matchData;
                    }
                }
            }

            if (!$this->shouldFindFuzzyMatches($term, !empty($termDocs))) {
                continue;
            }

            // Include fuzzy matches as supplemental results. Exact matches remain preferred,
            // but a same-site exact typo should not suppress a close title match.
            $fuzzyTerms = $this->findFuzzyMatchScores($term, siteId: (int)$siteId);
            foreach ($fuzzyTerms as $fuzzy => $confidence) {
                if ($fuzzy === $term) {
                    continue;
                }

                $fuzzyDocs = $this->filterDocumentsBySite($this->getTermDocuments($fuzzy), (int)$siteId);
                if (empty($fuzzyDocs)) {
                    continue;
                }

                $docFreq = count($fuzzyDocs);
                foreach ($fuzzyDocs as $docId => $freq) {
                    if (isset($termData[$termIndex][$docId])) {
                        continue;
                    }

                    $termMatches[$termIndex][$docId] = true;
                    $allDocuments[$docId] = true;
                    $termData[$termIndex][$docId] = [
                        'freq' => $freq,
                        'docFreq' => $docFreq,
                        'actualTerm' => $fuzzy,
                        'confidence' => $confidence,
                    ];
                }
            }
        }

        // Batch fetch all document lengths
        $documentLengths = $this->getDocumentLengthsBatch(array_keys($allDocuments));

        // Second pass: calculate BM25 scores using cached document lengths
        foreach ($termData as $termIndex => $documents) {
            foreach ($documents as $docId => $data) {
                $docLen = max(1, $documentLengths[$docId] ?? 1);
                $score = $this->bm25($data['freq'], $data['docFreq'], $docLen, $avgDocLength, $totalDocs);

                // Apply title boost if term is in title
                if ($this->isTermInTitle($data['actualTerm'], $docId)) {
                    $score *= $this->titleBoostFactor;
                }

                $score *= $data['confidence'] ?? 1.0;

                $docScores[$docId] = ($docScores[$docId] ?? 0) + $score;
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

        foreach (array_keys($filteredScores) as $docId) {
            if (
                $this->shouldBoostExactTitleTermSet($tokens, $isTypeaheadQuery)
                && $this->containsExactTitleTermSet($docId, $tokens)
            ) {
                $filteredScores[$docId] *= $this->exactMatchBoostFactor * $this->titleBoostFactor;
                $filteredScores[$docId] += $this->exactMatchBoostFactor * $this->titleBoostFactor * max(1, $totalDocs) * 10;
                continue;
            }

            if (count($tokens) > 1) {
                if (
                    $isTypeaheadQuery
                    ? $this->containsTypeaheadPhrase($docId, $tokens)
                    : $this->containsExactPhrase($docId, $searchQuery)
                ) {
                    $filteredScores[$docId] *= $this->exactMatchBoostFactor;
                }

                if ($this->containsTightTitleMatch($docId, $tokens, $isTypeaheadQuery)) {
                    $filteredScores[$docId] *= $this->exactMatchBoostFactor * $this->titleBoostFactor;
                    $filteredScores[$docId] += $this->exactMatchBoostFactor * $this->titleBoostFactor * max(1, $totalDocs);
                }
            }
        }

        $filteredScores = $this->filterScoresByElementQuery($filteredScores, $elementQuery);
        if (empty($filteredScores)) {
            return [];
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

    /**
     * Decide whether the final query token should be treated as an in-progress prefix.
     *
     * This follows the search-as-you-type model used by engines such as Elasticsearch's
     * match_bool_prefix: completed words are normal terms and the final typed word is a prefix.
     *
     * @param string $searchQuery The raw search query
     * @param array $tokens Tokenized search terms
     * @return bool Whether the query should use final-token prefix matching
     */
    protected function isSearchAsYouTypeQuery(string $searchQuery, array $tokens): bool
    {
        return !empty($tokens) && preg_match('/\s$/u', $searchQuery) !== 1;
    }

    /**
     * Intersect all term matches before the current token.
     *
     * @param array $termMatches Term match maps keyed by token index
     * @param int $currentTermIndex Current token index
     * @return array|null Prior matching document IDs, or null when no prior tokens exist
     */
    protected function getDocsMatchingPreviousTerms(array $termMatches, int $currentTermIndex): ?array
    {
        if ($currentTermIndex <= 0) {
            return null;
        }

        $validDocs = null;
        for ($i = 0; $i < $currentTermIndex; $i++) {
            $docs = array_keys($termMatches[$i] ?? []);

            if ($validDocs === null) {
                $validDocs = $docs;
            } else {
                $validDocs = array_values(array_intersect($validDocs, $docs));
            }

            if (empty($validDocs)) {
                return [];
            }
        }

        return $validDocs;
    }

    /**
     * Prefer title-backed or higher-confidence expanded terms for a document.
     *
     * @param string $docId Document ID
     * @param array $current Current match data
     * @param array $candidate Candidate match data
     * @return bool Whether the candidate should replace the current match
     */
    protected function shouldReplaceSearchTermMatch(string $docId, array $current, array $candidate): bool
    {
        if (($current['confidence'] ?? 1.0) >= 1.0) {
            return false;
        }

        $currentInTitle = $this->isTermInTitle((string)$current['actualTerm'], $docId);
        $candidateInTitle = $this->isTermInTitle((string)$candidate['actualTerm'], $docId);

        if ($candidateInTitle !== $currentInTitle) {
            return $candidateInTitle;
        }

        if (($candidate['confidence'] ?? 0.0) !== ($current['confidence'] ?? 0.0)) {
            return ($candidate['confidence'] ?? 0.0) > ($current['confidence'] ?? 0.0);
        }

        return ($candidate['docFreq'] ?? PHP_INT_MAX) < ($current['docFreq'] ?? PHP_INT_MAX);
    }

    /**
     * Filters scored search matches through the original element query criteria.
     *
     * Craft paginates `orderBy('score')` results after searchElements() returns,
     * so IDs that cannot be returned by the caller's query must be removed here.
     *
     * @param array<string,float> $scores Scores keyed by internal doc ID (siteId:elementId)
     * @param ElementQuery $elementQuery The original element query
     * @return array<string,float>
     */
    protected function filterScoresByElementQuery(array $scores, ElementQuery $elementQuery): array
    {
        if (empty($scores)) {
            return [];
        }

        $elementIds = [];
        foreach (array_keys($scores) as $docId) {
            [, $elementId] = explode(':', $docId, 2);
            $elementIds[] = (int)$elementId;
        }
        $elementIds = array_values(array_unique($elementIds));

        $criteriaQuery = clone $elementQuery;
        $criteriaQuery
            ->search(null)
            ->offset(null)
            ->limit(null)
            ->orderBy([]);
        $criteriaQuery->andWhere(['elements.id' => $elementIds]);

        try {
            $allowedIds = array_fill_keys(array_map('intval', $criteriaQuery->ids()), true);
        } catch (QueryAbortedException) {
            return [];
        }

        return array_filter(
            $scores,
            function(string $docId) use ($allowedIds): bool {
                [, $elementId] = explode(':', $docId, 2);
                return isset($allowedIds[(int)$elementId]);
            },
            ARRAY_FILTER_USE_KEY
        );
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
     * @param int $totalDocs Total number of documents in the index
     * @return float BM25 score
     */
    protected function bm25($freq, $docFreq, $docLen, $avgDocLen, $totalDocs): float
    {
        $idf = log(1 + (($totalDocs - $docFreq + 0.5) / ($docFreq + 0.5)));
        return $idf * (($freq * ($this->k1 + 1)) / ($freq + $this->k1 * (1 - $this->b + $this->b * ($docLen / $avgDocLen))));
    }

    /**
     * Build the indexed term frequencies for an element.
     *
     * Craft usually includes title text in searchable attributes, but this method
     * explicitly preserves every title token so exact title searches never depend
     * on that behavior. Stop words remain excluded from non-title content.
     *
     * @param string $text Searchable attribute and field text
     * @param array $titleTokens Tokenized element title
     * @return array Terms keyed to frequency
     */
    protected function buildIndexedTermFrequencies(string $text, array $titleTokens): array
    {
        $tokens = $this->filterStopWords($this->tokenize($text));
        $termFreqs = array_count_values($tokens);

        foreach ($titleTokens as $titleToken) {
            $termFreqs[$titleToken] ??= 1;
        }

        return $termFreqs;
    }

    /**
     * Find terms that are similar to the given term using Levenshtein distance
     *
     * @param string $term The term to find matches for
     * @param int $maxDistance Maximum Levenshtein distance for matches
     * @return array List of matching terms
     */
    protected function findFuzzyMatches(string $term, int $maxDistance = 2, ?int $siteId = null): array
    {
        return array_keys($this->findFuzzyMatchScores($term, $maxDistance, $siteId));
    }

    /**
     * Find terms similar to the given term, with confidence scores.
     *
     * N-gram similarity gives us cheap candidate retrieval. Edit distance then
     * removes candidates that share fragments but are not close typos.
     *
     * @param string $term The term to find matches for
     * @param int $maxDistance Maximum Levenshtein distance for matches
     * @param int|null $siteId Site to search within
     * @return array Matching terms keyed to confidence scores
     */
    protected function findFuzzyMatchScores(string $term, int $maxDistance = 2, ?int $siteId = null): array
    {
        // Generate n-grams for the search term
        $searchNgrams = $this->generateNgrams($term);
        
        if (empty($searchNgrams)) {
            return [];
        }

        // Get candidate terms with high n-gram similarity
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id ?? 1;
        
        // Use adaptive threshold based on term length for better short-term support
        $adaptiveThreshold = $this->getAdaptiveThreshold($term);
        
        $candidates = $this->getTermsByNgramSimilarity(
            $searchNgrams,
            $siteId,
            $adaptiveThreshold
        );

        $maxDistance = $this->getAdaptiveMaxDistance($term, $maxDistance);
        $matches = [];

        foreach ($candidates as $candidate => $similarity) {
            $candidate = (string)$candidate;
            if ($this->isWithinFuzzyEditDistance($term, $candidate, $maxDistance)) {
                $matches[$candidate] = $this->calculateFuzzyConfidence($term, $candidate, $similarity);
            }
        }

        // Limit candidates for performance and return by similarity score
        $matches = array_slice($matches, 0, $this->fuzzySearchMaxCandidates, true);

        return $matches;
    }

    /**
     * Find candidate terms for the final in-progress search word.
     *
     * When prior query terms already narrowed the result set, inspect those documents
     * first. This keeps one-character prefixes useful without expanding the whole index.
     *
     * @param string $prefix Current final query token
     * @param int $siteId Site to search within
     * @param array|null $candidateDocIds Optional prior-matching documents
     * @param array $excludedTerms Completed query terms that should not satisfy the final prefix
     * @return array Matching terms keyed to confidence scores
     */
    protected function findTypeaheadMatchScores(
        string $prefix,
        int $siteId,
        ?array $candidateDocIds = null,
        array $excludedTerms = [],
    ): array {
        if ($prefix === '') {
            return [];
        }

        $excluded = array_fill_keys($excludedTerms, true);
        $matches = [];

        if ($candidateDocIds !== null) {
            foreach ($candidateDocIds as $docId) {
                [$docSiteId, $elementId] = explode(':', (string)$docId, 2);
                if ((int)$docSiteId !== $siteId) {
                    continue;
                }

                foreach (array_keys($this->getDocumentTerms($siteId, (int)$elementId)) as $term) {
                    if (isset($excluded[$term]) || !str_starts_with((string)$term, $prefix)) {
                        continue;
                    }

                    $matches[$term] = max(
                        $matches[$term] ?? 0.0,
                        $this->calculateTypeaheadConfidence($prefix, (string)$term)
                    );
                }
            }
        } else {
            foreach ($this->getTermsByPrefix($prefix, $siteId, $this->fuzzySearchMaxCandidates) as $term => $confidence) {
                if (isset($excluded[$term])) {
                    continue;
                }

                $matches[$term] = max((float)$confidence, $this->calculateTypeaheadConfidence($prefix, (string)$term));
            }
        }

        if (empty($matches) && mb_strlen($prefix, 'UTF-8') >= 3) {
            $matches = $this->mergeFuzzyTypeaheadMatches($prefix, $siteId, $candidateDocIds, $excluded, $matches);
        }

        arsort($matches);

        $limit = $candidateDocIds !== null
            ? max($this->fuzzySearchMaxCandidates, 1000)
            : $this->fuzzySearchMaxCandidates;

        return array_slice($matches, 0, $limit, true);
    }

    /**
     * Add fuzzy prefix candidates for the final in-progress token.
     *
     * @param string $prefix Current final query token
     * @param int $siteId Site to search within
     * @param array|null $candidateDocIds Optional prior-matching documents
     * @param array $excluded Completed terms keyed to true
     * @param array $matches Existing prefix matches
     * @return array Matches with fuzzy prefix candidates merged in
     */
    protected function mergeFuzzyTypeaheadMatches(
        string $prefix,
        int $siteId,
        ?array $candidateDocIds,
        array $excluded,
        array $matches,
    ): array {
        if ($candidateDocIds !== null) {
            foreach ($candidateDocIds as $docId) {
                [$docSiteId, $elementId] = explode(':', (string)$docId, 2);
                if ((int)$docSiteId !== $siteId) {
                    continue;
                }

                foreach (array_keys($this->getDocumentTerms($siteId, (int)$elementId)) as $term) {
                    if (isset($excluded[$term]) || isset($matches[$term])) {
                        continue;
                    }

                    $confidence = $this->calculateFuzzyTypeaheadConfidence($prefix, (string)$term);
                    if ($confidence > 0) {
                        $matches[$term] = $confidence;
                    }
                }
            }

            return $matches;
        }

        foreach ($this->findFuzzyMatchScores($prefix, siteId: $siteId) as $term => $confidence) {
            if (isset($excluded[$term]) || isset($matches[$term])) {
                continue;
            }

            $termConfidence = $this->calculateFuzzyTypeaheadConfidence($prefix, (string)$term);
            if ($termConfidence > 0) {
                $matches[$term] = min((float)$confidence, $termConfidence);
            }
        }

        return $matches;
    }

    /**
     * Calculate a confidence multiplier for final-token prefix completion.
     *
     * @param string $prefix Current final query token
     * @param string $candidate Indexed candidate term
     * @return float Score multiplier between 0 and 1
     */
    protected function calculateTypeaheadConfidence(string $prefix, string $candidate): float
    {
        if ($prefix === $candidate) {
            return 1.0;
        }

        $coverage = mb_strlen($prefix, 'UTF-8') / max(1, mb_strlen($candidate, 'UTF-8'));

        return max(0.6, min(0.95, 0.6 + ($coverage * 0.35)));
    }

    /**
     * Calculate confidence for typo-tolerant final-token prefix completion.
     *
     * @param string $prefix Current final query token
     * @param string $candidate Indexed candidate term
     * @return float Score multiplier, or 0 when the candidate is too distant
     */
    protected function calculateFuzzyTypeaheadConfidence(string $prefix, string $candidate): float
    {
        $prefixLength = mb_strlen($prefix, 'UTF-8');
        if ($prefixLength < 3 || $prefixLength >= mb_strlen($candidate, 'UTF-8')) {
            return 0.0;
        }

        $candidatePrefix = mb_substr($candidate, 0, $prefixLength, 'UTF-8');
        $maxDistance = $prefixLength <= 4 ? 1 : 2;
        $distance = levenshtein($prefix, $candidatePrefix);

        if ($distance > $maxDistance) {
            return 0.0;
        }

        $editSimilarity = 1 - ($distance / max(1, $prefixLength));
        $coverage = $prefixLength / max(1, mb_strlen($candidate, 'UTF-8'));

        return max(0.25, min(0.55, (0.35 * $coverage) + (0.65 * $editSimilarity)));
    }

    /**
     * Reduce broad final-token prefix matches when the completed term is not in the title.
     *
     * @param string $prefix Current final query token
     * @return float Confidence multiplier
     */
    protected function getNonTitleTypeaheadConfidenceMultiplier(string $prefix): float
    {
        $prefixLength = mb_strlen($prefix, 'UTF-8');

        if ($prefixLength <= 1) {
            return 0.12;
        }

        if ($prefixLength === 2) {
            return 0.25;
        }

        if ($prefixLength <= 4) {
            return 0.45;
        }

        return 0.7;
    }

    /**
     * Ignore one-character typeahead matches that only appear outside the title.
     *
     * @param string $prefix Current final query token
     * @param string $candidate Indexed candidate term
     * @param string $docId Document ID
     * @return bool Whether the candidate should be skipped for this document
     */
    protected function shouldSkipNonTitleTypeaheadMatch(string $prefix, string $candidate, string $docId): bool
    {
        return mb_strlen($prefix, 'UTF-8') <= 1 && !$this->isTermInTitle($candidate, $docId);
    }

    /**
     * Decide whether to look for fuzzy candidates for a search term.
     *
     * Long exact terms may still be typos that exist elsewhere in the index, so fuzzy
     * supplements are useful. Short exact terms are noisy, so keep them exact unless
     * no exact match exists.
     *
     * @param string $term The search term
     * @param bool $hasExactMatches Whether exact matches were found on the active site
     * @return bool Whether fuzzy matching should run
     */
    protected function shouldFindFuzzyMatches(string $term, bool $hasExactMatches): bool
    {
        $termLength = mb_strlen($term, 'UTF-8');

        return $termLength >= 3 && (!$hasExactMatches || $termLength >= 5);
    }

    /**
     * Keep short fuzzy searches conservative while still allowing longer typo repairs.
     *
     * @param string $term The search term
     * @param int $maxDistance Configured maximum edit distance
     * @return int Effective maximum edit distance
     */
    protected function getAdaptiveMaxDistance(string $term, int $maxDistance): int
    {
        if (mb_strlen($term, 'UTF-8') <= 3) {
            return min(1, $maxDistance);
        }

        return $maxDistance;
    }

    /**
     * Check a fuzzy candidate against edit distance after n-gram preselection.
     *
     * @param string $term The search term
     * @param string $candidate The indexed candidate term
     * @param int $maxDistance Effective maximum edit distance
     * @return bool Whether the candidate is close enough
     */
    protected function isWithinFuzzyEditDistance(string $term, string $candidate, int $maxDistance): bool
    {
        if ($this->isFuzzyPrefixMatch($term, $candidate)) {
            return true;
        }

        return levenshtein($term, $candidate) <= $maxDistance;
    }

    /**
     * Check whether a query term is a useful prefix of an indexed term.
     *
     * @param string $term The search term
     * @param string $candidate The indexed candidate term
     * @return bool Whether this is an acceptable partial-word match
     */
    protected function isFuzzyPrefixMatch(string $term, string $candidate): bool
    {
        return mb_strlen($term, 'UTF-8') >= 4
            && mb_strlen($term, 'UTF-8') < mb_strlen($candidate, 'UTF-8')
            && str_starts_with($candidate, $term);
    }

    /**
     * Calculate a confidence multiplier for fuzzy and partial matches.
     *
     * @param string $term The search term
     * @param string $candidate The indexed candidate term
     * @param float $ngramSimilarity Candidate n-gram similarity
     * @return float Score multiplier between 0 and 1
     */
    protected function calculateFuzzyConfidence(string $term, string $candidate, float $ngramSimilarity): float
    {
        if ($term === $candidate) {
            return 1.0;
        }

        if ($this->isFuzzyPrefixMatch($term, $candidate)) {
            $coverage = mb_strlen($term, 'UTF-8') / max(1, mb_strlen($candidate, 'UTF-8'));
            return max(0.25, min(0.45, (0.5 * $coverage) + (0.5 * $ngramSimilarity)));
        }

        $distance = levenshtein($term, $candidate);
        $length = max(mb_strlen($term, 'UTF-8'), mb_strlen($candidate, 'UTF-8'), 1);
        $editSimilarity = 1 - ($distance / $length);

        return max(0.25, min(0.6, (0.6 * $editSimilarity) + (0.4 * $ngramSimilarity)));
    }

    /**
     * Get adaptive similarity threshold based on term length
     * Shorter terms get lower thresholds for better fuzzy matching
     *
     * @param string $term The search term
     * @return float Adaptive threshold
     */
    protected function getAdaptiveThreshold(string $term): float
    {
        $termLength = mb_strlen($term, 'UTF-8');
        $baseThreshold = $this->ngramSimilarityThreshold;
        
        // Apply scaling factor based on term length
        if ($termLength <= 2) {
            // Very short terms: use much lower threshold
            return max(0.1, $baseThreshold * 0.4);
        } elseif ($termLength == 3) {
            // 3-character terms: use lower threshold
            return max(0.15, $baseThreshold * 0.6);
        } elseif ($termLength == 4) {
            // 4-character terms: slightly lower threshold
            return max(0.2, $baseThreshold * 0.8);
        } elseif ($termLength <= 6) {
            // 5-6 character terms need room for common adjacent-letter transpositions
            return max(0.1, $baseThreshold * 0.4);
        }
        
        // 5+ character terms: use full threshold
        return $baseThreshold;
    }

    /**
     * Generate n-grams for a term
     *
     * @param string $term The term to generate n-grams for
     * @param array|null $sizes N-gram sizes to generate (defaults to configured sizes)
     * @return array Array of n-grams
     */
    protected function generateNgrams(string $term, ?array $sizes = null): array
    {
        $sizes = $sizes ?? $this->ngramSizes;
        $ngrams = [];

        // Add padding to capture word boundaries
        $paddedTerm = ' ' . $term . ' ';

        foreach ($sizes as $size) {
            if ($size < 1) {
                continue;
            }

            // Use mb_strlen for proper UTF-8 multibyte character handling
            $termLength = mb_strlen($paddedTerm, 'UTF-8');

            // Generate n-grams of specified size
            for ($i = 0; $i <= $termLength - $size; $i++) {
                // Use mb_substr for proper UTF-8 multibyte character extraction
                $ngram = mb_substr($paddedTerm, $i, $size, 'UTF-8');

                // Skip n-grams that are just spaces
                if (trim($ngram) !== '') {
                    $ngrams[] = $ngram;
                }
            }
        }

        return array_values(array_unique($ngrams));
    }

    /**
     * Calculate Jaccard similarity between two sets of n-grams
     *
     * @param array $ngrams1 First set of n-grams
     * @param array $ngrams2 Second set of n-grams
     * @return float Similarity score between 0.0 and 1.0
     */
    protected function calculateNgramSimilarity(array $ngrams1, array $ngrams2): float
    {
        if (empty($ngrams1) || empty($ngrams2)) {
            return 0.0;
        }

        $intersection = count(array_intersect($ngrams1, $ngrams2));
        $union = count(array_unique(array_merge($ngrams1, $ngrams2)));

        return $intersection / $union;
    }

    /**
     * Normalizes a search query value to a string for internal processing.
     *
     * Handles both string search queries and SearchQuery objects from Craft CMS.
     * When Craft processes searches (especially in the asset manager), it may pass
     * SearchQuery objects instead of strings. This method extracts the query string
     * from SearchQuery objects so it can be used in tokenization and other string operations.
     *
     * @param string|SearchQuery|null $search The search query value
     * @return string The normalized search query string
     */
    protected function normalizeSearchQueryToString(string|SearchQuery|null $search): string
    {
        if ($search instanceof SearchQuery) {
            return $search->getQuery();
        }

        return (string)$search;
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
        return array_values(array_filter(explode(' ', $text)));
    }

    /**
     * Remove stop words from an array of tokens
     *
     * @param array $tokens Array of tokens to filter
     * @return array Filtered tokens
     */
    protected function filterStopWords(array $tokens): array
    {
        return array_values(array_filter($tokens, fn($t) => !in_array($t, $this->stopWords, true)));
    }

    /**
     * Return only stop words from an array of tokens.
     *
     * @param array $tokens Array of tokens to filter
     * @return array Stop word tokens
     */
    protected function getStopWords(array $tokens): array
    {
        return array_values(array_filter($tokens, fn($t) => in_array($t, $this->stopWords, true)));
    }

    /**
     * Tokenize a search string, preserving stop words only when they are needed.
     *
     * Stop words are normally ignored for broad relevance, but title fields index them so
     * title-only searches like "Why" should not be reduced to an empty query. Typeahead
     * queries also keep completed stop words when they are the only completed terms, so
     * "your p" can narrow to title-backed "Your Pregnancy..." results instead of becoming
     * a global search for "p".
     *
     * @param string $text The search query
     * @param bool $isTypeaheadQuery Whether the final token should be treated as a prefix
     * @return array Search tokens
     */
    protected function getSearchTokens(string $text, bool $isTypeaheadQuery = false): array
    {
        $tokens = $this->tokenize($text);
        $filteredTokens = $this->filterStopWords($tokens);

        if ($isTypeaheadQuery && count($tokens) > 1) {
            $finalToken = (string)array_pop($tokens);
            $completedTokens = $tokens;
            $filteredCompletedTokens = $this->filterStopWords($completedTokens);

            return [
                ...(!empty($filteredCompletedTokens) ? $filteredCompletedTokens : $completedTokens),
                $finalToken,
            ];
        }

        return !empty($filteredTokens) ? $filteredTokens : $tokens;
    }

    /**
     * Filter document matches to the active site before deciding whether fuzzy fallback is needed.
     *
     * @param array $documents Document frequencies keyed by doc ID
     * @param int $siteId Active site ID
     * @return array Site-scoped document frequencies
     */
    protected function filterDocumentsBySite(array $documents, int $siteId): array
    {
        $sitePrefix = "$siteId:";

        return array_filter(
            $documents,
            fn($freq, $docId) => str_starts_with((string)$docId, $sitePrefix),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Find indexed terms that start with a prefix.
     *
     * Storage adapters can override this for a native prefix lookup. The fallback
     * filters the existing term dictionary and confirms terms exist on the active site.
     *
     * @param string $prefix Prefix to match
     * @param int $siteId Active site ID
     * @param int $limit Maximum terms to return
     * @return array Matching terms keyed to confidence scores
     */
    protected function getTermsByPrefix(string $prefix, int $siteId, int $limit = 100): array
    {
        $matches = [];

        foreach ($this->getAllTerms() as $term) {
            $term = (string)$term;
            if (!str_starts_with($term, $prefix)) {
                continue;
            }

            if (empty($this->filterDocumentsBySite($this->getTermDocuments($term), $siteId))) {
                continue;
            }

            $matches[$term] = $this->calculateTypeaheadConfidence($prefix, $term);

            if (count($matches) >= $limit) {
                break;
            }
        }

        arsort($matches);

        return $matches;
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
     * Check whether the document contains all phrase terms for exact-match boosting.
     *
     * The index stores term frequencies, not positions, so this is a term-presence
     * approximation rather than an ordered phrase check.
     *
     * @param string $docId The document ID (siteId:elementId)
     * @param string $phrase The phrase to check
     * @return bool Whether the document contains the exact phrase
     */
    protected function containsExactPhrase(string $docId, string $phrase): bool
    {
        $tokens = $this->getSearchTokens($phrase);

        [$siteId, $elementId] = explode(':', $docId);
        $docTerms = $this->getDocumentTerms((int)$siteId, (int)$elementId);

        foreach ($tokens as $token) {
            if (!isset($docTerms[$token])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a document satisfies a final-token prefix phrase approximation.
     *
     * @param string $docId The document ID (siteId:elementId)
     * @param array $tokens Search tokens
     * @return bool Whether completed terms and final prefix are present
     */
    protected function containsTypeaheadPhrase(string $docId, array $tokens): bool
    {
        if (count($tokens) < 2) {
            return false;
        }

        $titleTerms = $this->getTitleTerms($docId);
        $finalPrefix = (string)array_pop($tokens);
        $completedTerms = array_fill_keys($tokens, true);

        foreach ($tokens as $token) {
            if (!isset($titleTerms[$token])) {
                return false;
            }
        }

        foreach (array_keys($titleTerms) as $term) {
            if (isset($completedTerms[$term])) {
                continue;
            }

            if (str_starts_with((string)$term, $finalPrefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the query closely matches the document title terms.
     *
     * @param string $docId The document ID (siteId:elementId)
     * @param array $tokens Search tokens
     * @param bool $isTypeaheadQuery Whether the final token is a prefix
     * @return bool Whether the title is a tight query match
     */
    protected function containsTightTitleMatch(string $docId, array $tokens, bool $isTypeaheadQuery): bool
    {
        if (empty($tokens)) {
            return false;
        }

        $titleTerms = $this->getComparableTitleTerms($docId, $this->containsStopWord($tokens));
        if (empty($titleTerms)) {
            return false;
        }

        $originalTokenCount = count($tokens);
        $completedTerms = $tokens;
        $finalPrefix = null;

        if ($isTypeaheadQuery) {
            $finalPrefix = (string)array_pop($completedTerms);
        }

        foreach ($completedTerms as $token) {
            if (!isset($titleTerms[$token])) {
                return false;
            }
        }

        if ($finalPrefix !== null) {
            $matchedFinalPrefix = false;
            foreach (array_keys($titleTerms) as $term) {
                if (isset($titleTerms[$term]) && in_array($term, $completedTerms, true)) {
                    continue;
                }

                if (str_starts_with((string)$term, $finalPrefix)) {
                    $matchedFinalPrefix = true;
                    break;
                }
            }

            if (!$matchedFinalPrefix) {
                return false;
            }
        }

        $allowedExtraTitleTerms = $isTypeaheadQuery ? 2 : 0;

        return count($titleTerms) <= $originalTokenCount + $allowedExtraTitleTerms;
    }

    /**
     * Check whether the query terms exactly match the comparable title term set.
     *
     * @param string $docId The document ID (siteId:elementId)
     * @param array $tokens Search tokens
     * @return bool Whether the title term set exactly matches the query terms
     */
    protected function containsExactTitleTermSet(string $docId, array $tokens): bool
    {
        $titleTerms = $this->getComparableTitleTerms($docId);
        $queryTerms = [];

        foreach ($tokens as $token) {
            $token = (string)$token;
            if (preg_match('/^\d+$/', $token)) {
                continue;
            }
            if (in_array($token, $this->stopWords, true)) {
                continue;
            }

            $queryTerms[$token] = true;
        }

        if (empty($titleTerms) || count($titleTerms) !== count($queryTerms)) {
            return false;
        }

        foreach (array_keys($queryTerms) as $term) {
            if (!isset($titleTerms[$term])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Decide whether the exact-title boost is appropriate for this query.
     *
     * A one-character typeahead query such as "p" should still autocomplete broadly;
     * longer exact title queries should beat longer title supersets.
     *
     * @param array $tokens Search tokens
     * @param bool $isTypeaheadQuery Whether the final token is a prefix
     * @return bool Whether exact title term sets should receive the strongest boost
     */
    protected function shouldBoostExactTitleTermSet(array $tokens, bool $isTypeaheadQuery): bool
    {
        if (!$isTypeaheadQuery || count($tokens) > 1) {
            return true;
        }

        $token = (string)reset($tokens);

        return mb_strlen($token, 'UTF-8') > 1;
    }

    /**
     * Get title terms suitable for title tightness checks.
     *
     * @param string $docId The document ID (siteId:elementId)
     * @param bool $preserveStopWords Whether stop words should remain comparable
     * @return array Comparable title terms keyed to true
     */
    protected function getComparableTitleTerms(string $docId, bool $preserveStopWords = false): array
    {
        $terms = [];

        foreach (array_keys($this->getTitleTerms($docId)) as $term) {
            $term = (string)$term;
            if (preg_match('/^\d+$/', $term)) {
                continue;
            }
            if (!$preserveStopWords && in_array($term, $this->stopWords, true)) {
                continue;
            }

            $terms[$term] = true;
        }

        return $terms;
    }

    /**
     * Check whether any query token is a stop word.
     *
     * @param array $tokens Search tokens
     * @return bool Whether the token set contains a stop word
     */
    protected function containsStopWord(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (in_array((string)$token, $this->stopWords, true)) {
                return true;
            }
        }

        return false;
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

    /**
     * Get cached search statistics for the current search operation
     *
     * This method caches the total document count and average document length
     * to avoid repeated I/O operations during BM25 calculations.
     *
     * @return array Array with 'totalDocs' and 'avgDocLength' keys
     */
    protected function getSearchStatistics(): array
    {
        static $cachedStats = null;
        
        if ($cachedStats === null) {
            $totalDocs = max(1, $this->getTotalDocCount());
            $totalLength = max(1, $this->getTotalLength());
            $avgDocLength = $totalLength / $totalDocs;
            
            $cachedStats = [
                'totalDocs' => $totalDocs,
                'avgDocLength' => $avgDocLength,
            ];
        }
        
        return $cachedStats;
    }

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
     * Get document lengths for multiple documents in a single batch operation
     *
     * @param array $docIds Array of document IDs
     * @return array Associative array with docId => length
     */
    abstract protected function getDocumentLengthsBatch(array $docIds): array;

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

            if ($totalLengthToRemove > 0) {
                $this->updateTotalLength(-$totalLengthToRemove);
            }

            // Clear all terms that no longer have documents
            $this->cleanupOrphanedTerms();

            // Clear n-grams for this site
            $this->clearNgrams($siteId);

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
     * Remove indexed documents for a site that were not present in the latest rebuild pass.
     *
     * Rebuild jobs call this after all current elements have been indexed so searches can
     * continue using the previous index while the new one is being built.
     *
     * @param int $siteId The site ID to prune
     * @param array<int> $activeElementIds Element IDs that should remain indexed
     * @return bool Whether the operation was successful
     */
    public function pruneIndexForSite(int $siteId, array $activeElementIds): bool
    {
        try {
            $activeDocIds = [];
            foreach ($activeElementIds as $elementId) {
                $elementId = (int)$elementId;
                if ($elementId > 0) {
                    $activeDocIds["$siteId:$elementId"] = true;
                }
            }

            $removed = 0;
            $totalLengthToRemove = 0;

            foreach ($this->getSiteDocuments($siteId) as $docId) {
                if (isset($activeDocIds[$docId])) {
                    continue;
                }

                [$docSiteId, $elementId] = explode(':', $docId, 2);
                if ((int)$docSiteId !== $siteId) {
                    continue;
                }

                $totalLengthToRemove += $this->getDocumentLength($docId);
                if (!$this->deleteElementFromIndex((int)$elementId, $siteId)) {
                    return false;
                }

                $removed++;
            }

            if ($totalLengthToRemove > 0) {
                $this->updateTotalLength(-$totalLengthToRemove);
            }

            if ($removed > 0) {
                $this->cleanupOrphanedTerms();
            }

            $this->updateTotalDocCount();

            Craft::info(
                sprintf(
                    'Search index pruned for site ID: %d; removed %d stale document%s',
                    $siteId,
                    $removed,
                    $removed === 1 ? '' : 's'
                ),
                __METHOD__
            );

            return true;
        } catch (\Throwable $e) {
            Craft::error("Error pruning search index: " . $e->getMessage(), __METHOD__);
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
        $siteIds = Craft::$app->getSites()->getAllSiteIds(true);

        foreach ($allTerms as $term) {
            $docs = $this->getTermDocuments($term);

            if (empty($docs)) {
                $this->removeTermFromIndex($term);
                
                // Also remove n-grams for this globally orphaned term
                foreach ($siteIds as $siteId) {
                    $this->removeTermNgrams($term, $siteId);
                }
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

    // =========================================================================
    // N-GRAM ABSTRACT METHODS
    // =========================================================================

    /**
     * Store n-grams for a term in the index
     *
     * @param string $term The term to store n-grams for
     * @param array $ngrams Array of n-grams for the term
     * @param int $siteId The site ID
     * @return void
     */
    abstract protected function storeTermNgrams(string $term, array $ngrams, int $siteId): void;

    /**
     * Get terms that have similar n-grams to the search term
     *
     * @param array $ngrams N-grams of the search term
     * @param int $siteId The site ID
     * @param float $threshold Minimum similarity threshold (0.0 - 1.0)
     * @return array Array of [term => similarity_score]
     */
    abstract protected function getTermsByNgramSimilarity(array $ngrams, int $siteId, float $threshold): array;

    /**
     * Check if a term already has n-grams stored
     *
     * @param string $term The term to check
     * @param int $siteId The site ID
     * @return bool Whether the term has n-grams
     */
    abstract protected function termHasNgrams(string $term, int $siteId): bool;

    /**
     * Clear all n-grams for a site
     *
     * @param int $siteId The site ID
     * @return void
     */
    abstract protected function clearNgrams(int $siteId): void;

    /**
     * Remove n-grams for a specific term
     *
     * @param string $term The term to remove n-grams for
     * @param int $siteId The site ID
     * @return void
     */
    abstract protected function removeTermNgrams(string $term, int $siteId): void;
}
