# Release Notes for Bramble Search

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