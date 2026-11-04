<?php

namespace MadeByBramble\BrambleSearch;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\elements\db\ElementQuery;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterElementSearchableAttributesEvent;
use craft\helpers\App;
use craft\search\SearchQuery;
use craft\utilities\ClearCaches;

use MadeByBramble\BrambleSearch\adapters\BaseSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\CraftCacheSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\FileSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\MongoDbSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\MySqlSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\RedisSearchAdapter;
use MadeByBramble\BrambleSearch\helpers\RebuildState;
use MadeByBramble\BrambleSearch\jobs\FreshSearchIndexJob;
use MadeByBramble\BrambleSearch\models\Settings;
use yii\base\Event;
use yii\base\ModelEvent;

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
     * @var self|null
     */
    public static $plugin;

    /**
     * Schema version for database migrations.
     * This is used by Craft's migration system to determine if migrations need to be run.
     *
     * @var string
     */
    public string $schemaVersion = '1.0.5';

    /**
     * Indicates that the plugin has Control Panel settings.
     * When true, a settings icon will appear in the Control Panel plugins page.
     *
     * @var bool
     */
    public bool $hasCpSettings = true;

    private ?\yii\mutex\Mutex $indexMutex = null;

    public function getIndexMutex(): \yii\mutex\Mutex
    {
        return $this->indexMutex ??= Craft::createObject(App::dbMutexConfig());
    }

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

        /** @var \MadeByBramble\BrambleSearch\models\Settings $settings */
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
        /** @var \MadeByBramble\BrambleSearch\models\Settings $settings */
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
     * - Native lifecycle indexing and element deletion
     * - Raw zero search compatibility during query preparation
     *
     * @return void
     */
    protected function registerEventHandlers(): void
    {
        // Register cache clearing option
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function(RegisterCacheOptionsEvent $event) {
                $options = $event->options;
                $options['bramble-search'] = [
                    'label' => 'Bramble Search',
                    'key' => 'bramble-search',
                    'info' => Craft::t('bramble-search', 'Triggers a queued rolling rebuild of the search index.'),
                    'action' => function() {
                        if (!(Craft::$app->getSearch() instanceof BaseSearchAdapter)) {
                            throw new \RuntimeException('Bramble Search is not active as the Craft search service.');
                        }

                        foreach (Craft::$app->getSites()->getAllSites() as $site) {
                            $this->queueRebuildIndexJob($site->id);
                        }
                    },
                ];
                $event->options = $options;
            }
        );

        Event::on(
            \yii\queue\Queue::class,
            \yii\queue\Queue::EVENT_BEFORE_EXEC,
            function(\yii\queue\ExecEvent $event): void {
                if ($event->job instanceof \craft\queue\jobs\UpdateSearchIndex
                    && get_class($event->job) === \craft\queue\jobs\UpdateSearchIndex::class
                    && Craft::$app->getSearch() instanceof BaseSearchAdapter) {
                    $event->job = new FreshSearchIndexJob(get_object_vars($event->job));
                }
            }
        );

        Event::on(
            ElementQuery::class,
            ElementQuery::EVENT_BEFORE_PREPARE,
            [$this, 'handleZeroSearchQuery']
        );

        Event::on(
            Element::class,
            Element::EVENT_REGISTER_SEARCHABLE_ATTRIBUTES,
            [$this, 'handleSearchableAttributes']
        );

        Event::on(
            Element::class,
            Element::EVENT_AFTER_SAVE,
            [$this, 'handleElementAfterSave']
        );

        Craft::$app->getElements()->on(
            \craft\services\Elements::EVENT_AFTER_DELETE_ELEMENT,
            [$this, 'handleElementDelete']
        );

        Craft::$app->getElements()->on(
            \craft\services\Elements::EVENT_AFTER_DELETE_FOR_SITE,
            [$this, 'handleElementDelete']
        );

        Craft::$app->getElements()->on(
            \craft\services\Elements::EVENT_AFTER_RESTORE_ELEMENT,
            [$this, 'handleElementRestore']
        );

        $this->registerCommerceEventHandlers();
    }

    /**
     * Register Commerce-specific cleanup hooks when Commerce is installed.
     */
    protected function registerCommerceEventHandlers(): void
    {
        if (!class_exists(\craft\commerce\services\Carts::class)) {
            return;
        }

        Event::on(
            \craft\commerce\services\Carts::class,
            \craft\commerce\services\Carts::EVENT_BEFORE_PURGE_INACTIVE_CARTS,
            function(\craft\commerce\events\CartPurgeEvent $event): void {
                $searchService = Craft::$app->getSearch();
                if (!($searchService instanceof BaseSearchAdapter)) {
                    return;
                }

                $cartIds = (clone $event->inactiveCartsQuery)->column();
                foreach ($cartIds as $cartId) {
                    $order = \craft\commerce\elements\Order::find()
                        ->id((int)$cartId)
                        ->siteId('*')
                        ->status(null)
                        ->one();
                    if ($order?->id && $order->siteId) {
                        $searchService->deleteElementIndex($order);
                    }
                }
            }
        );
    }

    /**
     * Queues a rebuild job unless one is already waiting or running for the site.
     *
     * @param int $siteId
     * @return void
     */
    protected function queueRebuildIndexJob(int $siteId): void
    {
        $adapter = Craft::$app->getSearch();
        if (!($adapter instanceof BaseSearchAdapter)) {
            throw new \RuntimeException('Bramble Search is not the active search service.');
        }
        $queued = RebuildState::enqueue($adapter, $siteId);
        $request = Craft::$app->getRequest();
        if (!$queued && $request instanceof \craft\web\Request && $request->getIsCpRequest()) {
            Craft::$app->getSession()->setNotice(Craft::t('bramble-search', 'Bramble Search rebuild is already queued or running.'));
        }
    }
    public function handleZeroSearchQuery(Event $event): void
    {
        if ($event->sender->search === '0' && Craft::$app->getSearch() instanceof BaseSearchAdapter) {
            $event->sender->search(new SearchQuery('0'));
        }
    }

    public function handleSearchableAttributes(RegisterElementSearchableAttributesEvent $event): void
    {
        if (!(Craft::$app->getSearch() instanceof BaseSearchAdapter)) {
            return;
        }

        $event->attributes = array_values(array_unique([...$event->attributes, 'enabled', 'enabledForSite']));
    }

    /** Keeps first publication on Craft's ordinary suppressed/coalesced scheduling path. */
    public function handleElementAfterSave(ModelEvent $event): void
    {
        if (!(Craft::$app->getSearch() instanceof BaseSearchAdapter)) {
            return;
        }

        $element = $event->sender;
        if (!($element instanceof Element) || (!$element->firstSave && !$element->isNewForSite)) {
            return;
        }

        $element->setDirtyAttributes(['slug'], true);
    }

    /**
     * Handles element deletion events so Bramble Search does not retain stale documents.
     *
     * @param \craft\events\ElementEvent $event The element event
     * @return void
     */
    public function handleElementDelete(\craft\events\ElementEvent $event): void
    {
        $element = $event->element;

        if (!$element->id || !$element->siteId) {
            return;
        }

        $searchService = Craft::$app->getSearch();
        if (!($searchService instanceof BaseSearchAdapter)) {
            return;
        }

        if ($event->name === \craft\services\Elements::EVENT_AFTER_DELETE_ELEMENT && $element::isLocalized()) {
            foreach (array_unique([...Craft::$app->getSites()->getAllSiteIds(), $element->siteId]) as $siteId) {
                if (!$searchService->deleteElementFromIndex($element->id, (int)$siteId)) {
                    throw new \RuntimeException('Unable to remove a deleted element from search.');
                }
            }
        } elseif (!$searchService->deleteElementIndex($element)) {
            throw new \RuntimeException('Unable to remove a deleted element from search.');
        }
    }

    public function handleElementRestore(\craft\events\ElementEvent $event): void
    {
        $adapter = Craft::$app->getSearch();
        $element = $event->element;
        if (!($adapter instanceof BaseSearchAdapter) || !$element->id || !$element->siteId) {
            return;
        }
        $elements = [$element];
        if ($element::isLocalized()) {
            $others = $element::find()->id($element->id)->siteId('*')->status(null)->cache(false)->all();
            foreach ($others as $localized) {
                if ($localized->siteId !== $element->siteId) {
                    $elements[] = $localized;
                }
            }
        }
        foreach ($elements as $restored) {
            if (!$adapter->indexElementAttributes($restored)) {
                throw new \RuntimeException('Unable to restore the element search index.');
            }
        }
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
