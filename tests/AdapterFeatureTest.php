<?php

declare(strict_types=1);

namespace MadeByBrambleTest\BrambleSearch;

use Craft;
use craft\elements\Entry;
use craft\helpers\FileHelper;
use MadeByBramble\BrambleSearch\adapters\BaseSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\CraftCacheSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\FileSearchAdapter;
use PHPUnit\Framework\TestCase;

final class AdapterFeatureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Craft::setAlias('@bramble_search', dirname(__DIR__) . '/src');
    }

    public function testGeneratedNgramsArePhpLists(): void
    {
        $adapter = new TestableCraftCacheSearchAdapter();

        $ngrams = $adapter->publicGenerateNgrams('lavender');

        self::assertTrue(array_is_list($ngrams));
    }

    public function testCraftCacheAdapterFindsFuzzyTermsByNgramSimilarity(): void
    {
        $adapter = new TestableCraftCacheSearchAdapter();
        $adapter->setTestPrefix('bramble_search_test:' . bin2hex(random_bytes(4)) . ':');
        $adapter->addSearchTerm('lavender', 1, 100);

        $matches = $adapter->publicGetTermsByNgramSimilarity('lavendr', 1);

        self::assertArrayHasKey('lavender', $matches);
    }

    public function testFileAdapterFindsFuzzyTermsByNgramSimilarity(): void
    {
        $adapter = new TestableFileSearchAdapter();
        $baseDir = Craft::getAlias('@runtime') . '/bramble-search-test-' . bin2hex(random_bytes(4));
        $adapter->setBaseDir($baseDir);

        try {
            $adapter->addSearchTerm('lavender', 1, 100);

            $matches = $adapter->publicGetTermsByNgramSimilarity('lavendr', 1);

            self::assertArrayHasKey('lavender', $matches);
        } finally {
            FileHelper::removeDirectory($baseDir);
        }
    }

    public function testFuzzySearchUsesTheElementQuerySite(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addSearchTerm('orchid', 2, 200);

        $query = Entry::find()->siteId(2)->search('orchd');
        $matches = $adapter->searchElements($query);

        self::assertArrayHasKey('200-2', $matches);
    }

    public function testFuzzySearchFallsBackWhenExactMatchesOnlyExistOutsideTheElementQuerySite(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addSearchTerm('antibiotic', 1, 100);
        $adapter->addSearchTerm('antibioti', 2, 200);

        $query = Entry::find()->siteId(1)->search('antibioti');
        $matches = $adapter->searchElements($query);

        self::assertArrayHasKey('100-1', $matches);
    }

    public function testFuzzySearchFindsAntibioticOilTitleWhenExactTypoExistsOnSameSite(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Antibiotic Oil', 1, 100);
        $adapter->addSearchTerm('antibioti', 1, 200);

        $query = Entry::find()->siteId(1)->search('antibioti');
        $matches = $adapter->searchElements($query);

        self::assertArrayHasKey('100-1', $matches);
        self::assertArrayHasKey('200-1', $matches);
    }

    public function testFuzzySearchCoversCommonTypoShapes(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Antibiotic Oil', 1, 100);
        $adapter->addTitle('Lavender Extract', 1, 110);
        $adapter->addTitle('Ginger Root', 1, 120);
        $adapter->addTitle('Mineral Complex', 1, 130);
        $adapter->addTitle('Supplements Guide', 1, 140);

        $cases = [
            'antibioti' => '100-1',
            'antibotic' => '100-1',
            'lavendr' => '110-1',
            'lavendar' => '110-1',
            'gigner' => '120-1',
            'minerlas' => '130-1',
            'supplemnts' => '140-1',
        ];

        foreach ($cases as $queryTerm => $expectedDocId) {
            $matches = $adapter->searchElements(Entry::find()->siteId(1)->search($queryTerm));
            self::assertArrayHasKey($expectedDocId, $matches, "Expected $queryTerm to find $expectedDocId");
        }
    }

    public function testFuzzySearchSupportsTitleAndFieldPrefixes(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Antibiotic Oil', 1, 100);
        $adapter->addSearchTerm('supplements', 1, 110);
        $adapter->addSearchTerm('minerals', 1, 120);

        $cases = [
            'antibi' => '100-1',
            'supplem' => '110-1',
            'minera' => '120-1',
        ];

        foreach ($cases as $queryTerm => $expectedDocId) {
            $matches = $adapter->searchElements(Entry::find()->siteId(1)->search($queryTerm));
            self::assertArrayHasKey($expectedDocId, $matches, "Expected $queryTerm to find $expectedDocId");
        }
    }

    public function testFuzzySearchRejectsDistantSharedNgramCandidates(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Antibiotic Oil', 1, 100);
        $adapter->addTitle('Antibody Research', 1, 200);
        $adapter->addTitle('Caterpillar Study', 1, 300);

        $antibiotiMatches = $adapter->searchElements(Entry::find()->siteId(1)->search('antibioti'));
        $catMatches = $adapter->searchElements(Entry::find()->siteId(1)->search('cat'));

        self::assertArrayHasKey('100-1', $antibiotiMatches);
        self::assertArrayNotHasKey('200-1', $antibiotiMatches);
        self::assertArrayNotHasKey('300-1', $catMatches);
    }

    public function testExactShortTitleSearchDoesNotIncludeFuzzyLookalikes(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Why do you sell the supplements you sell?', 1, 100);
        $adapter->addTitle('Whey Protein Guide', 1, 200);

        $exactMatches = $adapter->searchElements(Entry::find()->siteId(1)->search('why'));
        $typoMatches = $adapter->searchElements(Entry::find()->siteId(1)->search('whi'));

        self::assertSame(['100-1'], array_keys($exactMatches));
        self::assertArrayHasKey('100-1', $typoMatches);
        self::assertArrayNotHasKey('200-1', $typoMatches);
    }

    public function testMultiTermFuzzySearchStillRequiresEverySearchTerm(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Antibiotic Oil', 1, 100);
        $adapter->addTitle('Antibiotic Capsules', 1, 110);
        $adapter->addTitle('Lavender Oil', 1, 120);

        $matches = $adapter->searchElements(Entry::find()->siteId(1)->search('antibioti oil'));

        self::assertSame(['100-1'], array_keys($matches));
    }

    public function testExactMatchesRemainPreferredOverFuzzySupplements(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Antibiotic Oil', 1, 100);
        $adapter->addTitle('Antibioti Exact', 1, 200);

        $matches = $adapter->searchElements(Entry::find()->siteId(1)->search('antibioti'));

        self::assertArrayHasKey('100-1', $matches);
        self::assertArrayHasKey('200-1', $matches);
        self::assertSame('200-1', array_key_first($matches));
    }

    public function testSearchElementsFiltersMatchesThroughTheElementQueryCriteria(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Powder Asset', 1, 100);
        $adapter->addSearchTerm('powder', 1, 200);
        $adapter->allowOnlyDocIds(['1:200']);

        $matches = $adapter->searchElements(Entry::find()->siteId(1)->search('powder')->orderBy('score')->limit(1));

        self::assertSame(['200-1'], array_keys($matches));
    }

    public function testTitleTermsAreIndexedEvenWhenSearchableTextDoesNotContainTitle(): void
    {
        $adapter = new InMemorySearchAdapter();

        $terms = $adapter->publicBuildIndexedTermFrequencies(
            'field content without the heading words',
            'Why Antibiotic Oil'
        );

        self::assertArrayHasKey('why', $terms);
        self::assertArrayHasKey('antibiotic', $terms);
        self::assertArrayHasKey('oil', $terms);
    }

    public function testStopWordOnlySearchCanMatchLongQuestionTitle(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Why do you sell the supplements you sell?', 1, 100);

        $query = Entry::find()->siteId(1)->search('why');
        $matches = $adapter->searchElements($query);

        self::assertArrayHasKey('100-1', $matches);
    }

    public function testClearIndexPreservesOtherSiteDocumentLengthMetadata(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addSearchTerm('lavender', 1, 100);
        $adapter->addSearchTerm('oil', 1, 100);
        $adapter->addSearchTerm('orchid', 2, 200);

        $adapter->clearIndex(1);

        self::assertSame(1, $adapter->publicTotalLength());
        self::assertSame(['2:200'], $adapter->publicSiteDocuments(2));
        self::assertSame([], $adapter->publicSiteDocuments(1));
    }

    public function testPruneIndexForSiteRemovesOnlyStaleDocumentsAfterRollingRebuild(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Fresh Powder', 1, 100);
        $adapter->addTitle('Old Powder', 1, 101);
        $adapter->addTitle('Other Site Powder', 2, 200);

        self::assertArrayHasKey('101-1', $adapter->searchElements(Entry::find()->siteId(1)->search('powder')));

        self::assertTrue($adapter->pruneIndexForSite(1, [100]));

        self::assertSame(['1:100'], $adapter->publicSiteDocuments(1));
        self::assertSame(['2:200'], $adapter->publicSiteDocuments(2));
        self::assertSame(['100-1'], array_keys($adapter->searchElements(Entry::find()->siteId(1)->search('powder'))));
        self::assertSame(['200-2'], array_keys($adapter->searchElements(Entry::find()->siteId(2)->search('powder'))));
    }
}

