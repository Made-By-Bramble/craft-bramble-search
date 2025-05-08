<?php

namespace MadeByBramble\BrambleSearch;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\controllers\ElementIndexesController;
use MadeByBramble\BrambleSearch\adapters\BaseSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\RedisSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\CraftCacheSearchAdapter;
use MadeByBramble\BrambleSearch\models\Settings;
use MadeByBramble\BrambleSearch\jobs\RebuildIndexJob;
use yii\base\Event;
use craft\utilities\ClearCaches;
use craft\events\RegisterCacheOptionsEvent;
use craft\console\Application as ConsoleApplication;
use craft\helpers\App;

/**
 * Bramble Search Plugin
 *
 * A powerful search engine for Craft CMS 5.x with inverted index, fuzzy search,
 * BM25 scoring and multiple storage backends (Craft Cache, Redis).
 */
class Plugin extends BasePlugin
{
    /**
     * Plugin instance reference
     */
    public static $plugin;

    /**
     * Schema version for database migrations
     */
    public string $schemaVersion = '1.0.0';

    /**
     * Indicates plugin has Control Panel settings
     */
    public bool $hasCpSettings = true;

    /**
     * Initialize the plugin
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
     * Initialize the search service with the appropriate adapter
     */
    protected function initializeSearchService(): void
    {
        $settings = $this->getSettings();

        // Check for environment variable overrides first, then fall back to settings
        $storageDriver = App::parseEnv('BRAMBLE_SEARCH_DRIVER') ? $settings->storageDriver : 'craft';

        if ($storageDriver === 'redis') {
            Craft::$app->set('search', new RedisSearchAdapter());
        } else {
            Craft::$app->set('search', new CraftCacheSearchAdapter());
        }
    }

    /**
     * Register event handlers for the plugin
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
    }

    /**
     * Handle the element count action to provide accurate search counts
     *
     * @param Event $event The event object
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
     * Get the search query from the request
     *
     * @return string|null The search query or null if not found
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
     * Get the element type from the request
     *
     * @return string|null The element type class or null if not found
     */
    protected function getElementTypeFromRequest(): ?string
    {
        $request = Craft::$app->getRequest();

        // Check direct elementType parameter
        $elementType = $request->getParam('elementType');
        if ($elementType) {
            return $elementType;
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
     * Create the settings model
     */
    public function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }

    /**
     * Render the settings template
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
}
