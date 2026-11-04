<?php

namespace MadeByBramble\BrambleSearch\adapters;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\QueryAbortedException;
use craft\elements\db\ElementQuery;
use craft\events\IndexKeywordsEvent;
use craft\events\SearchEvent;
use craft\helpers\ElementHelper;
use craft\helpers\Search as SearchHelper;
use craft\search\SearchQuery;
use craft\services\Search;
use MadeByBramble\BrambleSearch\helpers\IndexableElements;
use MadeByBramble\BrambleSearch\helpers\NumericTerm;
use MadeByBramble\BrambleSearch\helpers\RebuildState;
use MadeByBramble\BrambleSearch\helpers\SearchQueryParser;
use MadeByBramble\BrambleSearch\helpers\TitleAliases;
use MadeByBramble\BrambleSearch\models\Settings;

/**
 * Base Search Adapter
 *
 * Abstract base class for all search adapters that implements common functionality
 * including BM25 scoring, fuzzy search, and title boosting. Defines abstract methods
 * that must be implemented by storage-specific adapters.
 */
abstract class BaseSearchAdapter extends Search
{
    use DocumentStateTrait;
    use PrefixLifecycleTrait;
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

    /**
     * Match-ratio threshold above which a demotable AND group is treated as optional.
     * Values <= 0 disable proactive demotion.
     */
    protected float $commonTermDemotionThreshold = 0.5;

    /**
     * Whether front-end site searches treat the final token as an in-progress prefix.
     * The control panel's live element index search always does.
     */
    protected bool $siteSearchAsYouType = false;

    protected array $titleAliases = [];

    /**
     * Request-scoped read caches, cleared whenever the index is mutated
     */
    private array $memoTermDocs = [];
    private array $memoTitleTerms = [];
    private array $memoDocTerms = [];
    private ?array $memoSearchStats = null;

    // =========================================================================
    // INITIALIZATION METHODS
    // =========================================================================

    /**
     * Initialize the adapter
     */
    public function init(): void
    {
        parent::init();
        $this->loadSettings();
        $this->loadStopWords();
    }

    /**
     * Load stop words from language file, then apply plugin settings overrides.
     */
    protected function loadStopWords(): void
    {
        $bundled = require Craft::getAlias('@bramble_search/stopwords/en.php');
        $extra = [];
        $remove = [];

        $plugin = \MadeByBramble\BrambleSearch\Plugin::$plugin;
        if ($plugin) {
            /** @var Settings $settings */
            $settings = $plugin->getSettings();
            $extra = $settings->extraStopWords;
            $remove = $settings->removeStopWords;
        }

        $this->stopWords = Settings::mergeStopWordLists($bundled, $extra, $remove);
        $this->titleAliases = TitleAliases::compile($plugin?->getSettings()->titleAliases ?? [], $this->stopWords);
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
            $this->siteSearchAsYouType = $settings->siteSearchAsYouType ?? false;
            $this->commonTermDemotionThreshold = $settings->commonTermDemotionThreshold ?? 0.5;
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
     * @param array|null $fieldHandles Specific field handles to index, null for all, or [] for attributes only
     * @return bool Whether the indexing was successful
     */
    public function indexElementAttributes(ElementInterface $element, array|null $fieldHandles = null): bool
    {
        if ($element->id && $element->siteId && !$element::isLocalized() && Craft::$app->getSites()->getPrimarySite()->id !== $element->siteId) {
            throw new \RuntimeException('Primary-only elements cannot be indexed on a secondary site.');
        }
        return $this->withElementIndexLock(
            $element,
            fn() => $this->indexElementAttributesUnlocked($element, $fieldHandles)
        );
    }

    /**
     * Remove an element from the index while preserving metadata consistency.
     *
     * @param ElementInterface $element The element to remove
     * @return bool Whether the removal was successful
     */
    public function deleteElementIndex(ElementInterface $element): bool
    {
        return $this->withElementIndexLock(
            $element,
            fn() => $this->removeElementFromIndexAndUpdateMetadata($element)
        );
    }

    /**
     * Run an element index mutation under Craft's normal searchindex mutex.
     *
     * @param ElementInterface $element The element being mutated
     * @param callable $callback Mutation callback
     * @return bool Whether the mutation was successful
     */
    protected function withElementIndexLock(ElementInterface $element, callable $callback): bool
    {
        return !$element->id || !$element->siteId ? true : $this->withElementIndexLockById($element->id, $element->siteId, $callback);
    }

    protected function withElementIndexLockById(int $id, int $siteId, callable $callback): bool
    {
        return $this->withDocumentIndexLock($siteId, $id, $callback);
    }

    public function indexPersistedElement(string $type, int $id, int $siteId, ?array $fieldHandles = null): bool
    {
        if ($id <= 0 || $siteId <= 0) {
            throw new \InvalidArgumentException('Persisted indexing requires a positive element and site ID.');
        }
        IndexableElements::assertFreshReadContext();
        $siteId = IndexableElements::siteIdForType($type, $siteId);

        return $this->withElementIndexLockById($id, $siteId, fn(): bool => $this->indexPersistedElementUnlocked($type, $id, $siteId, $fieldHandles));
    }

    public function indexElementIfQueued(int $elementId, int $siteId, ?string $elementType = null): void
    {
        if (!method_exists(Search::class, __FUNCTION__)) {
            throw new \RuntimeException('Native queued indexing requires Craft 5.7 or later.');
        }
        IndexableElements::assertFreshReadContext();
        $elementType ??= Craft::$app->getElements()->getElementTypeById($elementId);
        if ($elementType !== null) {
            $siteId = IndexableElements::siteIdForType($elementType, $siteId);
        }
        $this->withElementIndexLockById($elementId, $siteId, function() use ($elementId, $siteId, $elementType): bool {
            parent::indexElementIfQueued($elementId, $siteId, $elementType);

            return true;
        });
    }

    protected function indexPersistedElementUnlocked(string $type, int $id, int $siteId, ?array $fieldHandles): bool
    {
        $element = IndexableElements::load($type, $id, $siteId);

        return $element === null || !IndexableElements::isEligible($element)
            ? $this->publishDocumentDeletion($siteId, $id)
            : $this->indexElementAttributesUnlocked($element, $fieldHandles);
    }

    public function auditIndexMetadata(): array
    {
        return $this->withPublicationGuard(function(): array {
            $publication = $this->publicationState();
            $publication['dirty'] = true;
            $this->writeIndexRecord('publication', $publication);
            $this->repairIndexMetadata($publication);
            $this->clearSearchMemo();

            return ['documents' => $this->getTotalDocCount(), 'length' => $this->getTotalLength()];
        });
    }

    /**
     * Index an element's attributes after the caller has acquired the element lock.
     *
     * @param ElementInterface $element The element to index
     * @param array|null $fieldHandles Specific field handles to index, or null for all
     * @return bool Whether the indexing was successful
     */
    protected function indexElementAttributesUnlocked(ElementInterface $element, array|null $fieldHandles = null): bool
    {
        return $this->mutateDocument($element, $fieldHandles);
    }

    /**
     * Remove an element that should no longer be indexed and keep metadata totals in sync.
     */
    protected function removeElementFromIndexAndUpdateMetadata(ElementInterface $element): bool
    {
        return !$element->id || !$element->siteId || $this->deleteElementFromIndex($element->id, $element->siteId);
    }

    /**
     * Explicit score ordering uses the scored event boundary; other queries share its resolver.
     *
     * @param ElementQuery $elementQuery
     * @return bool
     */
    public function shouldCallSearchElements(ElementQuery $elementQuery): bool
    {
        return $elementQuery->search !== null && isset($elementQuery->orderBy['score']);
    }

    /**
     * Returns a database query which will fetch matching element IDs for filter-only searches.
     *
     * @param string|array|SearchQuery $searchQuery The search term to filter the resulting elements by.
     * @param ElementQuery $elementQuery The element query being executed
     * @return Query|false
     */
    public function createDbQuery(string|array|SearchQuery $searchQuery, ElementQuery $elementQuery): Query|false
    {
        if (is_array($searchQuery)) {
            $searchQuery = Craft::$app->getSearch()->normalizeSearchQuery($searchQuery);
        }

        $parsed = SearchQueryParser::parse($searchQuery);
        $parsed = $this->expandMultiTokenTerms($parsed);
        $parsed = $this->filterStopWordGroups($parsed);
        $matches = [];
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SEARCH)
            || $this->hasEventHandlers(self::EVENT_BEFORE_SCORE_RESULTS)
            || $this->hasEventHandlers(self::EVENT_AFTER_SEARCH)) {
            $eventQuery = clone $elementQuery;
            $eventQuery->search($searchQuery);
            foreach (array_keys($this->searchElements($eventQuery)) as $key) {
                if (preg_match('/\A([1-9][0-9]*)-([1-9][0-9]*)\z/D', (string)$key, $parts) === 1) {
                    $matches[(int)$parts[2]][] = (int)$parts[1];
                }
            }
        } else {
            foreach ($this->resolveQuerySiteIds($elementQuery) as $siteId) {
                foreach ($this->findMatchingDocIdsForParsedQuery($parsed, $siteId, $elementQuery, false) as $docId) {
                    [, $elementId] = explode(':', (string)$docId, 2);
                    $matches[$siteId][] = (int)$elementId;
                }
            }
        }
        if ($matches === []) {
            return false;
        }
        $condition = ['or'];
        foreach ($matches as $siteId => $ids) {
            $condition[] = ['bs_matches.siteId' => $siteId, 'bs_matches.elementId' => array_values(array_unique($ids))];
        }

