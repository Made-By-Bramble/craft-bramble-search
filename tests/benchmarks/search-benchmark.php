<?php

declare(strict_types=1);

/**
 * Lightweight search benchmark harness for Bramble Search.
 *
 * Usage: php tests/benchmarks/search-benchmark.php [documentCount]
 */

use craft\elements\Entry;
use MadeByBrambleTest\BrambleSearch\InMemorySearchAdapter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/AdapterFeatureTest.php';

Craft::setAlias('@bramble_search', dirname(__DIR__, 2) . '/src');

$documentCount = max(100, (int)($argv[1] ?? 1000));
$adapter = new InMemorySearchAdapter();

for ($i = 1; $i <= $documentCount; $i++) {
    $adapter->addSearchTerm('product', 1, $i);
    $adapter->addSearchTerm('catalogitem' . ($i % 50), 1, $i);
}

$filterQuery = Entry::find()->siteId(1)->search('product catalogitem1');
$scoredQuery = Entry::find()->siteId(1)->search('product catalogitem1')->orderBy('score');

$filterStart = hrtime(true);
for ($i = 0; $i < 20; $i++) {
    $adapter->createDbQuery('product catalogitem1', $filterQuery);
}
$filterMs = (hrtime(true) - $filterStart) / 1_000_000 / 20;

$scoredStart = hrtime(true);
for ($i = 0; $i < 20; $i++) {
    $adapter->searchElements($scoredQuery);
}
$scoredMs = (hrtime(true) - $scoredStart) / 1_000_000 / 20;

echo json_encode([
    'documentCount' => $documentCount,
    'filterOnlyMs' => round($filterMs, 3),
    'scoredSearchMs' => round($scoredMs, 3),
    'speedup' => $filterMs > 0 ? round($scoredMs / $filterMs, 2) : null,
], JSON_PRETTY_PRINT) . PHP_EOL;