final class TestableCraftCacheSearchAdapter extends CraftCacheSearchAdapter
{
    public function setTestPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }

    public function addSearchTerm(string $term, int $siteId, int $elementId): void
    {
        $this->storeTermDocument($term, $siteId, $elementId, 1);
        $this->storeTermNgrams($term, $this->generateNgrams($term), $siteId);
    }

    public function publicGenerateNgrams(string $term): array
    {
        return $this->generateNgrams($term);
    }

    public function publicGetTermsByNgramSimilarity(string $term, int $siteId): array
    {
        return $this->getTermsByNgramSimilarity($this->generateNgrams($term), $siteId, 0.2);
    }
}

final class TestableFileSearchAdapter extends FileSearchAdapter
{
    public function setBaseDir(string $baseDir): void
    {
        $this->baseDir = $baseDir;
        $this->docsDir = $baseDir . '/docs';
        $this->termsDir = $baseDir . '/terms';
        $this->metaDir = $baseDir . '/meta';
        $this->titlesDir = $baseDir . '/titles';
        $this->ensureDirectoriesExist();
    }

    public function addSearchTerm(string $term, int $siteId, int $elementId): void
    {
        $this->storeTermDocument($term, $siteId, $elementId, 1);
        $this->storeTermNgrams($term, $this->generateNgrams($term), $siteId);
    }