        return (new Query())
            ->select(['elementId' => 'bs_matches.elementId', 'siteId' => 'bs_matches.siteId'])
            ->from(['bs_matches' => '{{%elements_sites}}'])
            ->where($condition)
            ->andWhere(new \yii\db\Expression('[[bs_matches.siteId]] = [[elements_sites.siteId]]'));
    }

    /**
     * Delete orphaned Bramble index entries whose elements no longer exist.
     */
    public function deleteOrphanedIndexes(): void
    {
        $this->deleteOrphanedIndexesFromAdapter();
        parent::deleteOrphanedIndexes();
    }

    /**
     * @return list<string|null>
     */
    public function getSearchableFieldHandles(ElementInterface $element): array
    {
        $fieldHandles = [];
        $fieldLayout = $element->getFieldLayout();

        if (!$fieldLayout) {
            return $fieldHandles;
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            if ($field->searchable) {
                $fieldHandles[] = $field->handle;
            }
        }

        return $fieldHandles;
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
    /**
     * Clear the request-scoped read caches. Called whenever the index is mutated.
     */
    protected function clearSearchMemo(): void
    {
        $this->memoTermDocs = [];
        $this->memoTitleTerms = [];
        $this->memoDocTerms = [];
        $this->memoSearchStats = null;
        $this->memoSourceStates = [];
    }

    /**
     * Memoized getTermDocuments()
     */
    protected function termDocumentsCached(string $term): array
    {
        $this->synchronizeSearchMemo();
        return $this->memoTermDocs[$term] ??= $this->getTermDocuments($term);
    }

    /**
     * Memoized getTitleTerms()
     */
    protected function titleTermsCached(string $docId): array
    {
        $this->synchronizeSearchMemo();
        return $this->memoTitleTerms[$docId] ??= $this->getTitleTerms($docId);
    }

    /**
     * Memoized getDocumentTerms()
     */
    protected function documentTermsCached(int $siteId, int $elementId): array
    {
        $this->synchronizeSearchMemo();
        return $this->memoDocTerms["$siteId:$elementId"] ??= $this->getDocumentTerms($siteId, $elementId);
    }

    /**
     * Prefetch term documents into the request cache.
     *
     * @param array<string> $terms
     */
    protected function warmTermDocuments(array $terms): void
    {
        $this->synchronizeSearchMemo();
        $missing = [];
        foreach ($terms as $term) {
            $term = (string)$term;
            if (!array_key_exists($term, $this->memoTermDocs)) {
                $missing[] = $term;
            }
        }

        foreach ($this->getTermDocumentsBatch($missing) as $term => $docs) {
            $this->memoTermDocs[$term] = $docs;
        }
    }

    /**
     * Prefetch title terms into the request cache.
     *
     * @param array<string> $docIds Document IDs (siteId:elementId)
     */
    protected function warmTitleTerms(array $docIds): void
    {
        $this->synchronizeSearchMemo();
        $missing = [];
        foreach ($docIds as $docId) {
            $docId = (string)$docId;
            if (!array_key_exists($docId, $this->memoTitleTerms)) {
                $missing[] = $docId;
            }
        }

        foreach ($this->getTitleTermsBatch($missing) as $docId => $titleTerms) {
            $this->memoTitleTerms[$docId] = $titleTerms;
        }
    }

    /**
     * Prefetch document terms into the request cache.
     *
     * @param array<string> $docIds Document IDs (siteId:elementId)
     */
    protected function warmDocumentTerms(array $docIds): void
    {
        $this->synchronizeSearchMemo();
        $missing = [];
        foreach ($docIds as $docId) {
            $docId = (string)$docId;
            if (!array_key_exists($docId, $this->memoDocTerms)) {
                $missing[] = $docId;
            }
        }

        foreach ($this->getDocumentTermsBatch($missing) as $docId => $terms) {
            $this->memoDocTerms[$docId] = $terms;
        }
    }

    /**
     * Fetch documents for multiple terms. Adapters can override with a batched implementation.
     *
     * @param array<string> $terms
     * @return array<string, array> term => documents
     */
    protected function getTermDocumentsBatch(array $terms): array
    {
        $results = [];
        foreach ($terms as $term) {
            $results[$term] = $this->getTermDocuments((string)$term);
        }

        return $results;
    }

    /**
     * Fetch title terms for multiple documents. Adapters can override with a batched implementation.
     *
     * @param array<string> $docIds Document IDs (siteId:elementId)
     * @return array<string, array> docId => title terms
     */
    protected function getTitleTermsBatch(array $docIds): array
    {
        $results = [];
        foreach ($docIds as $docId) {
            $results[$docId] = $this->getTitleTerms((string)$docId);
        }

        return $results;
    }

    /**
     * Fetch terms for multiple documents. Adapters can override with a batched implementation.
     *
     * @param array<string> $docIds Document IDs (siteId:elementId)
     * @return array<string, array> docId => terms
     */
    protected function getDocumentTermsBatch(array $docIds): array
    {
        $results = [];
        foreach ($docIds as $docId) {
            [$siteId, $elementId] = explode(':', (string)$docId, 2);
            $results[$docId] = $this->getDocumentTerms((int)$siteId, (int)$elementId);
        }

        return $results;
    }

    public function searchElements(ElementQuery $elementQuery): array
    {
        $parsed = SearchQueryParser::parse($elementQuery->search);
        $parsed = $this->expandMultiTokenTerms($parsed);
        $parsed = $this->filterStopWordGroups($parsed);
        $searchQuery = $parsed['rawQuery'];

        $filteredQuery = (clone $elementQuery)
            ->select('elements.id')
            ->search(null)
            ->offset(null)
            ->limit(null);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SEARCH)) {
            $this->trigger(self::EVENT_BEFORE_SEARCH, new SearchEvent([
                'elementQuery' => $filteredQuery,
                'query' => $this->normalizeSearchQuery($elementQuery->search),
                'siteId' => $elementQuery->siteId,
            ]));
        }

        $siteIds = $this->resolveQuerySiteIds($filteredQuery);
        $filteredScores = [];
        $promotions = [];
        foreach ($siteIds as $siteId) {
            $siteScores = $this->searchElementsForSite(
                $filteredQuery,
                $siteId,
                $parsed,
                $searchQuery,
                $promotions,
            );
            foreach ($siteScores as $docId => $score) {
                $filteredScores[$docId] = ($filteredScores[$docId] ?? 0) + $score;
            }
        }

        $filteredScores = $this->promoteDocumentScores($filteredScores, $promotions['numeric'] ?? []);
        $filteredScores = $this->promoteDocumentScores($filteredScores, $promotions['aliases'] ?? [], $promotions['newAliases'] ?? []);
        if (empty($filteredScores)) {
            return [];
        }

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SCORE_RESULTS)) {
            $event = new SearchEvent([
                'elementQuery' => $filteredQuery,
                'query' => $this->normalizeSearchQuery($elementQuery->search),
                'siteId' => $elementQuery->siteId,
                'results' => array_keys($filteredScores),
                'scores' => $this->formatScoresForSearchEvent($filteredScores),
            ]);
            $this->trigger(self::EVENT_BEFORE_SCORE_RESULTS, $event);
            if ($event->scores !== null) {
                $this->restrictPreparedResultSites($elementQuery, $event->scores);
                return $event->scores;
            }
        }

        arsort($filteredScores);

        $results = [];
        foreach ($filteredScores as $docId => $score) {
            [$docSiteId, $elementId] = explode(':', (string)$docId);
            $results["$elementId-$docSiteId"] = $score;
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_SEARCH)) {
            $event = new SearchEvent([
                'elementQuery' => $filteredQuery,
                'query' => $this->normalizeSearchQuery($elementQuery->search),
                'siteId' => $elementQuery->siteId,
                'results' => array_keys($results),
                'scores' => $results,
            ]);
            $this->trigger(self::EVENT_AFTER_SEARCH, $event);
            if ($event->scores !== null) {
                $results = $event->scores;
            }
        }

        $this->restrictPreparedResultSites($elementQuery, $results);

        return $results;
    }

    protected function restrictPreparedResultSites(ElementQuery $query, array $scores): void
    {
        if ($query->subQuery === null) {
            return;
        }
        if (($query->orderBy['score'] ?? null) === SORT_ASC) {
            $scores = array_reverse($scores, true);
        }
        if (array_key_first($query->orderBy ?? []) === 'score') {
            if (is_int($query->offset) && $query->offset !== 0) {
                $scores = array_slice($scores, $query->offset, null, true);
            }
            if (is_int($query->limit) && $query->limit !== 0) {
                $scores = array_slice($scores, 0, $query->limit, true);
            }
        }
        $idsBySite = [];
        foreach (array_keys($scores) as $key) {
            if (preg_match('/\A([1-9][0-9]*)-([1-9][0-9]*)\z/D', (string)$key, $parts) !== 1) {
                throw new \InvalidArgumentException('Search scores must identify both element and site.');
            }
            $idsBySite[(int)$parts[2]][] = (int)$parts[1];
        }
        $condition = ['or'];
        foreach ($idsBySite as $siteId => $ids) {
            $condition[] = ['elements_sites.siteId' => $siteId, 'elements.id' => array_values(array_unique($ids))];
        }
        $query->subQuery->andWhere(count($condition) > 1 ? $condition : '0=1');
    }

    /**
     * Score documents for a single site using parsed query semantics.
     *
     * @param array{andGroups: list<array{terms: list<array<string, mixed>>}>, excludeTerms: list<array<string, mixed>>, rawQuery: string} $parsed
     * @return array<string, float> docId => score
     */
    protected function searchElementsForSite(
        ElementQuery $elementQuery,
        int $siteId,
        array $parsed,
        string $searchQuery,
        ?array &$promotions = null,
    ): array {
        $resolved = $this->resolveMatchesForSite($elementQuery, $siteId, $parsed);
        if ($resolved === []) {
            return [];
        }
        $validDocs = $resolved['validDocs'];
        $groupTermData = $resolved['groupTermData'];
        $isTypeaheadQuery = $resolved['isTypeaheadQuery'];
        $numericConstrained = $resolved['numericConstrained'] ?? false;
        $searchStats = $this->getSearchStatistics();
        $totalDocs = $searchStats['totalDocs'];
        $avgDocLength = $searchStats['avgDocLength'];
        $docScores = [];
        $documentLengths = $this->getDocumentLengthsBatch($validDocs);
        $this->warmTitleTerms($validDocs);
        foreach ($groupTermData as $groupIndex => $documents) {
            foreach ($documents as $docId => $data) {
                if (!in_array($docId, $validDocs, true) || isset($resolved['newAliasDocs'][$docId])) {
                    continue;
                }
                if (!$this->termMatchesQueryScope($docId, $data, $elementQuery)) {
                    continue;
                }

                $docLen = max(1, $documentLengths[$docId] ?? 1);
                $score = $this->bm25($data['freq'], $data['docFreq'], $docLen, $avgDocLength, $totalDocs);
                if ($this->isTermInTitle($data['actualTerm'], $docId)) {
                    $score *= $this->titleBoostFactor;
                }
                $score *= $data['confidence'] ?? 1.0;
                $docScores[$docId] = ($docScores[$docId] ?? 0) + $score;
            }
        }

        $tokens = $this->flattenParsedTerms($parsed);
        if (!$isTypeaheadQuery && count($tokens) > 1) {
            $this->warmDocumentTerms($validDocs);
        }
        foreach ($validDocs as $docId) {
            if (!isset($docScores[$docId])) {
                continue;
            }

            if (
                $this->shouldBoostExactTitleTermSet($tokens, $isTypeaheadQuery)
                && $this->containsExactTitleTermSet($docId, $tokens, $numericConstrained)
            ) {
                $docScores[$docId] *= $this->exactMatchBoostFactor * $this->titleBoostFactor;
                $docScores[$docId] += $this->exactMatchBoostFactor * $this->titleBoostFactor * max(1, $totalDocs) * 10;
                continue;
            }

            if (count($tokens) > 1) {
                if (
                    $isTypeaheadQuery
                    ? $this->containsTypeaheadPhrase($docId, $tokens)
                    : $this->containsExactPhrase($docId, $searchQuery)
                ) {
                    $docScores[$docId] *= $this->exactMatchBoostFactor;
                }

                if ($this->containsTightTitleMatch($docId, $tokens, $isTypeaheadQuery, $numericConstrained)) {
                    $docScores[$docId] *= $this->exactMatchBoostFactor * $this->titleBoostFactor;
                    $docScores[$docId] += $this->exactMatchBoostFactor * $this->titleBoostFactor * max(1, $totalDocs);
                }
            }
        }

        foreach ($resolved['aliasDocs'] ?? [] as $docId => $value) {
            $promotions['aliases'][$docId] = true;
            if (!isset($docScores[$docId])) {
                $docScores[$docId] = 0.0;
            }
            if (isset($resolved['newAliasDocs'][$docId])) {
                $promotions['newAliases'][$docId] = true;
            }
        }
        foreach ($resolved['numericTitleDocs'] ?? [] as $docId => $value) {
            $promotions['numeric'][$docId] = true;
        }

        return array_intersect_key($docScores, array_flip($validDocs));
    }

    protected function promoteDocumentScores(array $scores, array $promoted, array $newDocuments = []): array
    {
        $promoted = array_intersect_key($promoted, $scores);
        if ($promoted === []) {
            return $scores;
        }
        arsort($scores);
        $existing = array_keys(array_diff_key(array_intersect_key($scores, $promoted), $newDocuments));
        $added = array_keys(array_intersect_key($newDocuments, $promoted));
        usort($added, static function(string $left, string $right): int {
            [$leftSite, $leftId] = array_map('intval', explode(':', $left, 2));
            [$rightSite, $rightId] = array_map('intval', explode(':', $right, 2));

            return [$leftSite, $leftId] <=> [$rightSite, $rightId];
        });
        $order = [...$existing, ...$added];
        $maximum = max($scores);
        $scale = max(1.0, abs($maximum));
        if (!is_finite($scale) || $scale > PHP_FLOAT_MAX / 4) {
            throw new \RuntimeException('Search scores cannot represent a finite promotion tier.');
        }
        $step = $scale / (count($order) + 1);
        $previous = INF;
        foreach ($order as $index => $docId) {
            $score = $maximum + $scale + $step * (count($order) - $index);
            if (!is_finite($score) || $score <= $maximum || $score >= $previous) {
                throw new \RuntimeException('Search promotion scores must be finite and strictly ordered.');
            }
            $scores[$docId] = $score;
            $previous = $score;
        }
        arsort($scores);

        return $scores;
    }

    /**
     * Decide whether the final query token should be treated as a prefix.
     *
     * Completed words are normal terms and the final word is a prefix, matching
     * the control panel element index. A trailing space means the last word is
     * finished and must match exactly.
     *
     * @param string $searchQuery The raw search query
     * @param array $tokens Tokenized search terms
     * @return bool Whether the query should use final-token prefix matching
     */
    protected function isSearchAsYouTypeQuery(string $searchQuery, array $tokens): bool
    {
        if (empty($tokens) || preg_match('/\s$/u', $searchQuery) === 1) {
            return false;
        }

        $request = Craft::$app->getRequest();

        return ($request instanceof \craft\web\Request && $request->getIsCpRequest()) || $this->siteSearchAsYouType;
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

        $allowedDocIds = $this->filterDocIdsByElementQuery(array_keys($scores), $elementQuery);

        return array_intersect_key($scores, $allowedDocIds);
    }

    /**
     * Resolve which of the given internal doc IDs actually satisfy the original element
     * query's criteria (status, section, element type, etc. — everything but search/paging).
     *
     * Demotion and degradation decisions (resolveValidDocsForGroups()) must run against
     * criteria-scoped doc sets. Otherwise an unscoped cross-type match (e.g. a non-product
     * entry that happens to contain both search terms) can make a group's intersection look
     * non-empty, suppressing degradation, only for the criteria filter to strip that match
     * out afterward and leave zero results.
     *
     * @param list<string> $docIds Internal doc IDs (siteId:elementId)
     * @param ElementQuery $elementQuery The original element query
     * @return array<string, true> Allowed doc IDs, keyed for fast lookup
     */
    protected function filterDocIdsByElementQuery(array $docIds, ElementQuery $elementQuery): array
    {
        if (empty($docIds)) {
            return [];
        }

        $idsBySite = [];
        foreach ($docIds as $docId) {
            [$siteId, $elementId] = explode(':', (string)$docId, 2);
            $idsBySite[(int)$siteId][] = (int)$elementId;
        }
        $allowed = [];
        foreach ($idsBySite as $siteId => $elementIds) {
            $criteriaQuery = clone $elementQuery;
            $criteriaQuery
                ->siteId($siteId)
                ->search(null)
                ->offset(null)
                ->limit(null)
                ->orderBy([]);
            $criteriaQuery->andWhere(['elements.id' => array_values(array_unique($elementIds))]);
            try {
                $allowedElementIds = array_fill_keys(array_map('intval', $criteriaQuery->ids()), true);
            } catch (QueryAbortedException) {
                continue;
            }
            foreach ($elementIds as $elementId) {
                if (isset($allowedElementIds[$elementId])) {
                    $allowed["$siteId:$elementId"] = true;
                }
            }
        }

        return $allowed;
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
     * Resolve Craft field-handle semantics for indexing calls.
     *
     * @return list<string> Empty array means attributes-only update.
     */
    protected function resolveFieldHandlesForIndexing(ElementInterface $element, ?array $fieldHandles): array
    {
        if ($fieldHandles !== null && $fieldHandles === []) {
            return [];
        }

        $searchable = $this->getSearchableFieldHandles($element);

        return $fieldHandles === null ? $searchable : array_values(array_intersect($fieldHandles, $searchable));
    }

    protected function getIndexableAttributes(ElementInterface $element): array
    {
        return array_values(array_diff(ElementHelper::searchableAttributes($element), ['enabled', 'enabledForSite']));
    }

    /**
     * Normalize keywords using Craft's helper and fire beforeIndexKeywords.
     */
    protected function normalizeIndexKeywords(
        ElementInterface $element,
        string $keywords,
        ?string $attribute = null,
        ?int $fieldId = null,
    ): string {
        if ($attribute !== null) {
            $attribute = strtolower($attribute);
        }

        $language = 'en';
        try {
            $language = $element->getSite()->language;
        } catch (\Throwable) {
            // ponytail: test fixtures may not have persisted sites
        }

        $keywords = SearchHelper::normalizeKeywords(NumericTerm::normalizeMeasurements($keywords), [], true, $language);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_INDEX_KEYWORDS)) {
            $event = new IndexKeywordsEvent([
                'element' => $element,
                'attribute' => $attribute,
                'fieldId' => $fieldId,
                'keywords' => $keywords,
            ]);
            $this->trigger(self::EVENT_BEFORE_INDEX_KEYWORDS, $event);

            if (!$event->isValid) {
                return '';
            }

            $keywords = $event->keywords;
        }

        return $keywords;
    }

    /**
     * @param array<string, list<string>> $termSources
     */
    protected function storeDocumentTermSources(int $siteId, int $elementId, array $termSources): void
    {
    }

    /**
     * @return array<string, list<string>>
     */
    protected function getDocumentTermSources(int $siteId, int $elementId): array
    {
        $state = $this->readDocumentSourceState($siteId, $elementId);

        return $state !== null && ($state['version'] ?? null) === 2 ? $state['sources'] : ($state ?? []);
    }

    protected function deleteDocumentTermSources(int $siteId, int $elementId): void
    {
    }

    protected function deleteOrphanedIndexesFromAdapter(): void
    {
    }

    /**
     * @return list<int>
     */
    protected function resolveQuerySiteIds(ElementQuery $elementQuery): array
    {
        $siteId = $elementQuery->siteId;

        if ($siteId === null || $siteId === '*') {
            return array_map(
                static fn($site): int => (int)$site->id,
                Craft::$app->getSites()->getAllSites()
            );
        }

        if (is_array($siteId)) {
            return array_values(array_map(static fn($id): int => (int)$id, $siteId));
        }

        return [(int)$siteId];
    }

    /**
     * @param array{andGroups: list<array{terms: list<array<string, mixed>>}>, excludeTerms: list<array<string, mixed>>, rawQuery: string} $parsed
     * @return list<string>
     */
    protected function flattenParsedTerms(array $parsed): array
    {
        $tokens = [];
        foreach ($parsed['andGroups'] as $group) {
            foreach ($group['terms'] as $termSpec) {
                $searchTokens = $this->getSearchTokens((string)$termSpec['term'], false);
                array_push($tokens, ...$searchTokens);
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Expand single-term AND groups whose term tokenizes into multiple words.
     *
     * A query term like "l-carnitine" tokenizes into ["l", "carnitine"]. Left as one AND
     * group it would otherwise only search its first token, silently discarding the rest.
     * Splitting it into one AND group per token requires every token to match instead.
     * Exact terms and OR groups (multiple term specs) are left untouched.
     *
     * @param array{andGroups: list<array{terms: list<array<string, mixed>>}>, excludeTerms: list<array<string, mixed>>, rawQuery: string} $parsed
     * @return array{andGroups: list<array{terms: list<array<string, mixed>>}>, excludeTerms: list<array<string, mixed>>, rawQuery: string}
     */
    protected function expandMultiTokenTerms(array $parsed): array
    {
        $andGroups = [];

        foreach ($parsed['andGroups'] as $group) {
            if (count($group['terms']) !== 1) {
                $andGroups[] = $group;
                continue;
            }

            $spec = $group['terms'][0];
            if (!empty($spec['exact'])) {
                $andGroups[] = $group;
                continue;
            }

            $tokens = $this->getSearchTokens((string)$spec['term'], false);
            if (count($tokens) <= 1) {
                $andGroups[] = $group;
                continue;
            }

            foreach ($tokens as $token) {
                $andGroups[] = ['terms' => [array_merge($spec, ['term' => $token])]];
            }
        }

        return [
            'andGroups' => $andGroups,
            'excludeTerms' => $parsed['excludeTerms'],
            'rawQuery' => $parsed['rawQuery'],
        ];
    }

    /**
     * Drop AND groups made up entirely of stop words, so a common word sitting between
     * content words no longer forces a required-but-unindexed AND term that zeroes the
     * whole query (getSearchTokens() falls back to the stop word itself per-group so
     * title-only queries like "Why" still work; applied to every group, that fallback
     * turns each stop word in a multi-word query into a mandatory match against content
     * where stop words are never indexed).
     *
     * Preserves title-only stop-word searches (all groups stop-only). The final group of a
     * typeahead query is never dropped since it is the in-progress prefix; consequently it is
     * also excluded from the "are there any content-bearing groups" check, so a completed stop
     * word that is the only OTHER completed term (e.g. "your" in "your p") is kept too, matching
     * getSearchTokens()'s existing "keep the sole completed stop word" typeahead behaviour.
     *
     * @param array{andGroups: list<array{terms: list<array<string, mixed>>}>, excludeTerms: list<array<string, mixed>>, rawQuery: string} $parsed
     * @return array{andGroups: list<array{terms: list<array<string, mixed>>}>, excludeTerms: list<array<string, mixed>>, rawQuery: string}
     */
    protected function filterStopWordGroups(array $parsed): array
    {
        $andGroups = $parsed['andGroups'];
        if (empty($andGroups)) {
            return $parsed;
        }

        $isTypeaheadQuery = $this->isSearchAsYouTypeQuery(
            $parsed['rawQuery'],
            $this->tokenize($parsed['rawQuery'])
        );
        $lastGroupIndex = array_key_last($andGroups);

        $stopOnly = [];
        foreach ($andGroups as $groupIndex => $group) {
            $stopOnly[$groupIndex] = $this->isStopWordOnlyGroup($group);
        }

        // Groups eligible to be dropped: everything except a typeahead query's final
        // (in-progress prefix) group, which is never dropped regardless of classification.
        $droppable = $stopOnly;
        if ($isTypeaheadQuery) {
            unset($droppable[$lastGroupIndex]);
        }

        if (empty($droppable) || !in_array(false, $droppable, true)) {
            // No droppable group is content-bearing: preserve everything (e.g. a title-only
            // search like "Why", or "your p" where "your" is the only completed term).
            return $parsed;
        }

        $filtered = [];
        foreach ($andGroups as $groupIndex => $group) {
            if ($droppable[$groupIndex] ?? false) {
                continue;
            }
            $filtered[] = $group;
        }

        return [
            'andGroups' => array_values($filtered),
            'excludeTerms' => $parsed['excludeTerms'],
            'rawQuery' => $parsed['rawQuery'],
        ];
    }

    /**
     * A group is stop-word-only when every term spec in it is non-exact and every token of
     * its term is a stop word. An OR group with any content-bearing alternative, an exact
     * spec, or a numeric token is never stop-only.
     *
     * @param array{terms: list<array<string, mixed>>} $group
     */
    protected function isStopWordOnlyGroup(array $group): bool
    {
        foreach ($group['terms'] as $spec) {
            if (!empty($spec['exact'])) {
                return false;
            }

            foreach ($this->tokenize((string)$spec['term']) as $token) {
                if (!in_array($token, $this->stopWords, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether any term spec in a group is an exact (quoted) match.
     *
     * @param array{terms: list<array<string, mixed>>} $group
     */
    protected function containsExactSpec(array $group): bool
    {
        foreach ($group['terms'] as $spec) {
            if (!empty($spec['exact'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the valid document set for a parsed query's AND groups, used by both the
     * scored and filter-only search paths so their required/optional semantics cannot drift.
     *
     * A strict intersection of every AND group turns any single common term into a hard gate:
     * one group with poor selectivity, or a zero-match group, zeroes the whole query. This
     * demotes over-broad or empty demotable groups to optional (scoring-only, non-constraining)
     * so the query degrades gracefully instead of returning nothing. Body-only matches (no
     * title hits) demote before title-matching groups so a rare SEO spelling cannot displace
     * the term that actually names the product.
     *
     * @param array<int, array<string, bool>> $groupDocSets docIds keyed true, per group index
     * @param array<int, array{demotable: bool, titleHits?: int}> $groupMeta per group index
     * @param int $totalDocs Total indexed document count, for the proactive demotion ratio
     * @return list<string> Valid document IDs
     */
    protected function resolveValidDocsForGroups(array $groupDocSets, array $groupMeta, int $totalDocs): array
    {
        if (empty($groupDocSets)) {
            return [];
        }

        $required = [];
        foreach ($groupDocSets as $groupIndex => $docSet) {
            $demotable = $groupMeta[$groupIndex]['demotable'] ?? true;
            if (!$demotable && empty($docSet)) {
                // An explicit exact/prefix miss on a non-demotable group is a legitimate zero.
                return [];
            }

            $required[$groupIndex] = $docSet;
        }

        $optional = [];

        // Proactive demotion: an over-broad demotable group stops being a hard requirement.
        if ($this->commonTermDemotionThreshold > 0 && $totalDocs >= 50) {
            $candidates = [];
            foreach ($required as $groupIndex => $docSet) {
                $demotable = $groupMeta[$groupIndex]['demotable'] ?? true;
                if ($demotable && (count($docSet) / $totalDocs) > $this->commonTermDemotionThreshold) {
                    $candidates[$groupIndex] = count($docSet);
                }
            }

            if (count($candidates) === count($required)) {
                // Demoting every candidate would leave zero required groups: keep the single
                // most-selective (lowest match count) group required.
                asort($candidates);
                array_shift($candidates);
            }

            foreach (array_keys($candidates) as $groupIndex) {
                $optional[$groupIndex] = $required[$groupIndex];
                unset($required[$groupIndex]);
            }
        }

        $intersection = $this->intersectDocSets($required);

        // Degradation loop: while the intersection is empty and more than one required group
        // remains, demote the required demotable group that contributes least — zero-match
        // groups first (they annihilate the intersection outright), then body-only groups
        // (no title hits) before title-matching groups, then the largest match count —
        // and re-intersect. Stops once the intersection is non-empty or only one required
        // group remains.
        while (empty($intersection) && count($required) > 1) {
            $demotableIndexes = array_values(array_filter(
                array_keys($required),
                fn(int $groupIndex): bool => $groupMeta[$groupIndex]['demotable'] ?? true
            ));

            if (empty($demotableIndexes)) {
                return [];
            }

            usort($demotableIndexes, function(int $a, int $b) use ($required, $groupMeta): int {
                $countA = count($required[$a]);
                $countB = count($required[$b]);
                $zeroA = $countA === 0;
                $zeroB = $countB === 0;

                if ($zeroA !== $zeroB) {
                    return $zeroA ? -1 : 1;
                }

                $titleA = ($groupMeta[$a]['titleHits'] ?? 0) > 0;
                $titleB = ($groupMeta[$b]['titleHits'] ?? 0) > 0;
                if ($titleA !== $titleB) {
                    return $titleA ? 1 : -1;
                }

                return $countB <=> $countA;
            });

            $toDemote = $demotableIndexes[0];
            $optional[$toDemote] = $required[$toDemote];
            unset($required[$toDemote]);

            $intersection = $this->intersectDocSets($required);
        }

        if (empty($intersection) && count($required) === 1) {
            return array_keys(reset($required));
        }

        return $intersection;
    }

    /**
     * Intersect a set of per-group document maps, keyed docId => true.
     *
     * @param array<int, array<string, bool>> $groupDocSets
     * @return list<string>
     */
    protected function intersectDocSets(array $groupDocSets): array
    {
        $intersection = null;
        foreach ($groupDocSets as $docSet) {
            $docIds = array_keys($docSet);
            $intersection = $intersection === null ? $docIds : array_values(array_intersect($intersection, $docIds));

            if (empty($intersection)) {
                return [];
            }
        }

        return $intersection ?? [];
    }

    /**
     * @param array<int, array<string, bool>> $groupDocSets
     * @param array<int, array<string, array<string, mixed>>> $groupTermData
     * @param array<int, array{demotable: bool, titleHits?: int}> $groupMeta
     * @return array<int, array{demotable: bool, titleHits: int}>
     */
    protected function withGroupTitleHits(array $groupDocSets, array $groupTermData, array $groupMeta): array
    {
        $docIds = [];
        foreach ($groupDocSets as $docs) {
            foreach (array_keys($docs) as $docId) {
                $docIds[] = (string)$docId;
            }
        }
        $this->warmTitleTerms($docIds);

        foreach ($groupDocSets as $groupIndex => $docs) {
            $groupMeta[$groupIndex]['titleHits'] = $this->countTitleHitsForGroup(
                $docs,
                $groupTermData[$groupIndex] ?? []
            );
        }

        return $groupMeta;
    }

    /**
     * @param array<string, bool> $docSet
     * @param array<string, array<string, mixed>> $termDataByDoc
     */
    protected function countTitleHitsForGroup(array $docSet, array $termDataByDoc): int
    {
        $hits = 0;
        foreach ($docSet as $docId => $_) {
            $term = $termDataByDoc[(string)$docId]['actualTerm'] ?? $termDataByDoc[$docId]['actualTerm'] ?? null;
            if (is_string($term) && $term !== '' && $this->isTermInTitle($term, (string)$docId)) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * @param array<string, float> $filteredScores
     * @return array<string, float>
     */
    protected function formatScoresForSearchEvent(array $filteredScores): array
    {
        $results = [];
        foreach ($filteredScores as $docId => $score) {
            [$docSiteId, $elementId] = explode(':', (string)$docId);
            $results["$elementId-$docSiteId"] = $score;
        }

        return $results;
    }

    /**
     * @param array{andGroups: list<array{terms: list<array<string, mixed>>}>, excludeTerms: list<array<string, mixed>>, rawQuery: string} $parsed
     * @return list<string>
     */
    protected function findMatchingDocIdsForParsedQuery(
        array $parsed,
        int $siteId,
        ElementQuery $elementQuery,
        bool $scored,
    ): array {
        if ($scored) {
            return array_keys($this->searchElementsForSite($elementQuery, $siteId, $parsed, $parsed['rawQuery']));
        }

        return $this->resolveMatchesForSite($elementQuery, $siteId, $parsed)['validDocs'] ?? [];
    }

    protected function resolveMatchesForSite(ElementQuery $elementQuery, int $siteId, array $parsed): array
    {
        $resolved = $this->resolveOriginalMatchesForSite($elementQuery, $siteId, $parsed);
        $aliases = $this->findTitleAliasDocuments($elementQuery, $siteId, $parsed);
        if ($aliases === []) {
            return $resolved;
        }
        if ($resolved === []) {
            $resolved = ['validDocs' => [], 'groupTermData' => [], 'isTypeaheadQuery' => $this->isSearchAsYouTypeQuery($parsed['rawQuery'], $this->tokenize($parsed['rawQuery']))];
        }
        $resolved['newAliasDocs'] = array_diff_key($aliases, array_fill_keys($resolved['validDocs'], true));
        $resolved['validDocs'] = array_values(array_unique([...$resolved['validDocs'], ...array_keys($aliases)]));
        $resolved['aliasDocs'] = $aliases;

        return $resolved;
    }

    protected function findTitleAliasDocuments(ElementQuery $elementQuery, int $siteId, array $parsed): array
    {
        if ($this->titleAliases === [] || $parsed['excludeTerms'] !== []) {
            return [];
        }
        foreach ($parsed['andGroups'] as $group) {
            if (count($group['terms']) !== 1) {
                return [];
            }
            $spec = $group['terms'][0];
            if (!empty($spec['exact']) || !empty($spec['phrase']) || !empty($spec['attribute']) || !empty($spec['subLeft'])) {
                return [];
            }
        }
        $input = TitleAliases::normalizeInput($parsed['rawQuery']);
        $tokens = $input === null ? null : ($this->titleAliases[$input] ?? null);
        if ($tokens === null) {
            return [];
        }
        $this->warmTermDocuments($tokens);
        $matches = null;
        foreach ($tokens as $term) {
            $documents = $this->filterDocumentsBySite($this->termDocumentsCached($term), $siteId);
            $matches = $matches === null ? $documents : array_intersect_key($matches, $documents);
            if ($matches === []) {
                return [];
            }
        }
        $this->warmTitleTerms(array_keys($matches));
        $titleMatches = [];
        foreach ($matches as $docId => $frequency) {
            $title = $this->titleTermsCached($docId);
            if (array_diff($tokens, array_map('strval', array_keys($title))) === []) {
                $titleMatches[] = $docId;
            }
        }

        return $this->filterDocIdsByElementQuery($titleMatches, $elementQuery);
    }

    protected function resolveOriginalMatchesForSite(ElementQuery $elementQuery, int $siteId, array $parsed): array
    {
        $searchQuery = $parsed['rawQuery'];
        $rawTokens = $this->tokenize($searchQuery);
        $isTypeaheadQuery = $this->isSearchAsYouTypeQuery($searchQuery, $rawTokens);

        if (empty($parsed['andGroups'])) {
            return [];
        }

        $totalDocs = $this->getSearchStatistics()['totalDocs'];

        $groupMatches = [];
        $groupMeta = [];
        $allDocuments = [];
        $groupTermData = [];
        $numericGroups = [];
        $numericTitleHits = [];
        $numericConstrained = false;
        $lastGroupIndex = array_key_last($parsed['andGroups']);

        foreach ($parsed['andGroups'] as $groupIndex => $group) {
            $groupMatches[$groupIndex] = [];
            $groupTermData[$groupIndex] = [];
            $numericAlternatives = [];

            foreach ($group['terms'] as $termIndex => $termSpec) {
                $finalTerm = $isTypeaheadQuery && $groupIndex === $lastGroupIndex && $termIndex === array_key_last($group['terms']);
                $numericAlternatives[] = ($termSpec['numericEligible'] ?? true) && NumericTerm::classify((string)$termSpec['term'], $finalTerm && empty($termSpec['exact'])) !== null;
                $termMatches = $this->findTermMatchesForSpec(
                    $termSpec,
                    $siteId,
                    $isTypeaheadQuery,
                    $isTypeaheadQuery
                        && $groupIndex === $lastGroupIndex
                        && $termIndex === array_key_last($group['terms']),
                    $isTypeaheadQuery ? $this->collectCompletedTerms($parsed, $groupIndex, $termIndex) : [],
                    $groupMatches,
                    $groupIndex,
                    collectFieldAlternatives: $elementQuery->customFields !== null,
                    fieldScope: $elementQuery,
                );

                foreach ($termMatches as $docId => $matchData) {
                    $groupMatches[$groupIndex][$docId] = true;
                    $allDocuments[$docId] = true;
                    if ($matchData['numericTitle'] ?? false) {
                        $numericTitleHits[$groupIndex][$docId] = true;
                    }
                    if ($elementQuery->customFields !== null) {
                        $groupTermData[$groupIndex][$docId]['candidates'][] = $matchData;
                    } elseif (
                        !isset($groupTermData[$groupIndex][$docId])
                        || ($matchData['confidence'] ?? 0) > ($groupTermData[$groupIndex][$docId]['confidence'] ?? 0)
                    ) {
                        $groupTermData[$groupIndex][$docId] = $matchData;
                    }
                }
            }

            $groupMeta[$groupIndex] = [
                'demotable' => !in_array(true, $numericAlternatives, true) && !$this->containsExactSpec($group) && !($isTypeaheadQuery && $groupIndex === $lastGroupIndex),
            ];
            $numericConstrained = $numericConstrained || in_array(true, $numericAlternatives, true);
            if ($numericAlternatives !== [] && !in_array(false, $numericAlternatives, true)) {
                $numericGroups[] = $groupIndex;
            }
        }

        // Scope every group's doc set to the original query's element criteria BEFORE
        // demotion/degradation runs, so those decisions can't be fooled by a cross-type
        // match that the criteria filter would strip out later anyway (see
        // filterDocIdsByElementQuery()'s docblock). One criteria query for the whole union.
        [$groupMatches, $groupTermData] = $this->resolveFieldScopedMatches($groupMatches, $groupTermData, $elementQuery);
        $allowedDocIds = $this->filterDocIdsByElementQuery(array_keys($allDocuments), $elementQuery);
        $scopedGroupMatches = [];
        foreach ($groupMatches as $groupIndex => $docs) {
            $scopedGroupMatches[$groupIndex] = array_intersect_key($docs, $allowedDocIds);
        }

        $groupMeta = $this->withGroupTitleHits($scopedGroupMatches, $groupTermData, $groupMeta);
        $validDocs = $this->resolveValidDocsForGroups($scopedGroupMatches, $groupMeta, $totalDocs);
        if (empty($validDocs)) {
            return [];
        }

        foreach ($parsed['excludeTerms'] as $excludeSpec) {
            $excludeMatches = $this->resolveQueryScopedTermMatches($this->findTermMatchesForSpec($excludeSpec, $siteId, false, false, [], [], 0, collectFieldAlternatives: $elementQuery->customFields !== null, fieldScope: $elementQuery), $elementQuery);
            $validDocs = array_values(array_diff($validDocs, array_keys($excludeMatches)));
            if (empty($validDocs)) {
                return [];
            }
        }

        $numericTitleDocs = [];
        if ($numericGroups !== []) {
            $numericTitleDocs = array_fill_keys($validDocs, true);
            foreach ($numericGroups as $groupIndex) {
                $numericTitleDocs = array_intersect_key($numericTitleDocs, $numericTitleHits[$groupIndex] ?? []);
            }
        }

        return compact('validDocs', 'groupTermData', 'isTypeaheadQuery', 'numericTitleDocs', 'numericConstrained');
    }

    /**
     * @param array<string, mixed> $termSpec
     * @param array<int, array<string, bool>> $groupMatches
     * @param bool $allowTokenExpansion Whether a multi-word term should be resolved token-by-token.
     *        Internal recursive calls pass false to guard against re-expanding an already-expanded token.
     * @return array<string, array<string, mixed>>
     */
    protected function findTermMatchesForSpec(
        array $termSpec,
        int $siteId,
        bool $isTypeaheadQuery,
        bool $isTypeaheadTerm,
        array $completedTerms,
        array $groupMatches,
        int $groupIndex,
        bool $allowTokenExpansion = true,
        bool $collectFieldAlternatives = false,
        ?ElementQuery $fieldScope = null,
    ): array {
        $term = (string)$termSpec['term'];
        $tokens = $this->getSearchTokens($term, $isTypeaheadTerm);
        if (empty($tokens)) {
            return [];
        }

        // OR-group members, exclude terms, and typeahead specs don't go through
        // expandMultiTokenTerms(), so a multi-word term (e.g. "l-carnitine") can still
        // reach here as a single spec. Resolve each token separately and require every
        // token to match, instead of silently truncating to the first token.
        if ($allowTokenExpansion && count($tokens) > 1) {
            $lastIndex = array_key_last($tokens);
            $matchingDocIds = null;
            $firstTokenMatches = [];

            foreach ($tokens as $index => $token) {
                $tokenMatches = $this->findTermMatchesForSpec(
                    array_merge($termSpec, ['term' => $token]),
                    $siteId,
                    $isTypeaheadQuery,
                    $isTypeaheadTerm && $index === $lastIndex,
                    $completedTerms,
                    $groupMatches,
                    $groupIndex,
                    false,
                    $collectFieldAlternatives,
                    $fieldScope,
                );

                if ($fieldScope !== null) {
                    $tokenMatches = $this->resolveQueryScopedTermMatches($tokenMatches, $fieldScope);
                }

                if ($index === 0) {
                    $firstTokenMatches = $tokenMatches;
                }

                $matchingDocIds = $matchingDocIds === null
                    ? array_keys($tokenMatches)
                    : array_values(array_intersect($matchingDocIds, array_keys($tokenMatches)));

                if (empty($matchingDocIds)) {
                    return [];
                }
            }

            return array_intersect_key($firstTokenMatches, array_flip($matchingDocIds));
        }

        $term = $tokens[0];
        $numeric = ($termSpec['numericEligible'] ?? true) ? NumericTerm::classify($term, $isTypeaheadTerm && empty($termSpec['exact'])) : null;
        if ($numeric !== null) {
            $priorDocIds = $groupIndex > 0 ? $this->getDocsMatchingPreviousGroups($groupMatches, $groupIndex) : null;

            return $this->findNumericTermMatches($numeric, $termSpec, $siteId, $isTypeaheadTerm, $fieldScope, $priorDocIds);
        }
        $matches = [];
        $termDocs = $this->filterDocumentsBySite($this->termDocumentsCached($term), $siteId);
        if (!empty($termSpec['attribute'])) {
            $this->warmDocumentSourceStates(array_keys($termDocs));
        }

        if (!empty($termDocs)) {
            $docFreq = count($termDocs);
            foreach ($termDocs as $docId => $freq) {
                if (!$this->termMatchesAttributeScope($docId, $term, $termSpec)) {
                    continue;
                }
                $matches[$docId] = [
                    'freq' => $freq,
                    'docFreq' => $docFreq,
                    'actualTerm' => $term,
                    'attribute' => $termSpec['attribute'] ?? null,
                    'confidence' => 1.0,
                ];
            }
        }

        $exactMatches = $fieldScope !== null ? $this->resolveQueryScopedTermMatches($matches, $fieldScope) : $matches;
        if (!empty($termSpec['exact'])) {
            return $exactMatches;
        }

        if ($isTypeaheadTerm) {
            $priorDocIds = $groupIndex > 0 ? $this->getDocsMatchingPreviousGroups($groupMatches, $groupIndex) : null;
            $typeaheadScores = $this->findTypeaheadMatchScores($term, $siteId, $priorDocIds, $completedTerms);
            unset($typeaheadScores[$term]);

            $this->warmTermDocuments(array_keys($typeaheadScores));
            $typeaheadDocs = [];
            $typeaheadDocIds = [];
            foreach (array_keys($typeaheadScores) as $prefixTerm) {
                $docs = $this->filterDocumentsBySite($this->termDocumentsCached((string)$prefixTerm), $siteId);
                $typeaheadDocs[$prefixTerm] = $docs;
                $typeaheadDocIds += $docs;
            }
            $this->warmTitleTerms(array_keys($typeaheadDocIds));
            if (!empty($termSpec['attribute'])) {
                $this->warmDocumentSourceStates(array_keys($typeaheadDocIds));
            }

            foreach ($typeaheadScores as $prefixTerm => $confidence) {
                $prefixTerm = (string)$prefixTerm;
                $docFreq = count($typeaheadDocs[$prefixTerm]);
                foreach ($typeaheadDocs[$prefixTerm] as $docId => $freq) {
                    if (!$this->termMatchesAttributeScope($docId, $prefixTerm, $termSpec)) {
                        continue;
                    }
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
                        'attribute' => $termSpec['attribute'] ?? null,
                        'confidence' => $docConfidence,
                    ];
                    $replace = !isset($matches[$docId]) || $this->shouldReplaceSearchTermMatch($docId, $matches[$docId], $matchData);
                    $this->rememberTermMatch($matches, $docId, $matchData, $replace, $collectFieldAlternatives);
                }
            }
        }

        if (!$this->shouldFindFuzzyMatches($term, $exactMatches !== [], count($exactMatches))) {
            return $matches;
        }

        $fuzzyScores = $this->findFuzzyMatchScores($term, siteId: $siteId);
        unset($fuzzyScores[$term]);
        $this->warmTermDocuments(array_keys($fuzzyScores));
        if (!empty($termSpec['attribute'])) {
            $sourceDocIds = [];
            foreach (array_keys($fuzzyScores) as $candidate) {
                $sourceDocIds += $this->filterDocumentsBySite($this->termDocumentsCached((string)$candidate), $siteId);
            }
            $this->warmDocumentSourceStates(array_keys($sourceDocIds));
        }

        foreach ($fuzzyScores as $fuzzy => $confidence) {
            $fuzzy = (string)$fuzzy;
            $fuzzyDocs = $this->filterDocumentsBySite($this->termDocumentsCached($fuzzy), $siteId);
            $docFreq = count($fuzzyDocs);
            foreach ($fuzzyDocs as $docId => $freq) {
                if ((!$collectFieldAlternatives && isset($matches[$docId])) || !$this->termMatchesAttributeScope($docId, $fuzzy, $termSpec)) {
                    continue;
                }
                $matchData = [
                    'freq' => $freq,
                    'docFreq' => $docFreq,
                    'actualTerm' => $fuzzy,
                    'attribute' => $termSpec['attribute'] ?? null,
                    'confidence' => $confidence,
                ];
                $this->rememberTermMatch($matches, $docId, $matchData, !isset($matches[$docId]), $collectFieldAlternatives);
            }
        }

        return $matches;
    }

    protected function findNumericTermMatches(array $numeric, array $spec, int $siteId, bool $inProgress, ?ElementQuery $fieldScope, ?array $priorDocIds): array
    {
        $exact = !empty($spec['exact']);
        $term = $numeric['digits'] . $numeric['unit'];
        $candidates = array_fill_keys(NumericTerm::directCandidates($numeric, $exact), 1.0);
        if ($inProgress && !$exact && $numeric['unit'] === '') {
            if ($priorDocIds === null) {
                $prefixTerms = $this->getTermsByPrefix($term, $siteId, $this->fuzzySearchMaxCandidates);
            } else {
                $this->warmDocumentTerms($priorDocIds);
                $prefixTerms = [];
                foreach ($priorDocIds as $docId) {
                    [$documentSite, $elementId] = array_map('intval', explode(':', $docId, 2));
                    if ($documentSite !== $siteId) {
                        continue;
                    }
                    foreach (array_keys($this->documentTermsCached($documentSite, $elementId)) as $candidate) {
                        $candidate = (string)$candidate;
                        if (NumericTerm::accepts($numeric, $candidate, true)) {
                            $prefixTerms[$candidate] = $this->calculateTypeaheadConfidence($term, $candidate);
                        }
                    }
                }
                arsort($prefixTerms);
                $prefixTerms = array_slice($prefixTerms, 0, max($this->fuzzySearchMaxCandidates, 1000), true);
            }
            foreach ($prefixTerms as $candidate => $confidence) {
                if (NumericTerm::accepts($numeric, (string)$candidate, true)) {
                    $candidates[$candidate] = (float)$confidence;
                }
            }
        }
        $this->warmTermDocuments(array_keys($candidates));
        $candidateDocs = [];
        $allDocuments = [];
        foreach ($candidates as $candidate => $confidence) {
            if (!NumericTerm::accepts($numeric, (string)$candidate, $inProgress && !$exact, $exact)) {
                continue;
            }
            $candidateDocs[$candidate] = $this->filterDocumentsBySite($this->termDocumentsCached((string)$candidate), $siteId);
            $allDocuments += $candidateDocs[$candidate];
        }
        $this->warmTitleTerms(array_keys($allDocuments));
        if (!empty($spec['attribute']) || $fieldScope?->customFields !== null) {
            $this->warmDocumentSourceStates(array_keys($allDocuments));
        }
        $matches = [];
        foreach ($candidateDocs as $candidate => $documents) {
            $candidate = (string)$candidate;
            foreach ($documents as $docId => $frequency) {
                $data = [
                    'freq' => $frequency,
                    'docFreq' => count($documents),
                    'actualTerm' => $candidate,
                    'attribute' => $spec['attribute'] ?? null,
                    'confidence' => (float)$candidates[$candidate],
                    'numericTitle' => empty($spec['attribute']) || $spec['attribute'] === 'title',
                ];
                if (!$this->termMatchesAttributeScope($docId, $candidate, $spec)
                    || ($fieldScope !== null && !$this->termMatchesQueryScope($docId, $data, $fieldScope))) {
                    continue;
                }
                $title = $data['numericTitle'] && $this->isTermInTitle($candidate, $docId);
                $data['numericTitle'] = $title || ($matches[$docId]['numericTitle'] ?? false);
                if (!isset($matches[$docId]) || $this->shouldReplaceSearchTermMatch($docId, $matches[$docId], $data)) {
                    $matches[$docId] = $data;
                } else {
                    $matches[$docId]['numericTitle'] = $data['numericTitle'];
                }
            }
        }

        return $matches;
    }

    /**
     * @param array<int, array<string, bool>> $groupMatches
     * @return list<string>|null
     */
    protected function getDocsMatchingPreviousGroups(array $groupMatches, int $groupIndex): ?array
    {
        $validDocs = null;
        for ($i = 0; $i < $groupIndex; $i++) {
            $validDocs = $validDocs === null
                ? array_keys($groupMatches[$i] ?? [])
                : array_intersect($validDocs, array_keys($groupMatches[$i] ?? []));
        }

        return $validDocs;
    }

    /**
     * @param array{andGroups: list<array{terms: list<array<string, mixed>>}>, excludeTerms: list<array<string, mixed>>, rawQuery: string} $parsed
     * @return list<string>
     */
    protected function collectCompletedTerms(array $parsed, int $groupIndex, int $termIndex): array
    {
        $terms = [];
        foreach ($parsed['andGroups'] as $currentGroupIndex => $group) {
            foreach ($group['terms'] as $currentTermIndex => $termSpec) {
                if ($currentGroupIndex === $groupIndex && $currentTermIndex === $termIndex) {
                    return $terms;
                }
                array_push($terms, ...(array)$this->getSearchTokens((string)$termSpec['term'], false));
            }
        }

        return $terms;
    }

    /**
     * @param array<string, mixed> $termSpec
     */
    protected function termMatchesAttributeScope(string $docId, string $term, array $termSpec): bool
    {
        if (empty($termSpec['attribute'])) {
            return true;
        }

        [$siteId, $elementId] = explode(':', $docId, 2);
        $sources = $this->getDocumentTermSources((int)$siteId, (int)$elementId);
        $origins = $sources[$term] ?? [];
        $attribute = (string)$termSpec['attribute'];

        foreach ($origins as $origin) {
            if ($origin === "attr:$attribute" || $origin === "field:$attribute") {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $matchData
     */
    protected function termMatchesQueryScope(string $docId, array $matchData, ElementQuery $elementQuery): bool
    {
        if ($elementQuery->customFields === null) {
            return true;
        }

        [$siteId, $elementId] = explode(':', $docId, 2);
        $sources = $this->getDocumentTermSources((int)$siteId, (int)$elementId);
        $term = (string)$matchData['actualTerm'];
        $origins = $sources[$term] ?? [];
        $attribute = $matchData['attribute'] ?? null;
        if ($attribute !== null) {
            $origins = array_values(array_filter(
                $origins,
                static fn(string $origin): bool => $origin === "attr:$attribute" || $origin === "field:$attribute"
            ));
        }
        if ($origins === []) {
            return $attribute === null && $this->isTermInTitle($term, $docId);
        }

        $allowed = array_map(
            static fn(FieldInterface $field): string => "field:$field->handle",
            $elementQuery->customFields
        );

        foreach ($origins as $origin) {
            if (str_starts_with($origin, 'attr:') || in_array($origin, $allowed, true)) {
                return true;
            }
        }

        return false;
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

        // N-gram similarity depends on stored n-grams matching the currently configured
        // ngramSizes. When settings changed since a term was indexed, stale n-grams can
        // return zero candidates even for an obvious prefix. Supplement with a direct
        // prefix lookup so long prefixes (e.g. "ashwagand" -> "ashwagandha") still resolve.
        if (mb_strlen($term, 'UTF-8') >= 4) {
            foreach ($this->getTermsByPrefix($term, $siteId, $this->fuzzySearchMaxCandidates) as $candidate => $confidence) {
                $candidate = (string)$candidate;
                if ($candidate === $term || isset($matches[$candidate])) {
                    continue;
                }

                $matches[$candidate] = $this->calculateFuzzyConfidence(
                    $term,
                    $candidate,
                    $this->calculateNgramSimilarity($searchNgrams, $this->generateNgrams($candidate))
                );
            }
        }

        $this->addSameLengthOneEditMatches($term, $siteId, $searchNgrams, $matches);

        // Limit candidates for performance and return by similarity score
        $matches = array_slice($matches, 0, $this->fuzzySearchMaxCandidates, true);

        return $matches;
    }

    /**
     * N-gram recall misses single-letter substitutions in short words.
     *
     * @param array<string, float> $matches
     */
    protected function addSameLengthOneEditMatches(
        string $term,
        int $siteId,
        array $searchNgrams,
        array &$matches,
    ): void {
        $termLength = mb_strlen($term, 'UTF-8');
        if ($termLength < 3) {
            return;
        }

        $chars = mb_str_split($term, 1, 'UTF-8');
        $candidates = [];
        foreach ($chars as $i => $char) {
            foreach (range('a', 'z') as $letter) {
                if ($letter === $char) {
                    continue;
                }
                $variant = $chars;
                $variant[$i] = $letter;
                $candidate = implode('', $variant);
                if (isset($matches[$candidate])) {
                    continue;
                }
                $candidates[$candidate] = true;
            }
        }

        $candidateTerms = array_keys($candidates);
        $this->warmTermDocuments($candidateTerms);

        foreach ($candidateTerms as $candidate) {
            if (empty($this->filterDocumentsBySite($this->termDocumentsCached($candidate), $siteId))) {
                continue;
            }
            $matches[$candidate] = $this->calculateFuzzyConfidence(
                $term,
                $candidate,
                $this->calculateNgramSimilarity($searchNgrams, $this->generateNgrams($candidate))
            );
        }
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
            $this->warmDocumentTerms($candidateDocIds);
            foreach ($candidateDocIds as $docId) {
                [$docSiteId, $elementId] = explode(':', (string)$docId, 2);
                if ((int)$docSiteId !== $siteId) {
                    continue;
                }

                foreach (array_keys($this->documentTermsCached($siteId, (int)$elementId)) as $term) {
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
            $this->warmDocumentTerms($candidateDocIds);
            foreach ($candidateDocIds as $docId) {
                [$docSiteId, $elementId] = explode(':', (string)$docId, 2);
                if ((int)$docSiteId !== $siteId) {
                    continue;
                }

                foreach (array_keys($this->documentTermsCached($siteId, (int)$elementId)) as $term) {
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
     * Three-letter exact terms stay exact. Four-letter and longer exact hits can still
     * be spelling variants that exist elsewhere in the index, so fuzzy supplements run.
     *
     * @param string $term The search term
     * @param bool $hasExactMatches Whether exact matches were found on the active site
     * @return bool Whether fuzzy matching should run
     */
    protected function shouldFindFuzzyMatches(string $term, bool $hasExactMatches, int $exactMatchCount = 0): bool
    {
        if ($exactMatchCount > 50) {
            return false;
        }

        $termLength = mb_strlen($term, 'UTF-8');

        return $termLength >= 3 && (!$hasExactMatches || $termLength >= 4);
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
        return SearchQueryParser::tokenize($text);
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
        if ($prefix === '' || $limit <= 0) {
            return [];
        }
        $matches = [];

        foreach ($this->getAllTerms() as $term) {
            $term = (string)$term;
            if (!str_starts_with($term, $prefix)) {
                continue;
            }

            if (empty($this->filterDocumentsBySite($this->termDocumentsCached($term), $siteId))) {
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

    protected function pendingPrefixDocuments(int $siteId): array
    {
        return array_filter($this->publicationState()['pending'], static fn(string $docId): bool => str_starts_with($docId, "$siteId:"), ARRAY_FILTER_USE_KEY);
    }

    protected function prefixDocumentIdentity(string $docId, int $siteId): array
    {
        if (preg_match('/\A([1-9][0-9]*):([1-9][0-9]*)\z/D', $docId, $parts) !== 1 || (int)$parts[1] !== $siteId) {
            throw new \RuntimeException('Invalid site prefix membership document identity.');
        }

        return [(int)$parts[1], (int)$parts[2]];
    }

    protected function normalizePrefixTerms(array $terms): array
    {
        $terms = array_values(array_unique(array_map('strval', $terms), SORT_STRING));
        sort($terms, SORT_STRING);

        return $terms;
    }

    protected function prefixByteSuccessor(string $prefix): ?string
    {
        for ($index = strlen($prefix) - 1; $index >= 0; $index--) {
            $byte = ord($prefix[$index]);
            if ($byte < 255) {
                return substr($prefix, 0, $index) . chr($byte + 1);
            }
        }

        return null;
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
        $titleTerms = $this->titleTermsCached($docId);
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
        $docTerms = $this->documentTermsCached((int)$siteId, (int)$elementId);

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

        $titleTerms = $this->titleTermsCached($docId);
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
    protected function containsTightTitleMatch(string $docId, array $tokens, bool $isTypeaheadQuery, bool $preserveNumbers = false): bool
    {
        if (empty($tokens)) {
            return false;
        }

        $titleTerms = $this->getComparableTitleTerms($docId, $this->containsStopWord($tokens), $preserveNumbers);
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
    protected function containsExactTitleTermSet(string $docId, array $tokens, bool $preserveNumbers = false): bool
    {
        $titleTerms = $this->getComparableTitleTerms($docId, preserveNumbers: $preserveNumbers);
        $queryTerms = [];

        foreach ($tokens as $token) {
            $token = (string)$token;
            if (!$preserveNumbers && preg_match('/^\d+$/', $token)) {
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
    protected function getComparableTitleTerms(string $docId, bool $preserveStopWords = false, bool $preserveNumbers = false): array
    {
        $terms = [];

        foreach (array_keys($this->titleTermsCached($docId)) as $term) {
            $term = (string)$term;
            if (!$preserveNumbers && preg_match('/^\d+$/', $term)) {
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
        $this->synchronizeSearchMemo();
        if ($this->memoSearchStats === null) {
            $totalDocs = max(1, $this->getTotalDocCount());
            $totalLength = max(1, $this->getTotalLength());
            $avgDocLength = $totalLength / $totalDocs;

            $this->memoSearchStats = [
                'totalDocs' => $totalDocs,
                'avgDocLength' => $avgDocLength,
            ];
        }

        return $this->memoSearchStats;
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

    /** @internal */
    public function removedSiteIds(): array
    {
        $current = array_fill_keys(array_map('intval', (new Query())->select('id')->from('{{%sites}}')->where(['dateDeleted' => null])->column()), true);
        $removed = [];
        foreach ($this->getIndexedDocumentIds() as $docId) {
            [$siteId] = $this->indexDocumentIdentity((string)$docId);
            if (!isset($current[$siteId])) {
                $removed[$siteId] = true;
            }
        }
        foreach ($this->publicationState()['pending'] as $docId => $marker) {
            [$siteId] = $this->indexDocumentIdentity((string)$docId);
            if (!isset($current[$siteId])) {
                $removed[$siteId] = true;
            }
        }
        foreach ((new Query())->select('siteId')->from(RebuildState::RUNS)->where(['indexIdentity' => $this->getIndexIdentity()])->column() as $siteId) {
            if (!isset($current[(int)$siteId])) {
                $removed[(int)$siteId] = true;
            }
        }
        return array_keys($removed);
    }

    /** @internal */
    public function removedSiteDocumentReferences(array $sites): iterable
    {
        foreach ($this->getIndexedDocumentIds() as $docId) {
            [$siteId, $elementId] = $this->indexDocumentIdentity((string)$docId);
            if (in_array($siteId, $sites, true)) {
                yield [$elementId, $siteId];
            }
        }
    }

    /** @internal */
    public function removedSitePendingReferences(array $sites): array
    {
        $references = [];
        foreach ($this->publicationState()['pending'] as $docId => $marker) {
            [$siteId, $elementId] = $this->indexDocumentIdentity((string)$docId);
            if (in_array($siteId, $sites, true)) {
                $references[] = [$elementId, $siteId];
                if (count($references) === 20) {
                    break;
                }
            }
        }
        return $references;
    }

    /** @internal */
    public function fenceRemovedSite(int $siteId): void
    {
        $this->withRemovedSiteGuard($siteId, function() use ($siteId): void {
            RebuildState::cancel($this->getIndexIdentity(), $siteId);
            $this->cancelDerivedRebuild($siteId);
        });
    }

    /** @internal */
    public function deleteRemovedSiteReferences(array $references): void
    {
        if (count($references) > 20) {
            throw new \RuntimeException('A removed-site cleanup batch exceeded its limit.');
        }
        foreach ($references as $reference) {
            if (!is_array($reference) || !array_is_list($reference) || count($reference) !== 2 || !is_int($reference[0]) || !is_int($reference[1]) || min($reference) <= 0) {
                throw new \RuntimeException('Invalid removed-site document reference.');
            }
            [$elementId, $siteId] = $reference;
            $this->withRemovedSiteGuard($siteId, function() use ($siteId, $elementId): void {
                if (!$this->deleteElementFromIndex($elementId, $siteId)) {
                    throw new \RuntimeException('Unable to remove search data belonging to a deleted site.');
                }
            });
        }
    }

    /** @internal */
    public function retireRemovedSiteStep(int $siteId): bool
    {
        $done = $this->withRemovedSiteGuard($siteId, function() use ($siteId): bool {
            if (!$this->retireDerivedSiteStep($siteId)) {
                return false;
            }
            $owner = RebuildState::owner($this->getIndexIdentity(), $siteId);
            if ($owner !== null) {
                Craft::$app->getDb()->transaction(function() use ($owner, $siteId): void {
                    Craft::$app->getDb()->createCommand()->delete(RebuildState::CHUNKS, ['runId' => $owner['runId']])->execute();
                    Craft::$app->getDb()->createCommand()->delete(RebuildState::RUNS, ['indexIdentity' => $this->getIndexIdentity(), 'siteId' => $siteId, 'runId' => $owner['runId']])->execute();
                });
            }
            return true;
        });
        return $done === null || $done;
    }

    protected function indexDocumentIdentity(string $docId): array
    {
        if (preg_match('/\A([1-9][0-9]*):([1-9][0-9]*)\z/D', $docId, $parts) !== 1) {
            throw new \RuntimeException('Invalid search document identity during site cleanup.');
        }
        return [(int)$parts[1], (int)$parts[2]];
    }

    protected function withRemovedSiteGuard(int $siteId, callable $callback): mixed
    {
        return RebuildState::withSiteGuard($this->getIndexIdentity(), $siteId, function() use ($siteId, $callback): mixed {
            if ((new Query())->from('{{%sites}}')->where(['id' => $siteId, 'dateDeleted' => null])->exists()) {
                return null;
            }
            $this->assertRebuildContext();
            return $callback();
        });
    }

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
            return RebuildState::withSiteGuard($this->getIndexIdentity(), $siteId, function() use ($siteId): bool {
                RebuildState::cancel($this->getIndexIdentity(), $siteId);
                $this->cancelDerivedRebuild($siteId);
                $this->clearSearchMemo();
                foreach ($this->getSiteDocuments($siteId) as $docId) {
                    [$documentSiteId, $elementId] = array_map('intval', explode(':', $docId, 2));
                    if (!$this->deleteElementFromIndex($elementId, $documentSiteId)) {
                        return false;
                    }
                }

                return true;
            });
        } catch (\Throwable $exception) {
            Craft::error('Unable to clear the search index: ' . $exception->getMessage(), __METHOD__);

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
            IndexableElements::assertFreshReadContext();
            $this->clearSearchMemo();
            $keep = array_fill_keys(array_map('intval', $activeElementIds), true);
            foreach ($this->getSiteDocuments($siteId) as $docId) {
                [$documentSiteId, $elementId] = array_map('intval', explode(':', $docId, 2));
                if (!isset($keep[$elementId])) {
                    $success = $this->withElementIndexLockById($elementId, $documentSiteId, function() use ($elementId, $documentSiteId): bool {
                        $type = Craft::$app->getElements()->getElementTypeById($elementId);

                        return $type === null || IndexableElements::siteIdForType($type, $documentSiteId) !== $documentSiteId
                            ? $this->publishDocumentDeletion($documentSiteId, $elementId)
                            : $this->indexPersistedElementUnlocked($type, $elementId, $documentSiteId, null);
                    });
                    if (!$success) {
                        return false;
                    }
                }
            }

            return true;
        } catch (\Throwable $exception) {
            Craft::error('Unable to prune the search index: ' . $exception->getMessage(), __METHOD__);

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
        $this->clearSearchMemo();

        return $this->withDocumentIndexLock($siteId, $elementId, fn(): bool => $this->publishDocumentDeletion($siteId, $elementId));
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
        $this->clearSearchMemo();
        foreach ($this->getAllTerms() as $term) {
            $this->withPublicationGuard(function() use ($term): void {
                if ($this->getTermDocuments((string)$term) === []) {
                    $this->removeTermFromIndex((string)$term);
                    foreach (Craft::$app->getSites()->getAllSiteIds(true) as $siteId) {
                        $this->removeTermNgrams((string)$term, $siteId);
                        $this->deleteIndexRecord("ngram:$siteId:$term");
                    }
                }
            });
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
     * Check whether a term's stored n-grams are current for the active ngramSizes setting.
     *
     * Existence alone (termHasNgrams()) isn't enough: if ngramSizes changes between
     * releases, previously stored n-grams stay in the index unchanged and silently mismatch
     * what generateNgrams() now produces, which degrades fuzzy-match Jaccard similarity.
     * Adapters that can cheaply read the stored n-gram count should override this to also
     * compare it against count($this->generateNgrams($term)), so a settings change causes
     * n-grams to regenerate on the next index write. The base implementation falls back to
     * plain existence checking.
     *
     * @param string $term The term to check
     * @param int $siteId The site ID
     * @return bool Whether the term's stored n-grams are current
     */
    protected function termNgramsCurrent(string $term, int $siteId): bool
    {
        return $this->termHasNgrams($term, $siteId);
    }

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
