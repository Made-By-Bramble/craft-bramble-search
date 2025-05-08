<?php

namespace MadeByBramble\BrambleSearch;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\controllers\ElementIndexesController;
use craft\elements\db\ElementQuery;
use craft\events\RegisterCacheOptionsEvent;
use craft\helpers\App;
use craft\utilities\ClearCaches;

use MadeByBramble\BrambleSearch\adapters\BaseSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\CraftCacheSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\FileSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\MongoDbSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\MySqlSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\RedisSearchAdapter;
use MadeByBramble\BrambleSearch\jobs\RebuildIndexJob;
use MadeByBramble\BrambleSearch\models\Settings;
use yii\base\Event;

/**
 * Bramble Search Plugin
 *
 * A powerful search engine for Craft CMS 5.x that provides enhanced search capabilities
 * with features including:
 * - Inverted index for efficient search operations
 * - Fuzzy search with Levenshtein distance
 * - BM25 scoring algorithm for relevance ranking
 * - Multiple storage backends (Craft Cache, Redis, MySQL, MongoDB)
 * - Seamless integration with Craft's element queries
 * - Support for multi-site search
 */
class Plugin extends BasePlugin
{
    /**
     * Static reference to the plugin instance.
     * Used for accessing the plugin instance throughout the application.
     *
     * @var Plugin
     */
    public static $plugin;

    /**
     * Schema version for database migrations.
     * This is used by Craft's migration system to determine if migrations need to be run.
     *
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    /**
     * Indicates that the plugin has Control Panel settings.
     * When true, a settings icon will appear in the Control Panel plugins page.
     *
     * @var bool
     */
    public bool $hasCpSettings = true;

    /**
     * Initializes the plugin.
     *
     * This method is called after the plugin is instantiated by Craft.
     * It sets up logging, registers event handlers, and initializes the search service
     * if the plugin is enabled in settings.
     *
     * @return void
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;
        Craft::setAlias('@bramble_search', __DIR__);

        // Set up logging
        Craft::$app->getLog()->targets[] = new \yii\log\FileTarget([
            'logFile' => Craft::getAlias('@storage/logs/bramble-search.log'),
            'categories' => ['bramble-search'],
            'levels' => ['error', 'warning', 'info', 'trace'],
        ]);

        $settings = $this->getSettings();
        if ($settings->enabled === true) {
            // Register console commands
            if (Craft::$app instanceof ConsoleApplication) {
                $this->controllerNamespace = 'MadeByBramble\\BrambleSearch\\console\\controllers';
            } else {
                // Register our controllers for web requests
                $this->controllerNamespace = 'MadeByBramble\\BrambleSearch\\controllers';
            }

            // Initialize the search service
            $this->initializeSearchService();

            // Register event hooks
            $this->registerEventHandlers();
        }

        Craft::info(
            Craft::t(
                'bramble-search',
                '{name} plugin initialized',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    /**
     * Initializes the search service with the appropriate adapter.
     *
     * This method determines which storage driver to use based on environment
     * variables or plugin settings, then registers the appropriate search adapter
     * with Craft's service container.
     *
     * @return void
     */
    protected function initializeSearchService(): void
    {
        $settings = $this->getSettings();

        // Check for environment variable overrides first, then fall back to settings
        $storageDriver = App::parseEnv('$BRAMBLE_SEARCH_DRIVER') ?: $settings->storageDriver;

        try {
            switch ($storageDriver) {
                case 'redis':
                    if (!$this->isRedisAvailable()) {
                        throw new \Exception('Redis extension is not installed. Please install the PHP Redis extension to use the Redis storage adapter.');
                    }
                    Craft::$app->set('search', new RedisSearchAdapter());
                    break;
                case 'file':
                    Craft::$app->set('search', new FileSearchAdapter());
                    break;
                case 'mysql':
                    Craft::$app->set('search', new MySqlSearchAdapter());
                    break;
                case 'mongodb':
                    if (!$this->isMongoDbAvailable()) {
                        throw new \Exception('MongoDB extension is not installed. Please install the PHP MongoDB extension to use the MongoDB storage adapter.');
                    }
                    if (!class_exists('\\MongoDB\\Client')) {
                        throw new \Exception('MongoDB PHP library is not installed. Please run "composer require mongodb/mongodb:^1.15.0" to use the MongoDB storage adapter.');
                    }
                    Craft::$app->set('search', new MongoDbSearchAdapter());
                    break;
                default:
                    Craft::$app->set('search', new CraftCacheSearchAdapter());
                    break;
            }
        } catch (\Exception $e) {
            // Log the error
            Craft::error(
                'Failed to initialize search adapter: ' . $e->getMessage(),
                'bramble-search'
            );

            // Show a flash message in the CP
            if (Craft::$app->getRequest()->getIsCpRequest()) {
                Craft::$app->getSession()->setError('Bramble Search: ' . $e->getMessage());
            }

            // Fall back to Craft's default search service
            Craft::warning(
                'Falling back to Craft\'s default search service due to adapter initialization failure.',
                'bramble-search'
            );

            // Don't replace Craft's search service
            return;
        }

        Craft::info(
            "Initialized search adapter: $storageDriver",
            'bramble-search'
        );
    }

