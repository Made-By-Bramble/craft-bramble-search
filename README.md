# 🔍 Bramble Search

A powerful search engine plugin for Craft CMS that replaces the default search service with an enhanced inverted index implementation. Designed for performance, accuracy, and flexibility.

## ✨ Features

- 📊 **Inverted Index Architecture** - Fast, efficient search using modern indexing techniques
- 🔤 **Fuzzy Search** - Find results even with typos using n-gram similarity with adaptive thresholds
- 🧮 **BM25 Ranking Algorithm** - Industry-standard relevance scoring for better results
- 🔄 **Multiple Storage Backends** - Choose between Redis (fastest), File Storage, MySQL, MongoDB, or Craft cache
- 📝 **Stop Word Removal** - Filter out common words to improve search relevance
- 🔠 **Title Field Boosting** - Prioritize matches in title fields (5x boost factor)
- 📏 **Exact Phrase Matching** - Boost results that contain the exact search phrase (3x boost factor)
- 🎛️ **Craft Search Replacement** - Seamlessly replaces Craft's built-in search service
- 🌐 **Multi-site Support** - Each site has its own search index
- 🔄 **Automatic Indexing** - Content is automatically indexed when created, updated, or deleted
- 🧹 **Orphaned Term Cleanup** - Automatically removes terms with no associated documents
- 🛠️ **Console Commands** - Tools for viewing index statistics and rebuilding the index
- 🔍 **AND Logic for Multiple Terms** - Narrows results as more terms are added (unlike Craft's default OR logic)
- 🚫 **Revision Handling** - Properly skips drafts, revisions, and provisional drafts during indexing

## 📋 Requirements

- 🔧 Craft CMS 5.5.0 or later
- 💻 PHP 8.2 or later
- 🔄 Redis 5.0+ (for Redis driver)
- 🗄️ MySQL 5.7.8+ or MariaDB 10.2.7+ (for MySQL driver)
- 🍃 MongoDB 4.4+ (for MongoDB driver)

## 📦 Installation

### Via Plugin Store

1. 🛒 Open your Craft Control Panel
2. 🔍 Navigate to **Plugin Store** → **Search** for "Bramble Search"
3. ⬇️ Click **Install**

### Via Composer

```bash
# Navigate to your project directory
cd /path/to/your-project

# Install the plugin
composer require made-by-bramble/craft-bramble-search

# Install the plugin in Craft
./craft plugin/install bramble-search
```

## 🛠️ Configuration

Bramble Search can be configured via the Control Panel or a configuration file.

### Configuration File Setup

Create a new file at `config/bramble-search.php` in your Craft project:

```php
<?php
// config/bramble-search.php
return [
    // Whether to enable the plugin and replace Craft's search service
    'enabled' => false,

    // Storage driver: 'redis', 'file', 'mysql', 'mongodb', or 'craft'
    'storageDriver' => 'mysql',

    // Redis connection settings (only needed if using Redis driver)
    'redisHost' => 'localhost',
    'redisPort' => 6379,
    'redisPassword' => null,
    'redisKeyPrefix' => 'bramble_search:',

    // MongoDB connection settings (only needed if using MongoDB driver)
    'mongoDbUri' => 'mongodb://localhost:27017',
    'mongoDbDatabase' => 'craft_search',

    // BM25 algorithm parameters (optional - defaults shown)
    'bm25K1' => 1.5,                    // Term frequency saturation (0.1-5.0)
    'bm25B' => 0.75,                    // Document length normalization (0.0-1.0)
    'titleBoostFactor' => 5.0,          // Title field boost multiplier (1.0-20.0)
    'exactMatchBoostFactor' => 3.0,     // Exact phrase match boost (1.0-20.0)

    // N-gram fuzzy search parameters (optional - defaults shown)
    'ngramSizes' => [1, 2, 3],          // N-gram sizes for fuzzy matching
    'ngramSimilarityThreshold' => 0.25, // Minimum similarity threshold (0.0-1.0)
    'fuzzySearchMaxCandidates' => 100,  // Max fuzzy candidates to process
];
```

All settings can be overridden using environment variables:
- `BRAMBLE_SEARCH_DRIVER` - Storage driver ('redis', 'file', 'mysql', 'mongodb', or 'craft')
- `BRAMBLE_SEARCH_REDIS_HOST` - Redis host
- `BRAMBLE_SEARCH_REDIS_PORT` - Redis port
- `BRAMBLE_SEARCH_REDIS_PASSWORD` - Redis password
- `BRAMBLE_SEARCH_REDIS_KEY_PREFIX` - Redis key prefix, useful when multiple sites share a Redis database
- `BRAMBLE_SEARCH_MONGODB_URI` - MongoDB connection URI
- `BRAMBLE_SEARCH_MONGODB_DATABASE` - MongoDB database name

## 🚀 Getting Started

### Initializing the Search Index

After installing and enabling the plugin, you need to build the search index:

1. Go to **Utilities → Clear Caches** in the Control Panel
2. Check the **Bramble Search** option
3. Click **Clear Caches**

Alternatively, you can use the command line:

```bash
# Build the search index
./craft clear-caches/bramble-search
```

This will queue a job to rebuild the search index for the current site.

### Basic Usage

Since Bramble Search replaces Craft's built-in search service, you can use Craft's standard search functionality in your templates. No special template variables are needed.

#### Standard Craft Search

```twig
{# Use Craft's standard search functionality #}
{% set entries = craft.entries()
    .search('your search query')
    .all() %}

{# Display results #}
{% for entry in entries %}
    <article class="search-result">
        <h2><a href="{{ entry.url }}">{{ entry.title }}</a></h2>
        <p>{{ entry.summary }}</p>
    </article>
{% endfor %}
```

#### Search with Pagination

```twig
{# Use Craft's standard search with pagination #}
{% set entriesQuery = craft.entries()
    .search('your search query') %}

{# Paginate the results #}
{% paginate entriesQuery as pageInfo, pageEntries %}

{# Display results #}
{% for entry in pageEntries %}
    <article class="search-result">
        <h2><a href="{{ entry.url }}">{{ entry.title }}</a></h2>
    </article>
{% endfor %}

{# Display pagination #}
{% if pageInfo.totalPages > 1 %}
    <nav class="pagination">
        {% if pageInfo.prevUrl %}
            <a href="{{ pageInfo.prevUrl }}">Previous</a>
        {% endif %}

        <span class="current">{{ pageInfo.currentPage }}</span>

        {% if pageInfo.nextUrl %}
            <a href="{{ pageInfo.nextUrl }}">Next</a>
        {% endif %}
    </nav>
{% endif %}
```

## 🔍 Advanced Usage

### AJAX Search with Craft

You can use Craft's built-in ElementAPI or GraphQL to create AJAX search experiences:

```javascript
// In your JavaScript file
document.getElementById('search-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const query = this.querySelector('input[name="q"]').value;

    // Using Craft's Element API endpoint
    fetch(`/api/entries.json?search=${encodeURIComponent(query)}`)
        .then(response => response.json())
        .then(data => {
            const resultsContainer = document.getElementById('search-results');
            resultsContainer.innerHTML = '';

            if (data.length === 0) {
                resultsContainer.innerHTML = '<p class="no-results">No results found</p>';
                return;
            }

            const resultsList = document.createElement('ul');
            data.forEach(entry => {
                const item = document.createElement('li');
                item.innerHTML = `
                    <a href="${entry.url}">
                        <h3>${entry.title}</h3>
                    </a>
                `;
                resultsList.appendChild(item);
            });

            resultsContainer.appendChild(resultsList);
        });
});
```

### Programmatic Search

You can use Craft's standard ElementQuery API with search parameters:

```php
// In your PHP code
use craft\elements\Entry;

// Perform a search
$entries = Entry::find()
    ->search('your search query')
    ->siteId(Craft::$app->sites->currentSite->id)
    ->section(['blog', 'news'])
    ->limit(20)
    ->orderBy('score')
    ->all();

// Process results
foreach ($entries as $entry) {
    echo $entry->title;
}
```

## ⚙️ Performance Tuning

### Storage Driver Selection

Choose the right storage driver based on your site's needs:

| Driver | Best For | Pros | Cons |
|--------|----------|------|------|
| **Redis** | Medium to large sites | Fastest performance, persistent storage | Requires Redis server setup |
| **File Storage** | Small to medium sites | Custom binary format, file locking for concurrent access | Slow on networked file systems |
| **MySQL** *(default)* | Small to medium sites | Persistent storage, n-gram optimization, no additional dependencies | Adds load to Craft's database |
| **MongoDB** | Medium to large sites | Flexible schema, excellent scalability, optimized n-gram aggregation | Requires MongoDB server setup |
| **Craft Cache** | Small to medium sites | Easy setup, no additional dependencies | Limited persistence, not scalable |

### Indexing Considerations

- The plugin automatically skips drafts, revisions, and provisional drafts during indexing
- The plugin indexes all searchable attributes and fields as defined by Craft's ElementHelper::searchableAttributes()
- Title fields receive special handling with 5x boosting for better relevance
- N-gram indexing provides 10-100x faster fuzzy search compared to traditional Levenshtein distance
- Adaptive similarity thresholds optimize fuzzy matching for short terms (2-3 characters)

## 🛠️ Command Line Tools

### Index Management

The primary way to rebuild the search index is through the Clear Caches utility in the Control Panel or by using the clear-caches command:

```bash
# Rebuild the search index
./craft clear-caches/bramble-search
```

This will queue a job that processes entries in batches to avoid memory issues, making it suitable for sites with large numbers of entries.

### Statistics

View detailed information about your search index:

```bash
# View basic index statistics
./craft bramble-search/stats

# View detailed statistics including top terms
./craft bramble-search/stats --detailed

# Specify a storage driver
./craft bramble-search/stats --driver=redis
./craft bramble-search/stats --driver=file
./craft bramble-search/stats --driver=mysql
./craft bramble-search/stats --driver=mongodb
./craft bramble-search/stats --driver=craft
```

The statistics command provides information about:
- Total number of documents in the index
- Total number of unique terms
- Total number of tokens
- Average document length
- Estimated storage size
- Top terms by document frequency (with --detailed flag)
- Term-to-document ratio and other health metrics (with --detailed flag)

## 🔄 Automatic Indexing

Bramble Search automatically indexes entries when they are:
- Created
- Updated
- Deleted

## 🎛️ How It Works

Bramble Search completely replaces Craft's built-in search service when enabled. This is the core functionality of the plugin.

When you enable the plugin by setting `enabled = true`, it:

1. Registers its own search adapter as Craft's search service
2. Intercepts all search queries from both the Control Panel and frontend
3. Processes searches using its enhanced inverted index and BM25 ranking
4. Implements AND logic for multiple search terms (requiring all terms to be present)
5. Applies n-gram similarity fuzzy matching when exact matches aren't found

### What This Affects

When enabled, Bramble Search will:

- 🎛️ **Replace Admin CP Search** - All searches in the Control Panel will use Bramble Search
- 🌐 **Replace Frontend Element Queries** - Any `craft.entries.search()` queries will use Bramble Search
- 🔄 **Handle Element Exports** - Search-based element exports will use Bramble Search
- 🔢 **Fix Element Counts** - Search result counts in the Control Panel will be accurate
- 🔍 **Improve Search Relevance** - Results are ranked using BM25 with title and exact match boosting

> ⚠️ **Important**: After enabling the plugin, you must build your search index using the Clear Caches utility in the Control Panel or by running `./craft clear-caches/bramble-search`.

## 📄 License

Bramble Search is licensed under a proprietary license. See the LICENSE.md file for details.

## 👨‍💻 Support

For support, please contact [hello@madebybramble.co.uk](mailto:hello@madebybramble.co.uk).

---

Made with ❤️ by [Made By Bramble](https://madebybramble.co.uk)
