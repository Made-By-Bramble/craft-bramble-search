# Release Notes for Bramble Search

## 1.0.14
- Improved fuzzy search robustness with supplemental fuzzy results, edit-distance precision filtering, partial-prefix matches, and confidence scoring so exact matches remain preferred.
- Fixed exact title indexing so title terms are always indexed directly, including stop words such as `Why`.
- Added broad unit and live Craft 5 coverage for title and field fuzzy searches, including `Antibioti` finding `Antibiotic Oil` and `Why` finding `Why do you sell the supplements you sell?`.

## 1.0.13
- Fixed title stop words so terms like `Why` are retained in title indexes and can be found by title-only searches.
- Fixed fuzzy fallback so exact matches on another site do not prevent fuzzy matching on the active element query site.
- Added Craft 5 driver matrix coverage for title stop-word searches and site-scoped fuzzy fallback across MySQL, file, Craft cache, Redis, and MongoDB storage drivers.

## 1.0.12
- Fixed Composer installs by making the MongoDB library an optional suggested package instead of a default requirement.

## 1.0.11
- Fixed fuzzy search for file and Craft cache storage drivers by using stored n-gram similarity data.
- Fixed fuzzy search lookups so candidate terms are scoped to the element query site.
- Fixed MongoDB fuzzy search by normalising n-gram arrays before storage and aggregation queries.
- Fixed indexing when Craft passes native or stale field handles by ignoring non-custom or non-searchable field handles before reading field values.
- Fixed stats command driver selection so `--driver` uses the requested storage adapter.
- Added MongoDB library dependency required by the MongoDB storage adapter.
- Added Craft 5 feature and live-field test coverage for all standard field types, multi-site language search, fuzzy search, queue indexing, settings rendering, and all storage drivers.

## 1.0.10
- Added configurable Redis key prefixes to isolate indexes when multiple Craft installs share one Redis database
- Fixed Control Panel search pagination counts so active element index source constraints are preserved

## 1.0.9
- Optimised MySQL adapter for large-scale index rebuilds (250K+ documents) by replacing per-document operations with bulk SQL
- Added bulk `clearIndex()` override using direct DELETE statements instead of iterating documents one by one
- Added batched `indexElementAttributes()` override using `batchInsert()` for term and n-gram storage
- Added `bulkMode` flag to defer `updateTotalDocCount` during rebuilds, called once in `after()` instead of per element
- Added `refreshTotalLength()` to recalculate total length from stored document data after rebuild
- Fixed duplicate metadata rows caused by missing `removeDocumentFromIndex` call during re-indexing
- Ensured `bulkMode` is always reset via `try/finally` even if the rebuild job fails

## 1.0.8
- Fixed MySQL deadlocks during concurrent indexing by replacing DELETE+INSERT with atomic UPDATEs for metadata rows
- Added automatic deadlock retry with random back-off for reliable concurrent indexing
- Fixed race condition in total length tracking by using atomic SQL increment instead of read-then-write

## 1.0.7
- Updated MySQL/MariaDB database table character sets to support accented characters

## 1.0.6
- Fixed CP search results pagination

## 1.0.5
- Fixed UTF-8 multibyte character handling in n-gram generation (fixes indexing of words with accented characters like café, naïve, über)

## 1.0.4
- Fixed console command compatibility for search queries by checking request type before calling `getPathInfo()`

## 1.0.3
- Fixed "Undefined array key 'score'" error when searching elements without explicit orderBy('score')
- Ensured orderBy['score'] is set when shouldCallSearchElements() returns true to prevent Craft from accessing non-existent array key

## 1.0.2
- Fixed search routing to always use Bramble Search's inverted index (override `shouldCallSearchElements()`)
- Enhanced RebuildIndexJob to index all registered element types, including Commerce Products and Variants
- Added MultiElementTypeBatcher to support batch processing across multiple element types
- Fixed real-time indexing for new elements by ensuring all enabled elements are queued for indexing on save

## 1.0.1
- Converted rebuild index job to use Batchable interface
- Fixed asset manager searching by implementing SearchQuery support

## 1.0.0
- Initial release
