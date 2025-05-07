<?php

namespace MadeByBramble\BrambleSearch;

use Craft;
use craft\base\Plugin as BasePlugin;
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

            // Check for environment variable overrides first, then fall back to settings
            $storageDriver = App::parseEnv('BRAMBLE_SEARCH_DRIVER') ? $settings->storageDriver : 'craft';

            if ($storageDriver === 'redis') {
                Craft::$app->set('search', new RedisSearchAdapter());
            } else {
                Craft::$app->set('search', new CraftCacheSearchAdapter());
            }
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
