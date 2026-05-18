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

    public function addSearchTerm(string $term, int $siteId, int $elementId): void
    {
        $docId = "$siteId:$elementId";
        $this->documents[$docId] = [
            'siteId' => $siteId,
            'elementId' => $elementId,
            'terms' => [$term => 1],
            'length' => 1,
        ];
        $this->terms[$term][$docId] = 1;
        $this->ngrams[$siteId][$term] = $this->generateNgrams($term);
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
    }

    protected function getTotalDocCount(): int
    {
        return count($this->documents);
    }

    protected function getTotalLength(): int
    {
        return max(1, array_sum(array_map(fn(array $document): int => $document['length'], $this->documents)));
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
