<?php

declare(strict_types=1);

namespace MadeByBrambleTest\BrambleSearch;

use Craft;
use MadeByBramble\BrambleSearch\models\Settings;
use MadeByBramble\BrambleSearch\Plugin;
use PHPUnit\Framework\TestCase;

final class StopWordsSettingsTest extends TestCase
{
    private mixed $originalPlugin = null;

    public static function setUpBeforeClass(): void
    {
        Craft::setAlias('@bramble_search', dirname(__DIR__) . '/src');
    }

    protected function setUp(): void
    {
        $this->originalPlugin = Plugin::$plugin;
    }

    protected function tearDown(): void
    {
        Plugin::$plugin = $this->originalPlugin;
    }

    public function testNormalizeStopWordListFromString(): void
    {
        self::assertSame(
            ['back', 'pain', 'relief'],
            Settings::normalizeStopWordList(" Back \n pain, relief \n")
        );
    }

    public function testMergeStopWordListsAddsAndRemoves(): void
    {
        $merged = Settings::mergeStopWordLists(['back', 'the', 'pain'], ['custom'], ['back']);

        self::assertContains('the', $merged);
        self::assertContains('pain', $merged);
        self::assertContains('custom', $merged);
        self::assertNotContains('back', $merged);
    }

    public function testDefaultAdapterLoadIncludesBundledStopWordThe(): void
    {
        Plugin::$plugin = null;

        $adapter = new InMemorySearchAdapter();
        $adapter->init();

        self::assertContains('the', $adapter->publicStopWords());
    }

    public function testRemoveStopWordsSettingDropsTheFromAdapter(): void
    {
        $settings = new Settings();
        $settings->setRemoveStopWords(['the']);

        $plugin = $this->createMock(Plugin::class);
        $plugin->method('getSettings')->willReturn($settings);
        Plugin::$plugin = $plugin;

        $adapter = new InMemorySearchAdapter();
        $adapter->init();

        self::assertNotContains('the', $adapter->publicStopWords());
        self::assertContains('of', $adapter->publicStopWords());
        self::assertSame(['the', 'widget'], $adapter->publicFilterStopWords(['the', 'widget', 'of']));
    }

    public function testExtraStopWordsSettingAddsCustomToken(): void
    {
        $settings = new Settings();
        $settings->setExtraStopWords(['therapyorganics']);

        $plugin = $this->createMock(Plugin::class);
        $plugin->method('getSettings')->willReturn($settings);
        Plugin::$plugin = $plugin;

        $adapter = new InMemorySearchAdapter();
        $adapter->init();

        self::assertContains('therapyorganics', $adapter->publicStopWords());
        self::assertSame([], $adapter->publicFilterStopWords(['therapyorganics']));
    }
}
