<?php

declare(strict_types=1);

/**
 * Compare CP-style product search vs therapy-organics storefront search logic.
 *
 * Usage (inside therapy-organics web container):
 *   php /bramble-search/tests/e2e/compare-product-search.php pumpkin
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
use modules\therapyorganics\variables\CatalogVariable;

$term = $argv[1] ?? '';
if ($term === '') {
    fwrite(STDERR, "Usage: compare-product-search.php <term>\n");
    exit(1);
}

$siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

$cpIds = Product::find()->siteId($siteId)->status(null)->search($term)->ids();

$catalogClass = new ReflectionClass(CatalogVariable::class);
$catalog = $catalogClass->newInstance();
$publicProductIds = $catalogClass->getMethod('publicProductIds');
$publicProductIds->setAccessible(true);
$matchingProductIds = $catalogClass->getMethod('matchingProductIds');
$matchingProductIds->setAccessible(true);

$liveBase = Product::find()->siteId($siteId)->status(Product::STATUS_LIVE);
$publicIds = $publicProductIds->invoke($catalog, clone $liveBase);
$publicQuery = Product::find()->siteId($siteId)->status(Product::STATUS_LIVE)->id($publicIds);
$publicDirectIds = (clone $publicQuery)->search($term)->ids();
$frontendIds = $matchingProductIds->invoke($catalog, $publicQuery, $term);

$onlyCp = array_values(array_diff($cpIds, $frontendIds));
$onlyFrontend = array_values(array_diff($frontendIds, $cpIds));

$relatedSections = [
    'linkedConcern' => 'concerns',
    'keywordTags' => 'keywords',
    'brand' => 'brands',
    'productCategory' => 'productCategory',
    'ethosTags' => 'ethos',
    'hairTypes' => 'hairType',
    'skinType' => 'skinType',
];

$expansion = [];
foreach ($relatedSections as $fieldHandle => $section) {
    $relatedEntryIds = Entry::find()->section($section)->siteId($siteId)->search($term)->ids();
    if ($relatedEntryIds === []) {
        continue;
    }
    $viaRelation = (clone $publicQuery)->{$fieldHandle}($relatedEntryIds)->ids();
    $expansion[$fieldHandle] = [
        'relatedEntries' => count($relatedEntryIds),
        'productsViaRelation' => count($viaRelation),
        'notInCp' => count(array_diff($viaRelation, $cpIds)),
    ];
}

echo json_encode([
    'term' => $term,
    'siteId' => $siteId,
    'counts' => [
        'cpProductSearch' => count($cpIds),
        'storefrontPublicDirectSearch' => count($publicDirectIds),
        'storefrontFullSearch' => count($frontendIds),
    ],
    'diff' => [
        'cpNotStorefront' => count($onlyCp),
        'storefrontNotCp' => count($onlyFrontend),
    ],
    'cpNotStorefrontSample' => array_map(static function (int $id): array {
        $product = Product::find()->id($id)->one();

        return [
            'id' => $id,
            'title' => $product?->title,
            'status' => $product?->status,
        ];
    }, array_slice($onlyCp, 0, 10)),
    'storefrontNotCpSample' => array_map(static function (int $id): array {
        $product = Product::find()->id($id)->one();

        return [
            'id' => $id,
            'title' => $product?->title,
        ];
    }, array_slice($onlyFrontend, 0, 10)),
    'relatedExpansion' => $expansion,
    'verdict' => count($onlyCp) || count($onlyFrontend)
        ? 'Mismatch is site integration (CatalogVariable), not Bramble Search index parity'
        : 'CP and storefront product sets match for this term',
], JSON_PRETTY_PRINT) . PHP_EOL;
