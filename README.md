# 🔍 Bramble Search

A powerful search engine plugin for Craft CMS with inverted index, fuzzy search, and multiple storage backends. Designed for performance, accuracy, and flexibility.

## ✨ Features

- 📊 **Inverted Index Architecture** - Fast, efficient search using modern indexing techniques
- 🔤 **Fuzzy Search** - Find results even with typos using Levenshtein distance
- 🔄 **Multiple Storage Backends** - Choose between Craft cache, MySQL, or Redis
- 📝 **Advanced Text Processing** - Stop word removal and stemming for better results
- 📱 **AJAX Support** - Built-in support for instant search results
- 📄 **Pagination** - Automatic pagination for search results
- ⚡ **Performance Optimized** - Chunked storage for memory optimization
- 🧩 **Flexible Integration** - Easy to integrate with your templates
- 🎛️ **Craft Integration** - Optionally replace Craft's search service for both admin and frontend

## 📋 Requirements

- 🔧 Craft CMS 5.5.0 or later
- 💻 PHP 8.2 or later
- 🗄️ MySQL 5.7.8+ or MariaDB 10.2.7+ (for MySQL driver)
- 🔄 Redis 5.0+ (for Redis driver)

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

Bramble Search can be configured via a configuration file.

### Configuration File Setup

1. 📁 Create a new file at `config/bramble-search.php` in your Craft project
2. ✏️ Add your configuration settings:

```php
<?php
// config/bramble-search.php
return [
    // Storage driver: 'craft', 'mysql', or 'redis'
    'storageDriver' => 'craft', // Default uses Craft's cache

    // Redis connection string (only needed if using Redis driver)
    'redisConnection' => 'redis://localhost:6379',

    // MySQL table prefix (for MySQL driver)
    'mysqlTablePrefix' => 'bramble_search_',

    // Search behavior settings
    'fuzzyDistanceThreshold' => 3,
    'removeStopWords' => true,
    'applyStemming' => true,
    'useTfIdf' => true,

    // Performance settings
    'chunkSize' => 1000,
    'numChunks' => 10,

    // Integration settings
    'replaceCraftSearch' => false, // Whether to replace Craft's search service (affects both admin and frontend)
];
```

## 🚀 Getting Started

### Initializing the Search Index

After installing the plugin, you need to build the search index:

```bash
# Build the search index
./craft bramble-search/index/rebuild

# Or with specific site ID
./craft bramble-search/index/rebuild --siteId=1

# Specify batch size for large sites
./craft bramble-search/index/rebuild --batchSize=200
```

For production environments with many entries, you can queue the rebuild process:

```bash
./craft bramble-search/index/queue-rebuild
```

### Basic Usage

#### Simple Search with Pagination

```twig
{# Perform a search (paginated by default) #}
{% set results = craft.brambleSearch.search('your search query') %}

{# Display results #}
{% for result in results %}
    <article class="search-result">
        <h2><a href="{{ result.url }}">{{ result.title }}</a></h2>
        <p>{{ result.summary }}</p>
    </article>
{% endfor %}

{# Display pagination #}
{% if results.totalPages > 1 %}
    <nav class="pagination">
        {% if results.prevUrl %}
            <a href="{{ results.prevUrl }}">← Previous</a>
        {% endif %}

        {% for page, url in results.getPrevUrls(2) %}
            <a href="{{ url }}">{{ page }}</a>
        {% endfor %}

        <span class="current">{{ results.currentPage }}</span>

        {% for page, url in results.getNextUrls(2) %}
            <a href="{{ url }}">{{ page }}</a>
        {% endfor %}

        {% if results.nextUrl %}
            <a href="{{ results.nextUrl }}">Next →</a>
        {% endif %}
    </nav>
{% endif %}
```

#### Advanced Search Options

```twig
{# Advanced search with options #}
{% set results = craft.brambleSearch.search('your search query', {
    sectionIds: [1, 2, 3],     # Limit to specific sections
    fieldIds: [4, 5, 6],       # Search only specific fields
    fuzzy: true,               # Enable fuzzy matching
    fuzzyDistance: 2,          # Levenshtein distance for fuzzy matching
    requireAll: true,          # Require all terms to match
    limit: 20,                 # Results per page
    paginate: true             # Enable pagination (default)
}) %}
```

#### Non-Paginated Search

```twig
{# Search without pagination #}
{% set results = craft.brambleSearch.search('your search query', {
    paginate: false
}) %}

{# Display results #}
{% for result in results %}
    <h2>{{ result.title }}</h2>
    <p>{{ result.summary }}</p>
{% endfor %}
```

## 📱 AJAX Search Implementation

Create dynamic, instant search experiences with the built-in AJAX support:

```javascript
// In your JavaScript file
document.getElementById('search-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const query = this.querySelector('input[name="q"]').value;

    // Set the X-Requested-With header to trigger AJAX detection in Craft
    fetch(`/actions/bramble-search/search?q=${encodeURIComponent(query)}`, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        const resultsContainer = document.getElementById('search-results');
        resultsContainer.innerHTML = '';

        if (data.results.length === 0) {
            resultsContainer.innerHTML = '<p class="no-results">No results found</p>';
            return;
        }

        const resultsList = document.createElement('ul');
        resultsList.className = 'search-results-list';

        data.results.forEach(result => {
            const item = document.createElement('li');
            item.innerHTML = `
                <a href="${result.url}" class="search-result-item">
                    <h3>${result.title}</h3>
                    <span class="search-result-section">${result.section}</span>
                </a>
            `;
            resultsList.appendChild(item);
        });

        resultsContainer.appendChild(resultsList);
    });
});
```

