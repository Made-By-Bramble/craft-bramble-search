<?php

declare(strict_types=1);

namespace MadeByBrambleTest\BrambleSearch;

use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
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

    public function testCraftCacheAdapterFindsTermsByPrefix(): void
    {
        $adapter = new TestableCraftCacheSearchAdapter();
        $adapter->setTestPrefix('bramble_search_test:' . bin2hex(random_bytes(4)) . ':');
        $adapter->addSearchTerm('synergy', 1, 100);
        $adapter->addSearchTerm('system', 2, 200);

        $matches = $adapter->publicGetTermsByPrefix('s', 1);

        self::assertArrayHasKey('synergy', $matches);
        self::assertArrayNotHasKey('system', $matches);
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

    public function testFileAdapterFindsTermsByPrefix(): void
    {
        $adapter = new TestableFileSearchAdapter();
        $baseDir = Craft::getAlias('@runtime') . '/bramble-search-test-' . bin2hex(random_bytes(4));
        $adapter->setBaseDir($baseDir);

        try {
            $adapter->addSearchTerm('synergy', 1, 100);
            $adapter->addSearchTerm('system', 2, 200);

            $matches = $adapter->publicGetTermsByPrefix('s', 1);

            self::assertArrayHasKey('synergy', $matches);
            self::assertArrayNotHasKey('system', $matches);
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
        $catMatches = $adapter->searchElements(Entry::find()->siteId(1)->search('cat '));

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

    public function testSearchAsYouTypeUsesTheFinalTokenAsAPrefix(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Smooth Synergy Fifty Caps', 1, 100);
        $adapter->addTitle('Antrodia Mushroom Capsules', 1, 110);
        $adapter->addTitle('Reishi Mushroom Organic Powder', 1, 120);
        $adapter->addTitle('Synergy Complex', 1, 130);
        $adapter->addTitle('Smooth Mag Powder', 1, 140);
        $adapter->addSearchTerm('sleep', 1, 140);

        $matches = $adapter->searchElements(Entry::find()->siteId(1)->search('Smooth S'));

        self::assertSame('100-1', array_key_first($matches));
        self::assertArrayNotHasKey('130-1', $matches);
    }

    public function testSearchAsYouTypeKeepsCompletedTermsFuzzyAndFinalTermPrefix(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Smooth Synergy Fifty Caps', 1, 100);
        $adapter->addTitle('Antrodia Mushroom Capsules', 1, 110);
        $adapter->addTitle('Reishi Mushroom Organic Powder', 1, 120);

        $matches = $adapter->searchElements(Entry::find()->siteId(1)->search('Smoooth Syn'));

        self::assertSame('100-1', array_key_first($matches));
    }

    public function testSearchAsYouTypeRelevanceImprovesWithMoreSpecificPrefixes(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Smooth Synergy', 1, 100);
        $adapter->addTitle('Smooth Skin Balm', 1, 110);
        $adapter->addTitle('Synergy Smooth', 1, 120);
        $adapter->addTitle('Smoothing Shampoo', 1, 130);

        $matches = $adapter->searchElements(Entry::find()->siteId(1)->search('Smooth Sy'));

        self::assertSame('100-1', array_key_first($matches));
        self::assertArrayNotHasKey('110-1', $matches);
        self::assertArrayNotHasKey('130-1', $matches);
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

    public function testExactCompactTitleMatchesBeatLongerTitleSupersets(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Radiance Serum', 1, 100);
        $adapter->addTitle('Supernatural Radiance Serum Rich Aura', 1, 200);
        $adapter->addTitle('Parasite X Dewormer', 1, 300);
        $adapter->addTitle('Parasite X Dewormer Children', 1, 400);

        $radianceMatches = $adapter->searchElements(Entry::find()->siteId(1)->search('Radiance Serum'));
        $parasiteMatches = $adapter->searchElements(Entry::find()->siteId(1)->search('Parasite X Dewormer'));

        self::assertSame('100-1', array_key_first($radianceMatches));
        self::assertSame('300-1', array_key_first($parasiteMatches));
    }

    public function testSingleTermExactTitleBeatsLongerPrefixTitleMatches(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Juicy', 1, 100);
        $adapter->addTitle('NINI Organics Juicy Multi C Bio Brightening Serum', 1, 200);
        $adapter->addTitle('Clean', 1, 300);
        $adapter->addTitle('Natural Multi Purpose Cleaner Lemon', 1, 400);

        $juicyMatches = $adapter->searchElements(Entry::find()->siteId(1)->search('Juicy'));
        $cleanMatches = $adapter->searchElements(Entry::find()->siteId(1)->search('Clean'));

        self::assertSame('100-1', array_key_first($juicyMatches));
        self::assertSame('300-1', array_key_first($cleanMatches));
    }

    public function testTypeaheadPreservesCompletedStopWordWhenItIsTheOnlyCompletedTerm(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Your Pregnancy Nutrition Guide', 1, 100);
        $adapter->addTitle('Prenatal Multivitamin Complex', 1, 200);
        $adapter->addTitle('Bowen Technique Prepayment', 1, 300);
        $adapter->addTitle('P and P International Post', 1, 400);

        $singleLetterMatches = $adapter->searchElements(Entry::find()->siteId(1)->search('your p'));
        $prefixMatches = $adapter->searchElements(Entry::find()->siteId(1)->search('your pre'));

        self::assertSame('100-1', array_key_first($singleLetterMatches));
        self::assertSame('100-1', array_key_first($prefixMatches));
        self::assertArrayNotHasKey('200-1', $singleLetterMatches);
        self::assertArrayNotHasKey('300-1', $prefixMatches);
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

    public function testDeleteElementIndexRemovesDeletedElementImmediately(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('Old Powder', 1, 101);

        $element = new Entry();
        $element->id = 101;
        $element->siteId = 1;

        self::assertSame(['1:101'], $adapter->publicSiteDocuments(1));
        self::assertTrue($adapter->deleteElementIndex($element));

        self::assertSame([], $adapter->publicSiteDocuments(1));
        self::assertSame(0, $adapter->publicTotalLength());
        self::assertSame([], $adapter->searchElements(Entry::find()->siteId(1)->search('powder')));
    }

    public function testNullFieldHandlesIndexesAllSearchableFields(): void
    {
        $adapter = new InMemorySearchAdapter();
        $entry = IndexableTestEntry::withFields([
            'body' => 'bodyneedle content',
            'summary' => 'summaryneedle phrase',
        ]);

        self::assertTrue($adapter->indexElementAttributes($entry, null));
        self::assertArrayHasKey($entry->id . '-1', $adapter->searchElements(Entry::find()->siteId(1)->search('summaryneedle')));
    }

    public function testPartialFieldHandlesPreservesOtherFields(): void
    {
        $adapter = new InMemorySearchAdapter();
        $entry = IndexableTestEntry::withFields([
            'body' => 'bodyneedle token',
            'summary' => 'summaryneedle token',
        ]);

        self::assertTrue($adapter->indexElementAttributes($entry, ['body', 'summary']));
        self::assertTrue($adapter->indexElementAttributes($entry, ['body']));

        self::assertArrayHasKey($entry->id . '-1', $adapter->searchElements(Entry::find()->siteId(1)->search('summaryneedle')));
    }

    public function testEmptyFieldHandlesPreservesCustomFields(): void
    {
        $adapter = new InMemorySearchAdapter();
        $entry = IndexableTestEntry::withFields([
            'body' => 'bodypreserve needle',
            'summary' => 'summarypreserve needle',
        ]);

        self::assertTrue($adapter->indexElementAttributes($entry, ['body', 'summary']));
        $entry->title = 'Renamed Preserve Needle';
        self::assertTrue($adapter->indexElementAttributes($entry, []));

        self::assertArrayHasKey($entry->id . '-1', $adapter->searchElements(Entry::find()->siteId(1)->search('summarypreserve')));
        self::assertArrayHasKey($entry->id . '-1', $adapter->searchElements(Entry::find()->siteId(1)->search('renamed')));
    }

    public function testOrSearchQueryMatchesEitherTerm(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addSearchTerm('alpha', 1, 100);
        $adapter->addSearchTerm('beta', 1, 200);

        $query = new \craft\search\SearchQuery('alpha OR beta');
        $matches = $adapter->searchElements(Entry::find()->siteId(1)->search($query));

        self::assertArrayHasKey('100-1', $matches);
        self::assertArrayHasKey('200-1', $matches);
    }

    public function testExcludeSearchQueryRemovesMatches(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addSearchTerm('alpha', 1, 100);
        $adapter->addSearchTerm('beta', 1, 200);

        $matches = $adapter->searchElements(Entry::find()->siteId(1)->search('alpha -beta'));

        self::assertArrayHasKey('100-1', $matches);
        self::assertArrayNotHasKey('200-1', $matches);
    }

    public function testMultiTokenTermRequiresEveryTokenToMatch(): void
    {
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('L-Carnitine 500mg', 1, 100);
        $adapter->addTitle('L-Lysine 500mg', 1, 200);

        $matches = $adapter->searchElements(Entry::find()->siteId(1)->search('l-carnitine'));

        self::assertArrayHasKey('100-1', $matches);
        self::assertArrayNotHasKey('200-1', $matches);
    }

    public function testMultiTokenOrGroupMemberRequiresEveryTokenToMatch(): void
    {
        // OR-group members bypass expandMultiTokenTerms() and reach findTermMatchesForSpec()
        // as a single multi-word spec, so the recursive per-token resolution has to cover them too.
        $adapter = new InMemorySearchAdapter();
        $adapter->addTitle('L-Carnitine 500mg', 1, 100);
        $adapter->addTitle('Zinc Tablets', 1, 200);

        $matches = $adapter->searchElements(Entry::find()->siteId(1)->search(
            new \craft\search\SearchQuery('l-carnitine OR zinc')
        ));

        self::assertArrayHasKey('100-1', $matches);
        self::assertArrayHasKey('200-1', $matches);
    }

    public function testLongPrefixFindsCandidateWhenNgramSimilarityReturnsNothing(): void
    {
        // Simulates stale stored n-grams (e.g. after an ngramSizes setting change) by making
        // n-gram similarity retrieval return no candidates at all, the way Jaccard collapses
        // to zero when stored and current n-gram sizes disagree.
        $adapter = new StaleNgramInMemorySearchAdapter();
        $adapter->addSearchTerm('ashwagandha', 1, 100);

        $matches = $adapter->searchElements(Entry::find()->siteId(1)->search('ashwagand'));

        self::assertArrayHasKey('100-1', $matches);
    }

    public function testTermNgramsCurrentDetectsStaleCountAfterNgramSizeChange(): void
    {
        $adapter = new CountTrackingNgramAdapter();
        $currentNgrams = $adapter->publicGenerateNgrams('lavender');

        // Simulate n-grams stored under a previous ngramSizes setting: a mismatched count.
        $adapter->publicStoreTermNgrams('lavender', array_slice($currentNgrams, 0, 1), 1);
        self::assertFalse($adapter->publicTermNgramsCurrent('lavender', 1));

        $adapter->publicStoreTermNgrams('lavender', $currentNgrams, 1);
        self::assertTrue($adapter->publicTermNgramsCurrent('lavender', 1));
    }

    public function testIndexingRegeneratesStaleNgramsWhenCountMismatches(): void
    {
        $adapter = new CountTrackingNgramAdapter();
        $entry = IndexableTestEntry::withFields(['body' => 'ashwagandha extract']);

        // Pre-seed a stale n-gram count for a term this entry will index, simulating a term
        // whose n-grams were generated under a previous ngramSizes setting.
        $adapter->publicStoreTermNgrams('ashwagandha', ['stale'], 1);

        self::assertTrue($adapter->indexElementAttributes($entry, null));

        self::assertTrue($adapter->publicTermNgramsCurrent('ashwagandha', 1));
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

    public function publicGetTermsByPrefix(string $prefix, int $siteId): array
    {
        return $this->getTermsByPrefix($prefix, $siteId);
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

    public function publicGetTermsByPrefix(string $prefix, int $siteId): array
    {
        return $this->getTermsByPrefix($prefix, $siteId);
    }
}

class InMemorySearchAdapter extends BaseSearchAdapter
{
    private array $documents = [];
    private array $terms = [];
    private array $titleTerms = [];
    private array $ngrams = [];
    private array $termSources = [];
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
            $this->addSearchTerm((string)$term, $siteId, $elementId, true);
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

    /**
     * @param array<string, list<string>> $termSources
     */
    protected function storeDocumentTermSources(int $siteId, int $elementId, array $termSources): void
    {
        $this->termSources["$siteId:$elementId"] = $termSources;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function getDocumentTermSources(int $siteId, int $elementId): array
    {
        return $this->termSources["$siteId:$elementId"] ?? [];
    }

    protected function deleteDocumentTermSources(int $siteId, int $elementId): void
    {
        unset($this->termSources["$siteId:$elementId"]);
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

    /**
     * @return string[]
     */
    public function publicStopWords(): array
    {
        $property = new \ReflectionProperty(BaseSearchAdapter::class, 'stopWords');
        $property->setAccessible(true);

        /** @var string[] $stopWords */
        $stopWords = $property->getValue($this);

        return $stopWords;
    }

    /**
     * @param string[] $tokens
     * @return string[]
     */
    public function publicFilterStopWords(array $tokens): array
    {
        return $this->filterStopWords($tokens);
    }
}

/**
 * Simulates a search index whose stored n-grams no longer overlap with what the current
 * ngramSizes setting would generate: n-gram similarity retrieval always comes back empty,
 * the same symptom stale/mismatched n-grams produce in production adapters.
 */
final class StaleNgramInMemorySearchAdapter extends InMemorySearchAdapter
{
    protected function getTermsByNgramSimilarity(array $ngrams, int $siteId, float $threshold): array
    {
        return [];
    }
}

/**
 * Tracks n-gram counts the way RedisSearchAdapter/MySqlSearchAdapter do, so termNgramsCurrent()
 * can be exercised without a live Redis or MySQL connection.
 */
final class CountTrackingNgramAdapter extends InMemorySearchAdapter
{
    private array $ngramCounts = [];

    protected function storeTermNgrams(string $term, array $ngrams, int $siteId): void
    {
        parent::storeTermNgrams($term, $ngrams, $siteId);
        $this->ngramCounts[$siteId][$term] = count($ngrams);
    }

    protected function termNgramsCurrent(string $term, int $siteId): bool
    {
        if (!isset($this->ngramCounts[$siteId][$term])) {
            return false;
        }

        return $this->ngramCounts[$siteId][$term] === count($this->generateNgrams($term));
    }

    public function publicTermNgramsCurrent(string $term, int $siteId): bool
    {
        return $this->termNgramsCurrent($term, $siteId);
    }

    public function publicStoreTermNgrams(string $term, array $ngrams, int $siteId): void
    {
        $this->storeTermNgrams($term, $ngrams, $siteId);
    }

    public function publicGenerateNgrams(string $term): array
    {
        return $this->generateNgrams($term);
    }
}

final class IndexableTestEntry extends Entry
{
    public ?FieldLayout $testFieldLayout = null;
    public array $testFieldValues = [];

    public static function withFields(array $values, int $id = 9001): self
    {
        $fields = [];
        foreach (array_keys($values) as $handle) {
            $fields[] = new PlainText([
                'name' => ucfirst($handle),
                'handle' => $handle,
                'uid' => StringHelper::UUID(),
                'searchable' => true,
            ]);
        }

        $layout = new FieldLayout(['type' => self::class]);
        $elements = array_map(
            static fn(PlainText $field): CustomField => new CustomField($field),
            $fields
        );
        $tab = new FieldLayoutTab(['name' => 'Content']);
        $tab->setLayout($layout);
        $tab->setElements($elements);
        $layout->setTabs([$tab]);

        $entry = new self();
        $entry->id = $id;
        $entry->siteId = 1;
        $entry->enabled = true;
        $entry->title = 'Indexable Test Entry';
        $entry->testFieldValues = $values;
        $entry->testFieldLayout = $layout;

        return $entry;
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return $this->testFieldLayout;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        return $this->testFieldValues[$fieldHandle] ?? null;
    }
}