    /**
     * Registers all event handlers for the plugin.
     *
     * This method sets up event listeners for:
     * - Cache clearing options in the Control Panel
     * - Element index controller actions for accurate search counts
     * - Element query preparation to intercept search queries
     *
     * @return void
     */
    protected function registerEventHandlers(): void
    {
        // Register cache clearing option
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function (RegisterCacheOptionsEvent $event) {
                $options = $event->options;
                $options['bramble-search'] = [
                    'label' => 'Bramble Search',
                    'key' => 'bramble-search',
                    'info' => Craft::t('bramble-search', 'Triggers a queued rebuild of the search index.'),
                    'action' => function () {
                        Craft::$app->getQueue()->push(new RebuildIndexJob([
                            'siteId' => Craft::$app->getSites()->currentSite->id,
                        ]));
                    }
                ];
                $event->options = $options;
            }
        );

        // Hook into the element index controller to modify the count elements response
        Event::on(
            ElementIndexesController::class,
            ElementIndexesController::EVENT_AFTER_ACTION,
            [$this, 'handleElementCountAction']
        );

        // Hook into the ElementQuery's prepare method to intercept all element queries with search parameters
        Event::on(
            ElementQuery::class,
            ElementQuery::EVENT_BEFORE_PREPARE,
            [$this, 'handleElementQueryPrepare']
        );
    }

    /**
     * Handles the ElementQuery prepare event to intercept search queries for exports.
     *
     * This method intercepts ElementQuery objects with search parameters during export
     * operations and modifies them to use Bramble Search results instead of Craft's
     * default search functionality.
     *
     * @param Event $event The event object containing the ElementQuery
     * @return void
     */
    public function handleElementQueryPrepare(Event $event): void
    {
        /** @var ElementQuery $query */
        $query = $event->sender;

        // Check if this is a search query
        if (!empty($query->search)) {
            Craft::info(
                sprintf(
                    'Bramble Search: Intercepted ElementQuery with search: "%s"',
                    $query->search
                ),
                'bramble-search'
            );

            // Get the search service
            $searchService = Craft::$app->getSearch();

            // Check if it's one of our adapters
            if ($searchService instanceof BaseSearchAdapter) {
                // Get the current request
                $request = Craft::$app->getRequest();

                // Check if this is an export request
                $isExport = false;
                $path = $request->getPathInfo();
                if (
                    strpos($path, 'element-indexes/export') !== false ||
                    (strpos($path, 'actions/element-indexes/export') !== false)
                ) {
                    $isExport = true;
                }

                if ($isExport) {
                    Craft::info(
                        sprintf(
                            'Bramble Search: Modifying export query for search: "%s"',
                            $query->search
                        ),
                        'bramble-search'
                    );

                    // Perform the search using our adapter
                    $searchResults = $searchService->searchElements($query);

                    if (empty($searchResults)) {
                        // If no results, set an impossible condition to return no results
                        $query->id(0);
                    } else {
                        // Set the element IDs based on our search results
                        $elementIds = [];
                        foreach (array_keys($searchResults) as $key) {
                            [$elementId,] = explode('-', $key);
                            $elementIds[] = $elementId;
                        }

                        // Set the element IDs in the query and remove the search parameter
                        $query->id($elementIds);
                        $query->search = null;

                        Craft::info(
                            sprintf(
                                'Bramble Search: Modified export query with %d results',
                                count($searchResults)
                            ),
                            'bramble-search'
                        );
                    }
                }
            }
        }
    }

    /**
     * Handles the element count action to provide accurate search counts.
     *
     * This method intercepts the response from the ElementIndexesController's
     * countElements action and updates the count to reflect the actual number
     * of search results when using Bramble Search.
     *
     * @param Event $event The event object containing the controller and response
     * @return void
     */
    public function handleElementCountAction(Event $event): void
    {
        /** @var ElementIndexesController $controller */
        $controller = $event->sender;

        // Only intercept the countElements action
        if ($controller->action->id !== 'count-elements') {
            return;
        }

        // Get the response data
        $responseData = $controller->response->data;

        // Check if this is a search response
        if (!isset($responseData['total']) || !isset($responseData['resultSet'])) {
            return;
        }

        // Get the search query from the request
        $searchQuery = $this->getSearchQueryFromRequest();

        if (empty($searchQuery)) {
            return;
        }

        // Get the search service
        $searchService = Craft::$app->getSearch();

        // Check if it's one of our adapters
        if (!($searchService instanceof BaseSearchAdapter)) {
            return;
        }

        // Create a temporary element query to perform the search
        $elementType = $this->getElementTypeFromRequest();
        if (!$elementType) {
            return;
        }

        // Create a query to perform the search
        $query = $elementType::find();
        $query->search = $searchQuery;

        // Set the site ID
        $siteId = Craft::$app->getRequest()->getParam('siteId');
        if (!$siteId) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }
        $query->siteId = $siteId;

        // Get search results
        $searchResults = $searchService->searchElements($query);
        $total = count($searchResults);

        // Log the count update for debugging
        Craft::info(
            sprintf(
                'Bramble Search: Updating count from %d to %d for search: "%s"',
                $responseData['total'] ?? 0,
                $total,
                $searchQuery
            ),
            'bramble-search'
        );

        // Update the response data
        $controller->response->data = [
            'resultSet' => $responseData['resultSet'],
            'total' => $total,
            'unfilteredTotal' => $responseData['unfilteredTotal'] ?? $total,
        ];
    }

    /**
     * Extracts the search query from the current request.
     *
     * This method checks various locations where a search query might be found
     * in the request, including direct parameters, criteria arrays, and body parameters.
     *
     * @return string|null The search query string if found, null otherwise
     */
    protected function getSearchQueryFromRequest(): ?string
    {
        $request = Craft::$app->getRequest();

        // Check direct search parameter
        $searchQuery = $request->getParam('search');
        if (!empty($searchQuery)) {
            return $searchQuery;
        }

        // Check criteria parameter
        $criteria = $request->getParam('criteria');
        if (is_array($criteria) && isset($criteria['search'])) {
            return $criteria['search'];
        }

        // Check body parameters
        $bodyParams = $request->getBodyParams();
        if (isset($bodyParams['search'])) {
            return $bodyParams['search'];
        } elseif (isset($bodyParams['criteria']) && is_array($bodyParams['criteria']) && isset($bodyParams['criteria']['search'])) {
            return $bodyParams['criteria']['search'];
        }

        return null;
    }

    /**
     * Determines the element type class from the current request.
     *
     * This method attempts to extract the element type from request parameters,
     * body parameters, or context clues. If no element type can be determined,
     * it returns null.
     *
     * @return string|null The fully qualified element type class name if found, null otherwise
     */
    protected function getElementTypeFromRequest(): ?string
    {
        $request = Craft::$app->getRequest();

        // Check direct elementType parameter
        $elementType = $request->getParam('elementType');
        if ($elementType) {
            return $elementType;
        }

        // Check body params for elementType
        $bodyParams = $request->getBodyParams();
        if (isset($bodyParams['elementType'])) {
            return $bodyParams['elementType'];
        }

        // Try to determine element type from context
        $context = $request->getParam('context');
        if (strpos($context, 'index') !== false) {
            // Default to Entry for most common case
            return 'craft\\elements\\Entry';
        }

        return null;
    }

    /**
     * Creates and returns the settings model used by the plugin.
     *
     * @return \craft\base\Model|null The settings model
     */
    public function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }

    /**
     * Renders the settings template for the Control Panel.
     *
     * This method loads the current settings, validates them, and checks for
     * any config file overrides before rendering the settings template.
     *
     * @return string|null The rendered settings HTML
     */
    protected function settingsHtml(): ?string
    {
        $settings = $this->getSettings();
        $settings->validate();
        $overrides = Craft::$app->getConfig()->getConfigFromFile(strtolower($this->handle));

        return Craft::$app->view->renderTemplate('bramble-search/_settings', [
            'settings' => $settings,
            'overrides' => array_keys($overrides),
        ]);
    }

    /**
     * Checks if the Redis extension is available.
     *
     * @return bool Whether the Redis extension is available
     */
    public function isRedisAvailable(): bool
    {
        return extension_loaded('redis');
    }

    /**
     * Checks if the MongoDB extension and library are available.
     *
     * @return bool Whether the MongoDB extension and library are available
     */
    public function isMongoDbAvailable(): bool
    {
        return extension_loaded('mongodb') && class_exists('\\MongoDB\\Client');
    }
}