    public function publicGetTermsByNgramSimilarity(string $term, int $siteId): array
    {
        return $this->getTermsByNgramSimilarity($this->generateNgrams($term), $siteId, 0.2);
    }
}

final class InMemorySearchAdapter extends BaseSearchAdapter
{
    private array $documents = [];
    private array $terms = [];
    private array $titleTerms = [];
    private array $ngrams = [];
    private ?array $allowedDocIds = null;
    private int $totalLength = 0;

    public function addSearchTerm(string $term, int $siteId, int $elementId, bool $titleTerm = false): void
    {
        $docId = "$siteId:$elementId";
        $this->documents[$docId] ??= [
            'siteId' => $siteId,
            'elementId' => $elementId,
            'terms' => [],
            'length' => 0,
        ];
        $this->documents[$docId]['terms'][$term] = ($this->documents[$docId]['terms'][$term] ?? 0) + 1;
        $this->documents[$docId]['length']++;
        $this->terms[$term][$docId] = 1;
        $this->ngrams[$siteId][$term] = $this->generateNgrams($term);
        $this->totalLength++;

        if ($titleTerm) {
            $this->titleTerms[$docId][$term] = true;
        }
    }

    public function addTitle(string $title, int $siteId, int $elementId): void
    {
        $titleTokens = $this->tokenize($title);
        $indexedTerms = array_keys($this->buildIndexedTermFrequencies('', $titleTokens));

        foreach ($indexedTerms as $term) {
            $this->addSearchTerm($term, $siteId, $elementId, true);
        }
    }

    public function publicBuildIndexedTermFrequencies(string $text, string $title): array
    {
        return $this->buildIndexedTermFrequencies($text, $this->tokenize($title));
    }

    public function publicTotalLength(): int
    {
        return $this->getTotalLength();
    }

