<?php

namespace MadeByBramble\BrambleSearch\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use MadeByBramble\BrambleSearch\adapters\BaseSearchAdapter;
use yii\console\ExitCode;

/**
 * Stats Controller
 *
 * Console command that provides statistics about the Bramble Search index,
 * including document count, term count, and estimated storage size.
 */
class StatsController extends Controller
{
    /**
     * Whether to show detailed statistics including top terms and index health
     */
    public $detailed = false;

    /**
     * The storage driver to use (redis, file, mysql, mongodb, craft)
     */
    public $driver;

    /**
     * Define the command options
     *
     * @param string $actionID The ID of the action to get options for
     * @return array The options for the command
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'detailed';
        $options[] = 'driver';
        return $options;
    }

    /**
     * Define option aliases for the command
     *
     * @return array The option aliases
     */
    public function optionAliases(): array
    {
        $aliases = parent::optionAliases();
        $aliases['d'] = 'detailed';
        return $aliases;
    }

    /**
     * Display statistics about the Bramble Search index
     *
     * @return int Exit code
     */
    public function actionIndex(): int
    {
        $searchService = Craft::$app->getSearch();

        // Check if the search service is a Bramble Search adapter
        if (!($searchService instanceof BaseSearchAdapter)) {
            $this->stderr('Bramble Search is not currently active as the search service.' . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('Gathering Bramble Search index statistics...' . PHP_EOL . PHP_EOL, Console::FG_YELLOW);

        // Get basic statistics
        $totalDocs = $this->getTotalDocuments($searchService);
        $totalTerms = $this->getTotalTerms($searchService);
        $totalLength = $this->getTotalLength($searchService);
        $avgDocLength = $totalDocs > 0 ? round($totalLength / $totalDocs, 2) : 0;
        $storageSize = $this->getStorageSize($searchService);

        // Display basic statistics
        $this->stdout('=== Basic Statistics ===' . PHP_EOL, Console::FG_GREEN);
        $this->stdout('Total Documents: ', Console::FG_YELLOW);
        $this->stdout($totalDocs . PHP_EOL, Console::FG_GREY);

        $this->stdout('Total Unique Terms: ', Console::FG_YELLOW);
        $this->stdout($totalTerms . PHP_EOL, Console::FG_GREY);

        $this->stdout('Total Tokens: ', Console::FG_YELLOW);
        $this->stdout($totalLength . PHP_EOL, Console::FG_GREY);

        $this->stdout('Average Document Length: ', Console::FG_YELLOW);
        $this->stdout($avgDocLength . ' tokens' . PHP_EOL, Console::FG_GREY);

        $this->stdout('Estimated Storage Size: ', Console::FG_YELLOW);
        $this->stdout($this->formatBytes($storageSize) . PHP_EOL . PHP_EOL, Console::FG_GREY);

        // If detailed statistics are requested
        if ($this->detailed) {
            $this->displayDetailedStats($searchService);
        }

        $this->stdout('Done!' . PHP_EOL, Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Get the total number of documents in the index
     *
     * Uses reflection to access protected method in the search adapter
     *
     * @param BaseSearchAdapter $searchService The search service
     * @return int The total document count
     */
    protected function getTotalDocuments(BaseSearchAdapter $searchService): int
    {
        // Use reflection to access protected method
        $reflection = new \ReflectionClass($searchService);
        $method = $reflection->getMethod('getTotalDocCount');
        $method->setAccessible(true);

        return $method->invoke($searchService);
    }

    /**
     * Get the total number of unique terms in the index
     *
     * Uses reflection to access protected method in the search adapter
     *
     * @param BaseSearchAdapter $searchService The search service
     * @return int The total unique term count
     */
    protected function getTotalTerms(BaseSearchAdapter $searchService): int
    {
        // Use reflection to access protected method
        $reflection = new \ReflectionClass($searchService);
        $method = $reflection->getMethod('getAllTerms');
        $method->setAccessible(true);

        return count($method->invoke($searchService));
    }

    /**
     * Get the total length of all documents in the index
     *
     * Uses reflection to access protected method in the search adapter
     *
     * @param BaseSearchAdapter $searchService The search service
     * @return int The total token length
     */
    protected function getTotalLength(BaseSearchAdapter $searchService): int
    {
        // Use reflection to access protected method
        $reflection = new \ReflectionClass($searchService);
        $method = $reflection->getMethod('getTotalLength');
        $method->setAccessible(true);

        return $method->invoke($searchService);
    }

    /**
     * Get the estimated storage size of the index
     *
     * Calculates a rough estimate based on term, document, and token counts
     *
     * @param BaseSearchAdapter $searchService The search service
     * @return int The estimated storage size in bytes
     */
    protected function getStorageSize(BaseSearchAdapter $searchService): int
    {
        // This is a rough estimate based on the number of terms and documents
        $totalTerms = $this->getTotalTerms($searchService);
        $totalDocs = $this->getTotalDocuments($searchService);
        $totalLength = $this->getTotalLength($searchService);

        // Estimate based on average term length (8 bytes) plus overhead
        $termSize = $totalTerms * 20; // 20 bytes per term for storage
        $docSize = $totalDocs * 50;   // 50 bytes per document for metadata
        $tokenSize = $totalLength * 12; // 12 bytes per token for frequency and position

        return $termSize + $docSize + $tokenSize;
    }

    /**
     * Display detailed statistics about the index
     *
     * Shows top terms by document frequency and index health metrics
     *
     * @param BaseSearchAdapter $searchService The search service
     */
    protected function displayDetailedStats(BaseSearchAdapter $searchService): void
    {
        $this->stdout('=== Detailed Statistics ===' . PHP_EOL, Console::FG_GREEN);

        // Get the top terms by document frequency
        $topTerms = $this->getTopTerms($searchService, 10);

        $this->stdout('Top 10 Terms by Document Frequency:' . PHP_EOL, Console::FG_YELLOW);
        $i = 1;
        foreach ($topTerms as $term => $count) {
            $this->stdout("  {$i}. ", Console::FG_GREY);
            $this->stdout("'{$term}': ", Console::FG_CYAN);
            $this->stdout("{$count} documents" . PHP_EOL, Console::FG_GREY);
            $i++;
        }

        $this->stdout(PHP_EOL);

        // Get index health metrics
        $this->displayIndexHealth($searchService);
    }

    /**
     * Get the top terms by document frequency
     *
     * Uses reflection to access protected methods in the search adapter
     *
     * @param BaseSearchAdapter $searchService The search service
     * @param int $limit Maximum number of terms to return
     * @return array Top terms with their document counts
     */
    protected function getTopTerms(BaseSearchAdapter $searchService, int $limit = 10): array
    {
        // Use reflection to access protected methods
        $reflection = new \ReflectionClass($searchService);
        $getAllTermsMethod = $reflection->getMethod('getAllTerms');
        $getAllTermsMethod->setAccessible(true);

        $getTermDocumentsMethod = $reflection->getMethod('getTermDocuments');
        $getTermDocumentsMethod->setAccessible(true);

        $terms = $getAllTermsMethod->invoke($searchService);
        $termCounts = [];

        // This could be slow for large indexes, but it's a console command
        foreach ($terms as $term) {
            $docs = $getTermDocumentsMethod->invoke($searchService, $term);
            $termCounts[$term] = count($docs);
        }

        arsort($termCounts);
        return array_slice($termCounts, 0, $limit, true);
    }

    /**
     * Display index health metrics
     *
     * Shows term-to-document ratio and other health indicators
     *
     * @param BaseSearchAdapter $searchService The search service
     */
    protected function displayIndexHealth(BaseSearchAdapter $searchService): void
    {
        $this->stdout('Index Health:' . PHP_EOL, Console::FG_YELLOW);

        $totalDocs = $this->getTotalDocuments($searchService);
        $totalTerms = $this->getTotalTerms($searchService);

        // Calculate term-to-document ratio (higher is better for search precision)
        $termToDocRatio = $totalDocs > 0 ? round($totalTerms / $totalDocs, 2) : 0;

        $this->stdout('  Term-to-Document Ratio: ', Console::FG_GREY);
        $color = $termToDocRatio > 5 ? Console::FG_GREEN : ($termToDocRatio > 2 ? Console::FG_YELLOW : Console::FG_RED);
        $this->stdout($termToDocRatio . PHP_EOL, $color);

        // Add more health metrics as needed

        $this->stdout(PHP_EOL);
    }

    /**
     * Format bytes to human-readable format
     *
     * Converts raw byte count to KB, MB, GB, or TB as appropriate
     *
     * @param int $bytes The number of bytes
     * @param int $precision The number of decimal places
     * @return string Formatted size with unit
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
