# Release Notes for Bramble Search

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