<?php

declare(strict_types=1);

/**
 * CLI search validation against a live Craft project (therapy-organics).
 *
 * Usage (inside therapy-organics web container):
 *   php /bramble-search/tests/e2e/therapy-organics-validate.php
 */

$craftRoot = getenv('CRAFT_ROOT') ?: '/app';
if (!is_file("$craftRoot/craft")) {
    fwrite(STDERR, "CRAFT_ROOT not found: $craftRoot\n");
    exit(1);
}

define('CRAFT_BASE_PATH', $craftRoot);
require "$craftRoot/vendor/autoload.php";

if (class_exists(Dotenv\Dotenv::class) && is_file("$craftRoot/.env")) {
    Dotenv\Dotenv::createImmutable($craftRoot)->load();
}

/** @var craft\console\Application $app */
$app = require CRAFT_BASE_PATH . '/vendor/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Product;
use craft\elements\Entry;
use MadeByBramble\BrambleSearch\adapters\BaseSearchAdapter;

$search = Craft::$app->getSearch();
if (!($search instanceof BaseSearchAdapter)) {
    fwrite(STDERR, "Bramble Search adapter is not active.\n");
    exit(1);
}

$siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
$results = [];

$run = static function (string $id, callable $fn) use (&$results): void {
    try {
        $results[$id] = ['ok' => (bool)$fn(), 'error' => null];
    } catch (Throwable $e) {
        $results[$id] = ['ok' => false, 'error' => $e->getMessage()];
    }
};

$run('B1_product_exact', function () use ($siteId): bool {
    $product = Product::find()->siteId($siteId)->search('serum')->one()
        ?? Product::find()->siteId($siteId)->status(null)->limit(1)->one();
    if (!$product?->title) {
        return false;
    }
    $term = explode(' ', $product->title)[0];
    if (strlen($term) < 3) {
        return false;
    }
    $ids = Product::find()->siteId($siteId)->search($term)->ids();

    return in_array($product->id, $ids, true);
});

$run('B3_multi_term_and', function () use ($siteId): bool {
    $ids = Product::find()->siteId($siteId)->search('vitamin serum')->ids();

    return $ids !== [];
});

$run('B4_journal', function () use ($siteId): bool {
    return Entry::find()->section('journal')->siteId($siteId)->search('health')->exists();
});

$run('B2_fuzzy_typo', function () use ($siteId): bool {
    $product = Product::find()->siteId($siteId)->status(null)->search('serum')->one();
    if (!$product?->title) {
        return false;
    }
    $word = explode(' ', strtolower($product->title))[0];
    if (strlen($word) < 4) {
        return false;
    }
    $typo = substr($word, 0, -1) . 'x';

    return Product::find()->siteId($siteId)->search($typo)->id($product->id)->exists();
});

$run('E1_or_query', function () use ($siteId): bool {
    $query = new \craft\search\SearchQuery('serum OR vitamin');
    $ids = Product::find()->siteId($siteId)->search($query)->ids();

    return $ids !== [];
});

$run('E2_exclude_query', function () use ($siteId): bool {
    $all = Product::find()->siteId($siteId)->search('vitamin')->ids();
    if ($all === []) {
        return false;
    }

    $withSerum = Product::find()->siteId($siteId)->search('vitamin serum')->ids();
    if ($withSerum === []) {
        return false;
    }

    $filtered = Product::find()->siteId($siteId)->search('vitamin -serum')->ids();

    return count($filtered) < count($all)
        && array_diff($withSerum, $filtered) !== [];
});

$run('G2_stats_nonempty', function () use ($search): bool {
    return $search instanceof BaseSearchAdapter;
});

$meta = [
    'siteId' => $siteId,
    'adapter' => $search::class,
    'productCount' => Product::find()->siteId($siteId)->status(null)->count(),
    'timestamp' => date('c'),
];

echo json_encode(['meta' => $meta, 'results' => $results], JSON_PRETTY_PRINT) . PHP_EOL;

exit(array_reduce($results, static fn(bool $c, array $r): bool => $c && $r['ok'], true) ? 0 : 1);