    public function publicSiteDocuments(int $siteId): array
    {
        return $this->getSiteDocuments($siteId);
    }

    public function allowOnlyDocIds(array $docIds): void
    {
        $this->allowedDocIds = array_fill_keys($docIds, true);
    }

    protected function getDocumentTerms(int $siteId, int $elementId): array
    {
        return $this->documents["$siteId:$elementId"]['terms'] ?? [];
    }

    protected function removeTermDocument(string $term, int $siteId, int $elementId): void
    {
        unset($this->terms[$term]["$siteId:$elementId"]);
    }

    protected function deleteDocument(int $siteId, int $elementId): void
    {
        unset($this->documents["$siteId:$elementId"]);
    }

    protected function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLen): void
    {
        $this->documents["$siteId:$elementId"] = [
            'siteId' => $siteId,
            'elementId' => $elementId,
            'terms' => $termFreqs,
            'length' => $docLen,
        ];
    }

    protected function storeTermDocument(string $term, int $siteId, int $elementId, int $freq): void
    {
        $this->terms[$term]["$siteId:$elementId"] = $freq;
    }

    protected function addDocumentToIndex(int $siteId, int $elementId): void
    {
    }

    protected function updateTotalDocCount(): void
    {
    }

    protected function updateTotalLength(int $docLen): void
    {
        $this->totalLength = max(0, $this->totalLength + $docLen);
    }

    protected function getTotalDocCount(): int
    {
        return count($this->documents);
    }

    protected function getTotalLength(): int
    {
        return $this->totalLength;
    }

    protected function getTermDocuments(string $term): array
    {
        return $this->terms[$term] ?? [];
    }

    protected function getDocumentLength(string $docId): int
    {
        return $this->documents[$docId]['length'] ?? 0;
    }

    protected function getDocumentLengthsBatch(array $docIds): array
    {
        $lengths = [];
        foreach ($docIds as $docId) {
            $lengths[$docId] = $this->getDocumentLength($docId);
        }

        return $lengths;
    }

    protected function getAllTerms(): array
    {
        return array_keys($this->terms);
    }

    protected function filterScoresByElementQuery(array $scores, \craft\elements\db\ElementQuery $elementQuery): array
    {
        if ($this->allowedDocIds === null) {
            return $scores;
        }

        return array_intersect_key($scores, $this->allowedDocIds);
    }

    protected function storeTitleTerms(int $siteId, int $elementId, array $titleTerms): void
    {
        $this->titleTerms["$siteId:$elementId"] = $titleTerms;
    }

    protected function getTitleTerms(string $docId): array
    {
        return $this->titleTerms[$docId] ?? [];
    }

    protected function deleteTitleTerms(int $siteId, int $elementId): void
    {
        unset($this->titleTerms["$siteId:$elementId"]);
    }

    protected function getSiteDocuments(int $siteId): array
    {
        return array_values(array_filter(
            array_keys($this->documents),
            fn(string $docId): bool => str_starts_with($docId, "$siteId:")
        ));
    }

    protected function removeDocumentFromIndex(int $siteId, int $elementId): void
    {
    }

    protected function resetTotalLength(): void
    {
    }

    protected function removeTermFromIndex(string $term): void
    {
        unset($this->terms[$term]);
    }

    protected function storeTermNgrams(string $term, array $ngrams, int $siteId): void
    {
        $this->ngrams[$siteId][$term] = $ngrams;
    }

    protected function getTermsByNgramSimilarity(array $ngrams, int $siteId, float $threshold): array
    {
        $matches = [];

        foreach ($this->ngrams[$siteId] ?? [] as $term => $termNgrams) {
            $similarity = $this->calculateNgramSimilarity($ngrams, $termNgrams);
            if ($similarity >= $threshold) {
                $matches[$term] = $similarity;
            }
        }

        arsort($matches);

        return $matches;
    }

    protected function termHasNgrams(string $term, int $siteId): bool
    {
        return isset($this->ngrams[$siteId][$term]);
    }

    protected function clearNgrams(int $siteId): void
    {
        unset($this->ngrams[$siteId]);
    }

    protected function removeTermNgrams(string $term, int $siteId): void
    {
        unset($this->ngrams[$siteId][$term]);
    }
}