## 💻 Advanced Usage

### Custom Search Filters

```twig
{# Search with custom filters #}
{% set results = craft.brambleSearch.search('your search query', {
    sectionIds: [1, 2, 3],     # Limit to specific sections
    siteId: craft.app.sites.currentSite.id,
    fuzzy: true,
    fuzzyDistance: 2,
    limit: 20,
    offset: 0,
    orderBy: 'score',          # 'score', 'title', 'postDate', etc.
    orderDir: 'desc',          # 'asc' or 'desc'
    paginate: true
}) %}

{# Display results #}
{% for result in results %}
    <article class="search-result">
        <h2><a href="{{ result.url }}">{{ result.title }}</a></h2>
        <p>{{ result.section }}</p>
    </article>
{% endfor %}
```

### Programmatic Search

```php
// In your PHP code
use MadeByBramble\BrambleSearch\Plugin;

// Perform a search
$results = Plugin::getInstance()->search->search('your search query', [
    'sectionIds' => [1, 2, 3],
    'siteId' => Craft::$app->sites->currentSite->id,
    'fuzzy' => true,
    'fuzzyDistance' => 2,
    'limit' => 20,
    'offset' => 0,
    'orderBy' => 'score',
    'orderDir' => 'desc'
]);
```

## ⚙️ Performance Tuning

### Storage Driver Selection

Choose the right storage driver based on your site's needs:

| Driver | Best For | Pros | Cons |
|--------|----------|------|------|
| **Craft Cache** | Small to medium sites | Easy setup, no additional dependencies | Limited persistence, less scalable |
| **MySQL** | Medium to large sites | Persistent storage, good performance | Uses database resources |
| **Redis** | Large sites with high traffic | Fastest performance, dedicated caching | Requires Redis server setup |

### Memory Optimization Tips

1. 📦 **Optimize Chunk Size**: Adjust `chunkSize` for your specific content volume
2. 🔢 **Adjust Number of Chunks**: Set `numChunks` based on your site's size (fewer for small sites, more for large sites)

### Search Accuracy Optimization

1. 📝 **Enable Stemming**: Set `applyStemming = true` for better word form matching
2. 🚫 **Remove Stop Words**: Set `removeStopWords = true` to ignore common words
3. 📊 **TF-IDF Scoring**: Enable `useTfIdf = true` for better relevance ranking

## 🛠️ Command Line Tools

### Index Management

| Command | Description |
|---------|-------------|
| `./craft bramble-search/index/rebuild` | Rebuild the entire search index |
| `./craft bramble-search/index/clear` | Clear the search index |

#### Command Options

```bash
# Rebuild with specific site ID
./craft bramble-search/index/rebuild --siteId=1

# Rebuild with custom batch size
./craft bramble-search/index/rebuild --batchSize=200
```

### Testing & Debugging

| Command | Description |
|---------|-------------|
| `./craft bramble-search/test/search` | Test search functionality |
| `./craft bramble-search/test/stats` | View index statistics |
| `./craft bramble-search/test/chunks` | View index chunks |
| `./craft bramble-search/test/storage` | Test storage drivers |
| `./craft bramble-search/test/benchmark` | Benchmark search performance |
| `./craft bramble-search/debug/entry` | Debug entry tokenization |
| `./craft bramble-search/debug/token` | Debug token search |
| `./craft bramble-search/debug/stem` | Debug stemming |

#### Command Options

```bash
# Test search with options
./craft bramble-search/test/search --query="your search" --fuzzy=1 --limit=20

# View a specific chunk
./craft bramble-search/test/chunks a

# Test a specific storage driver
./craft bramble-search/test/storage --driver=redis

# Benchmark search performance
./craft bramble-search/test/benchmark --query="your search" --iterations=20

# Debug entry tokenization
./craft bramble-search/debug/entry --entryId=123 --stemming=1 --stopWords=1

# Debug token search
./craft bramble-search/debug/token --token=example

# Debug stemming
./craft bramble-search/debug/stem running
```

## 🔄 Automatic Indexing

Bramble Search automatically indexes entries when they are:
- Created
- Updated
- Deleted

## 🎛️ Craft Search Service Integration

Bramble Search can replace Craft's built-in search service, providing enhanced search capabilities for both the admin Control Panel and frontend templates that use Craft's native search functionality.

To enable this feature, set the `replaceCraftSearch` setting to `true` in your `config/bramble-search.php` file:

```php
return [
    // Other settings...

    // Enable Craft search service replacement
    'replaceCraftSearch' => true,
];
```

### What This Affects

When you enable this setting, Bramble Search will:

- 🎛️ **Replace Admin CP Search** - All searches in the Craft Control Panel will use Bramble Search
- 🌐 **Replace Frontend Element Queries** - Any `craft.entries.search()` queries in your templates will use Bramble Search
- 🔄 **Handle All Native Search Operations** - Any code that uses Craft's search service will automatically use Bramble Search

> ⚠️ **Important**: Before enabling this feature, make sure you have built your search index using the `./craft bramble-search/index/rebuild` command. Without a properly built index, search functionality may not work correctly.

## 📄 License

Bramble Search is licensed under a proprietary license. See the LICENSE.md file for details.
