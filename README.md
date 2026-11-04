# Bramble Search

Bramble Search replaces Craft's search service with a separate index. It supports Redis, MySQL, MongoDB, local files and Craft Cache.

## Requirements

- Craft CMS 5.5 or later within Craft 5
- PHP 8.2 or later
- A database supported by your Craft installation

Redis requires the PHP Redis extension. MongoDB requires the PHP MongoDB extension and the `mongodb/mongodb` Composer package.

## Installation

Install through Craft's Plugin Store or run these commands from your Craft project.

```bash
composer require madebybramble/craft-bramble-search
php craft plugin/install bramble-search
```

## Configuration

Create `config/bramble-search.php`. Set `enabled` to `true` to use Bramble Search instead of Craft's native search.

```php
<?php

return [
    'enabled' => true,
    'storageDriver' => 'mysql',
    'siteSearchAsYouType' => false,
    'titleAliases' => [],

    'redisHost' => 'localhost',
    'redisPort' => 6379,
    'redisPassword' => null,
    'redisKeyPrefix' => 'bramble_search:',

    'mongoDbUri' => 'mongodb://localhost:27017',
    'mongoDbDatabase' => 'craft_search',

    'bm25K1' => 1.5,
    'bm25B' => 0.75,
    'titleBoostFactor' => 5.0,
    'exactMatchBoostFactor' => 3.0,
    'ngramSizes' => [1, 2, 3],
    'ngramSimilarityThreshold' => 0.25,
    'fuzzySearchMaxCandidates' => 100,
    'commonTermDemotionThreshold' => 0.5,
    'extraStopWords' => [],
    'removeStopWords' => ['how'],
];
```

Only configure the connection settings for the driver you use. MySQL is the default. Use a different Redis key prefix for each installation sharing a Redis database.

Environment variables override the corresponding settings.

| Variable | Setting |
| --- | --- |
| `BRAMBLE_SEARCH_DRIVER` | `storageDriver` |
| `BRAMBLE_SEARCH_REDIS_HOST` | `redisHost` |
| `BRAMBLE_SEARCH_REDIS_PORT` | `redisPort` |
| `BRAMBLE_SEARCH_REDIS_PASSWORD` | `redisPassword` |
| `BRAMBLE_SEARCH_REDIS_KEY_PREFIX` | `redisKeyPrefix` |
| `BRAMBLE_SEARCH_MONGODB_URI` | `mongoDbUri` |
| `BRAMBLE_SEARCH_MONGODB_DATABASE` | `mongoDbDatabase` |

### Autocomplete

Set `siteSearchAsYouType` to `true` for frontend autocomplete. The control panel uses autocomplete automatically. A trailing space marks the last word as complete.

### Title aliases

Use `titleAliases` to promote titles for an alternate search phrase. Every target word must appear in the title.

```php
'titleAliases' => [
    'desk lamp' => ['reading', 'lamp'],
],
```

Aliases apply to plain search phrases. Explicit query operators do not trigger them.

### Stop words

Use `extraStopWords` to exclude words from normal matching. Use `removeStopWords` to keep words that the bundled English list excludes. Rebuild the index after changing either setting.

## Searching

Use Craft's normal element queries. Sort by score when you want the most relevant results first.

```twig
{% set results = craft.entries()
    .search(craft.app.request.getQueryParam('q'))
    .orderBy('score DESC')
    .all() %}

{% for entry in results %}
    <a href="{{ entry.url }}">{{ entry.title }}</a>
{% endfor %}
```

Craft pagination uses the same query.

```twig
{% set resultsQuery = craft.entries()
    .search(craft.app.request.getQueryParam('q'))
    .orderBy('score DESC') %}

{% paginate resultsQuery as pageInfo, results %}
```

The same API is available in PHP.

```php
use craft\elements\Entry;

$results = Entry::find()
    ->search('your search query')
    ->orderBy('score DESC')
    ->limit(20)
    ->all();
```

Multiple words normally narrow the results. Common words can become optional when they would otherwise remove useful matches. Title matches receive a configurable score boost.

Attached units use the existing case insensitive matching without converting values. Spaced units use normal word matching. Leading zeros remain significant.

### Craft compatibility

| Feature | Behaviour |
| --- | --- |
| `OR` and excluded terms | Supported |
| `title:term` and `title::term` | Restrict matching to the attribute |
| `customFields` | Limits custom field matching. Attributes remain searchable |
| Site and source restrictions | Applied before results are returned |
| Counts and exports | Use the same matching rules |
| Craft search events | Forwarded by the adapter |
| Scores | Adapter scores are floats. Craft exposes element search scores as integers |

Bramble Search keeps its own index. If you disable it, Craft's native index may need rebuilding before native searches return current results.

## Index management

Build the index after enabling the plugin. Use **Utilities / Clear Caches / Bramble Search** or run the command below. It queues a rebuild for every Craft site.

```bash
php craft clear-caches/bramble-search
```

Content changes use Craft's normal indexing process. Disabled elements and drafts are excluded. Rebuilds include any lookup data required by the driver. Saved checkpoints allow interrupted batches to resume within Craft's retry limit. Start a new rebuild if a run has exhausted its retries. Check the queue for failed or stalled jobs.

The queue shows the current rebuild stage and the number of processed elements. Preparation shows activity counts until the total is known.

Rebuild after changing the site's structure or moving content between sites. The full rebuild removes indexed records belonging to deleted sites. Rebuild after enabling the plugin again if content changed while it was disabled.

### Statistics

```bash
php craft bramble-search/stats
php craft bramble-search/stats --detailed
```

The commands report an estimated index size and document counts. Use `--driver` to inspect a driver other than the configured one.

## Upgrading

Major releases often change the index format and require a full rebuild.

Check the changelog before updating. Pause indexing and stop queue workers while applying plugin migrations. Replace old rebuild jobs only when the release requires it. Keep ordinary indexing jobs queued.

Restart the workers and rebuild the index when required.

### Rolling back

Index formats are not guaranteed to work with older packages. Pause indexing before restoring an older version. Set aside queued jobs that need the newer package.

Clear incompatible indexes with the installed package before switching code, then rebuild them using the older package. The Bramble Search option in Clear Caches queues a rebuild and does not empty the index. Keep the migrated database schema unless you are restoring a compatible database backup.

## Storage and tuning

| Driver | Storage |
| --- | --- |
| `mysql` | Uses Craft's database |
| `redis` | Requires a Redis server |
| `mongodb` | Requires a MongoDB server |
| `file` | Uses `storage/runtime/bramble-search` |
| `craft` | Uses the configured Craft cache |

File and Craft Cache lookups can scan the stored vocabulary. Test them with your site's content volume before choosing them. Clearing the backing cache or deleting the index files removes that index.

BM25 settings control how term frequency and document length affect ranking. `titleBoostFactor` changes the weight of title matches. `fuzzySearchMaxCandidates` limits fuzzy candidates. Lower limits reduce work but can omit useful matches.

## License

Bramble Search uses a proprietary license. See LICENSE.md.

## Support

Contact [hello@madebybramble.co.uk](mailto:hello@madebybramble.co.uk).
