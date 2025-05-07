<?php

/**
 * Bramble Search plugin for Craft CMS 5.x
 *
 * A powerful search engine with inverted index, fuzzy search, BM25 scoring and multiple storage backends
 *
 * @link      https://madebybramble.co.uk
 * @copyright Copyright (c) 2025 Made By Bramble
 */

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
 * Class Plugin
 *
 * @author    Made By Bramble
 * @package   BrambleSearch
 * @since     1.0.0
 *
 */
class Plugin extends BasePlugin
{
    /**
     * @var Plugin
     */
    public static $plugin;

    /**
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @var bool
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritdoc
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
                // Check for environment variable overrides first, then fall back to settings
                $redisHost = App::parseEnv('BRAMBLE_SEARCH_REDIS_HOST') ? $settings->redisHost : 'localhost';
                $redisPort = (int)(App::parseEnv('BRAMBLE_SEARCH_REDIS_PORT') ? $settings->redisPort : 6379);
                $redisPassword = App::parseEnv('BRAMBLE_SEARCH_REDIS_PASSWORD') ? $settings->redisPassword : null;

                Craft::$app->set('search', new RedisSearchAdapter([
                    'host' => $redisHost,
                    'port' => $redisPort,
                    'password' => $redisPassword
                ]));
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

    public function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
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
