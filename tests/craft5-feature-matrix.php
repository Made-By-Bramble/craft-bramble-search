<?php

declare(strict_types=1);

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\enums\PropagationMethod;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Addresses;
use craft\fields\Assets;
use craft\fields\ButtonGroup;
use craft\fields\Categories;
use craft\fields\Checkboxes;
use craft\fields\Color;
use craft\fields\ContentBlock;
use craft\fields\Country;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Email;
use craft\fields\Entries;
use craft\fields\Icon;
use craft\fields\Json;
use craft\fields\Lightswitch;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\MissingField;
use craft\fields\Money;
use craft\fields\MultiSelect;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\RadioButtons;
use craft\fields\Range;
use craft\fields\Table;
use craft\fields\Tags;
use craft\fields\Time;
use craft\fields\Url;
use craft\fields\Users;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\OptionData;
use craft\fields\data\SingleOptionFieldData;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use MadeByBramble\BrambleSearch\adapters\BaseSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\CraftCacheSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\FileSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\MongoDbSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\MySqlSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\RedisSearchAdapter;
use MadeByBramble\BrambleSearch\console\controllers\StatsController;
use MadeByBramble\BrambleSearch\Plugin;

require __DIR__ . '/bootstrap.php';

final class MatrixFailure extends RuntimeException
{
}

final class MatrixFieldCoverageEntry extends Entry
{
    public ?FieldLayout $matrixFieldLayout = null;
    public array $matrixFieldValues = [];

    public function getFieldLayout(): ?FieldLayout
    {
        return $this->matrixFieldLayout;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        return $this->matrixFieldValues[$fieldHandle] ?? null;
    }
}

function matrix_pass(string $message, array $context = []): void
{
    echo '[PASS] ' . $message;
    if ($context !== []) {
        echo ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
    echo PHP_EOL;
}

function matrix_assert(bool $condition, string $message, array $context = []): void
{
    if (!$condition) {
        throw new MatrixFailure($message . ($context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES)));
    }
}

function matrix_reflect(object $object, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionClass($object);
    $method = $reflection->getMethod($method);
    $method->setAccessible(true);

    return $method->invoke($object, ...$args);
}

function matrix_first_test_section(): Section
{
    $section = Craft::$app->getEntries()->getSectionByHandle('test');
    if (!$section) {
        $sections = Craft::$app->getEntries()->getAllSections();
        $section = $sections[0] ?? null;
    }

    matrix_assert($section instanceof Section, 'No section is available for entry fixtures');
    matrix_assert($section->type !== Section::TYPE_SINGLE, 'Fixture section must support multiple entries', [
        'section' => $section->handle,
    ]);

    return $section;
}

function matrix_ensure_second_site(Section $section): Site
{
    $sites = Craft::$app->getSites();
    $site = $sites->getSiteByHandle('brambleSearchFr', true);

    if (!$site) {
        $primary = $sites->getPrimarySite();
        $site = new Site();
        $site->groupId = $primary->groupId;
        $site->handle = 'brambleSearchFr';
        $site->setName('Bramble Search FR');
        $site->setLanguage('fr-FR');
        $site->setBaseUrl('@web/fr/');
        $site->hasUrls = false;
        $site->primary = false;
        $site->setEnabled(true);

        matrix_assert($sites->saveSite($site), 'Failed to create secondary Craft 5 test site', $site->getErrors());
        $sites->refreshSites();
        $site = $sites->getSiteByHandle('brambleSearchFr', true);
    }

    matrix_assert($site instanceof Site, 'Secondary Craft 5 test site is not available');

    $siteSettings = $section->getSiteSettings();
    if (!isset($siteSettings[$site->id])) {
        $siteSettings[$site->id] = new Section_SiteSettings([
            'sectionId' => $section->id,
            'siteId' => $site->id,
            'enabledByDefault' => true,
            'hasUrls' => false,
        ]);
        $section->setSiteSettings(array_values($siteSettings));
        $section->propagationMethod = PropagationMethod::All;
        matrix_assert(Craft::$app->getEntries()->saveSection($section, false), 'Failed to enable fixture section for secondary site', $section->getErrors());
    }

    return $site;
}

function matrix_create_entry(Section $section, int $siteId, string $title, ?string $slug = null): Entry
{
    $entryTypes = $section->getEntryTypes();
    $entryType = $entryTypes[0] ?? null;
    matrix_assert($entryType !== null, 'Fixture section has no entry type', ['section' => $section->handle]);

    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $entryType->id;
    $entry->siteId = $siteId;
    $entry->title = $title;
    $entry->slug = $slug ?? StringHelper::slugify($title);
    $entry->enabled = true;
    $entry->enabledForSite = true;

    matrix_assert(Craft::$app->getElements()->saveElement($entry, false, false, false), 'Failed to save fixture entry', [
        'title' => $title,
        'errors' => $entry->getErrors(),
    ]);

    return $entry;
}

function matrix_adapter(string $driver, string $token): BaseSearchAdapter
{
    putenv('BRAMBLE_SEARCH_REDIS_HOST=redis');
    putenv('BRAMBLE_SEARCH_REDIS_PORT=6379');
    putenv("BRAMBLE_SEARCH_REDIS_KEY_PREFIX=bramble_search_test_$token:");
    putenv('BRAMBLE_SEARCH_MONGODB_URI=mongodb://mongodb:27017');
    putenv("BRAMBLE_SEARCH_MONGODB_DATABASE=bramble_search_test_$token");

    if ($driver === 'mongodb') {
        matrix_register_mongodb_library();
    }

    return match ($driver) {
        'mysql' => new MySqlSearchAdapter(),
        'file' => new FileSearchAdapter(),
        'craft' => new CraftCacheSearchAdapter(),
        'redis' => new RedisSearchAdapter(),
        'mongodb' => new MongoDbSearchAdapter(),
        default => throw new InvalidArgumentException("Unsupported driver: $driver"),
    };
}

function matrix_register_mongodb_library(): void
{
    static $registered = false;

    if ($registered || class_exists(MongoDB\Client::class)) {
        return;
    }

    $vendor = dirname(__DIR__) . '/vendor';
    $mongodbSrc = $vendor . '/mongodb/mongodb/src';

    matrix_assert(is_dir($mongodbSrc), 'MongoDB Composer library is not installed in plugin vendor');

    $polyfill = $vendor . '/symfony/polyfill-php85/bootstrap.php';
    if (is_file($polyfill)) {
        require_once $polyfill;
    }

    require_once $mongodbSrc . '/functions.php';

    spl_autoload_register(function(string $class) use ($mongodbSrc): void {
        $prefix = 'MongoDB\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = $mongodbSrc . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });

    $registered = true;
}

function matrix_search_ids(BaseSearchAdapter $adapter, string $term, int $siteId): array
{
    $query = Entry::find()
        ->siteId($siteId)
        ->status(null)
        ->search($term);

    return array_keys($adapter->searchElements($query));
}

function matrix_standard_field_classes(): array
{
    $classes = [
        Addresses::class,
        Assets::class,
        ButtonGroup::class,
        Categories::class,
        Checkboxes::class,
        Color::class,
        ContentBlock::class,
        Country::class,
        Date::class,
        Dropdown::class,
        Email::class,
        Entries::class,
        Icon::class,
        Json::class,
        Lightswitch::class,
        Link::class,
        Matrix::class,
        Money::class,
        MultiSelect::class,
        Number::class,
        PlainText::class,
        RadioButtons::class,
        Range::class,
        Table::class,
        Tags::class,
        Time::class,
        Url::class,
        Users::class,
    ];

    $coreClasses = [];
    foreach (glob(Craft::getAlias('@craft/fields') . '/*.php') ?: [] as $file) {
        $class = 'craft\\fields\\' . pathinfo($file, PATHINFO_FILENAME);
        if ($class === MissingField::class || !class_exists($class) || !is_a($class, FieldInterface::class, true)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if (!$reflection->isAbstract()) {
            $coreClasses[] = $class;
        }
    }

    sort($classes);
    sort($coreClasses);
    matrix_assert($classes === $coreClasses, 'Standard Craft field coverage list is out of sync', [
        'missing' => array_values(array_diff($coreClasses, $classes)),
        'extra' => array_values(array_diff($classes, $coreClasses)),
    ]);

    return $classes;
}

function matrix_core_fields(string $token, Entry $relatedEntry, int $siteId): array
{
    $classes = matrix_standard_field_classes();
    $fields = [];
    $values = [];

    foreach ($classes as $i => $class) {
        $handle = 'bs' . strtolower((new ReflectionClass($class))->getShortName());
        $term = strtolower($handle . $token);

        /** @var FieldInterface $field */
        $field = new $class([
            'name' => $handle,
            'handle' => $handle,
            'uid' => StringHelper::UUID(),
            'searchable' => true,
        ]);

        if (property_exists($field, 'options')) {
            $field->options = [
                ['label' => $term, 'value' => $term, 'default' => false],
            ];
        }
        if ($class === Table::class) {
            $field->columns = [
                'col1' => [
                    'heading' => 'Search',
                    'handle' => 'search',
                    'type' => 'singleline',
                ],
            ];
        }

        $fields[] = $field;
        if (in_array($class, [ButtonGroup::class, Dropdown::class, RadioButtons::class], true)) {
            $option = new SingleOptionFieldData($term, $term, true);
            $option->setOptions([$option]);
            $value = $option;
        } elseif (in_array($class, [Checkboxes::class, MultiSelect::class], true)) {
            $option = new OptionData($term, $term, true);
            $value = new MultiOptionsFieldData([$option]);
            $value->setOptions([$option]);
        } else {
            $value = match ($class) {
            Assets::class => Asset::find()->status(null)->limit(1),
            Categories::class => Category::find()->status(null)->limit(1),
            Entries::class => Entry::find()->siteId($siteId)->id($relatedEntry->id)->status(null),
            Tags::class => Tag::find()->limit(1),
            Users::class => User::find()->status(null)->limit(1),
            Table::class => [['col1' => $term]],
            ContentBlock::class, Matrix::class => [],
            Lightswitch::class => true,
            default => $term,
            };
        }

        $values[$handle] = $value;
    }

    return [$fields, $values];
}

function matrix_field_layout(array $fields): FieldLayout
{
    $layout = new FieldLayout(['type' => MatrixFieldCoverageEntry::class]);
    $elements = array_map(fn(FieldInterface $field): CustomField => new CustomField($field), $fields);
    $tab = new FieldLayoutTab(['name' => 'Searchable Fields']);
    $tab->setLayout($layout);
    $tab->setElements($elements);
    $layout->setTabs([$tab]);

    return $layout;
}

function matrix_run_driver(string $driver, string $token, array $entriesBySite, int $site1, int $site2): void
{
    $adapter = matrix_adapter($driver, $token);
    Craft::$app->set('search', $adapter);

    $adapter->clearIndex($site1);
    $adapter->clearIndex($site2);

    foreach ([$site1, $site2] as $siteId) {
        foreach ($entriesBySite[$siteId] as $entry) {
            $adapter->indexElementAttributes($entry, null);
        }
    }

    $exact = matrix_search_ids($adapter, 'lavender', $site1);
    matrix_assert(in_array($entriesBySite[$site1]['lavender']->id . "-$site1", $exact, true), "$driver exact search failed", ['ids' => $exact]);

    $multi = matrix_search_ids($adapter, 'orchid velocity', $site1);
    matrix_assert($multi === [$entriesBySite[$site1]['orchid']->id . "-$site1"], "$driver multi-term AND search failed", ['ids' => $multi]);

    $fuzzy = matrix_search_ids($adapter, 'lavendr', $site1);
    matrix_assert(in_array($entriesBySite[$site1]['lavender']->id . "-$site1", $fuzzy, true), "$driver fuzzy search failed", ['ids' => $fuzzy]);

    $exactTypoDocs = matrix_reflect($adapter, 'getTermDocuments', 'antibioti');
    matrix_assert(
        isset($exactTypoDocs[$site1 . ':' . $entriesBySite[$site1]['antibioti']->id])
            && isset($exactTypoDocs[$site2 . ':' . $entriesBySite[$site2]['antibioti']->id])
            && !isset($exactTypoDocs[$site1 . ':' . $entriesBySite[$site1]['antibiotic']->id]),
        "$driver fuzzy fixture did not create the expected exact typo terms",
        ['docs' => $exactTypoDocs]
    );

    $fuzzyWithExactTypo = matrix_search_ids($adapter, 'antibioti', $site1);
    matrix_assert(
        in_array($entriesBySite[$site1]['antibiotic']->id . "-$site1", $fuzzyWithExactTypo, true)
            && in_array($entriesBySite[$site1]['antibioti']->id . "-$site1", $fuzzyWithExactTypo, true),
        "$driver fuzzy search skipped close matches when an exact typo existed on the same site",
        ['ids' => $fuzzyWithExactTypo]
    );

    $whyTerms = matrix_reflect($adapter, 'getDocumentTerms', $site1, $entriesBySite[$site1]['why']->id);
    $whyTitleTerms = matrix_reflect($adapter, 'getTitleTerms', $site1 . ':' . $entriesBySite[$site1]['why']->id);
    matrix_assert(isset($whyTerms['why']) && isset($whyTitleTerms['why']), "$driver title stop word was not indexed", [
        'terms' => $whyTerms,
        'titleTerms' => $whyTitleTerms,
    ]);

    $titleStopWord = matrix_search_ids($adapter, 'why', $site1);
    matrix_assert(
        $titleStopWord === [$entriesBySite[$site1]['why']->id . "-$site1"],
        "$driver title stop-word search failed",
        ['ids' => $titleStopWord]
    );

    $none = matrix_search_ids($adapter, 'zzzzzzzzzzzz', $site1);
    matrix_assert($none === [], "$driver no-result search failed", ['ids' => $none]);

    $site1Term = matrix_search_ids($adapter, 'velocity', $site1);
    $site2Wrong = matrix_search_ids($adapter, 'velocity', $site2);
    $site2Term = matrix_search_ids($adapter, 'vitesse', $site2);
    matrix_assert($site1Term === [$entriesBySite[$site1]['orchid']->id . "-$site1"], "$driver site 1 term search failed", ['ids' => $site1Term]);
    matrix_assert($site2Wrong === [], "$driver leaked site 1 term into site 2", ['ids' => $site2Wrong]);
    matrix_assert($site2Term === [$entriesBySite[$site2]['orchid']->id . "-$site2"], "$driver site 2 localized term search failed", ['ids' => $site2Term]);

    $pageTerm = 'pageprobe';
    $count = (int)Entry::find()->siteId($site1)->status(null)->search($pageTerm)->count();
    $page1 = Entry::find()->siteId($site1)->status(null)->search($pageTerm)->limit(2)->ids();
    $page2 = Entry::find()->siteId($site1)->status(null)->search($pageTerm)->limit(2)->offset(2)->ids();
    $page3 = Entry::find()->siteId($site1)->status(null)->search($pageTerm)->limit(2)->offset(4)->ids();

    matrix_assert($count === 4, "$driver paginated count failed", ['count' => $count]);
    matrix_assert(count($page1) === 2 && count($page2) === 2 && $page3 === [], "$driver pagination windows failed", [
        'page1' => $page1,
        'page2' => $page2,
        'page3' => $page3,
    ]);

    $deleteTarget = $entriesBySite[$site1]['delete'];
    $adapter->deleteElementFromIndex($deleteTarget->id, $site1);
    matrix_assert(matrix_search_ids($adapter, 'deleteprobe', $site1) === [], "$driver delete from index failed");
    $adapter->indexElementAttributes($deleteTarget, null);
    matrix_assert(matrix_search_ids($adapter, 'deleteprobe', $site1) === [$deleteTarget->id . "-$site1"], "$driver re-index after delete failed");

    [$fields, $values] = matrix_core_fields($token, $entriesBySite[$site1]['orchid'], $site1);
    $fieldEntry = new MatrixFieldCoverageEntry();
    $fieldEntry->id = 880000 + crc32($driver) % 10000;
    $fieldEntry->siteId = $site1;
    $fieldEntry->enabled = true;
    $fieldEntry->title = "Field Coverage $driver $token";
    $fieldEntry->matrixFieldLayout = matrix_field_layout($fields);
    $fieldEntry->matrixFieldValues = $values;

    $adapter->indexElementAttributes($fieldEntry, array_keys($values));
    $fieldTerms = matrix_reflect($adapter, 'getDocumentTerms', $site1, $fieldEntry->id);
    $missingTerms = [];
    $keywordFields = [];
    $emptyKeywordFields = [];
    foreach ($fields as $field) {
        $handle = $field->handle;
        $value = $values[$handle] ?? null;
        $tokens = [];

        if ($value) {
            $keywords = $field->getSearchKeywords($value, $fieldEntry);
            $tokens = matrix_reflect($adapter, 'tokenize', $keywords);
            $tokens = matrix_reflect($adapter, 'filterStopWords', $tokens);
            $tokens = array_values(array_unique($tokens));
        }

        if ($tokens === []) {
            $emptyKeywordFields[] = $handle;
            continue;
        }

        $keywordFields[] = $handle;
        foreach ($tokens as $term) {
            if (!isset($fieldTerms[$term])) {
                $missingTerms[$handle][] = $term;
            }
        }
    }
    matrix_assert($missingTerms === [], "$driver standard Craft field keyword indexing failed", ['missing' => $missingTerms]);

    $boostTitle = new MatrixFieldCoverageEntry();
    $boostTitle->id = 890000 + crc32($driver) % 10000;
    $boostTitle->siteId = $site1;
    $boostTitle->enabled = true;
    $boostTitle->title = "boostneedle$token";
    $boostTitle->matrixFieldLayout = matrix_field_layout([]);

    $boostField = new MatrixFieldCoverageEntry();
    $boostField->id = 891000 + crc32($driver) % 10000;
    $boostField->siteId = $site1;
    $boostField->enabled = true;
    $boostField->title = "Other $token";
    $boostField->matrixFieldLayout = matrix_field_layout([new PlainText([
        'name' => 'Boost Field',
        'handle' => 'boostField',
        'uid' => StringHelper::UUID(),
        'searchable' => true,
    ])]);
    $boostField->matrixFieldValues = ['boostField' => "boostneedle$token"];

    $adapter->indexElementAttributes($boostTitle, null);
    $adapter->indexElementAttributes($boostField, ['boostField']);
    $boostResults = matrix_search_ids($adapter, "boostneedle$token", $site1);
    matrix_assert($boostResults[0] === $boostTitle->id . "-$site1", "$driver title boost ordering failed", ['ids' => $boostResults]);

    matrix_pass("$driver driver matrix", [
        'exact' => $exact,
        'fuzzy' => $fuzzy,
        'fuzzyWithExactTypo' => $fuzzyWithExactTypo,
        'titleStopWord' => $titleStopWord,
        'count' => $count,
        'fields' => count($fields),
        'keywordFields' => count($keywordFields),
        'emptyKeywordFields' => count($emptyKeywordFields),
    ]);
}

function matrix_run_stats_command(Plugin $plugin, string $driver, string $token): void
{
    if ($driver === 'mongodb') {
        matrix_register_mongodb_library();
    }

    putenv('BRAMBLE_SEARCH_REDIS_HOST=redis');
    putenv('BRAMBLE_SEARCH_REDIS_PORT=6379');
    putenv("BRAMBLE_SEARCH_REDIS_KEY_PREFIX=bramble_search_test_$token:");
    putenv('BRAMBLE_SEARCH_MONGODB_URI=mongodb://mongodb:27017');
    putenv("BRAMBLE_SEARCH_MONGODB_DATABASE=bramble_search_test_$token");

    $controller = new StatsController('stats', $plugin);
    $controller->driver = $driver;

    $exitCode = $controller->actionIndex();

    matrix_assert($exitCode === 0, 'stats command failed', [
        'driver' => $driver,
        'exitCode' => $exitCode,
    ]);
}

$token = strtolower(bin2hex(random_bytes(4)));

try {
    $plugin = Craft::$app->getPlugins()->getPlugin('bramble-search');
    matrix_assert($plugin instanceof Plugin, 'Bramble Search plugin is not installed');
    matrix_assert(Craft::$app->getSearch() instanceof BaseSearchAdapter, 'Bramble Search is not active as Craft search service');
    matrix_pass('plugin discovery and search binding', ['adapter' => get_class(Craft::$app->getSearch())]);

    foreach (['documents', 'metadata', 'ngram_index', 'ngrams', 'terms', 'titles'] as $table) {
        matrix_assert(Craft::$app->getDb()->tableExists("{{%bramble_search_$table}}"), 'Missing Bramble Search table', ['table' => $table]);
    }
    matrix_pass('migration schema tables');

    $section = matrix_first_test_section();
    $site1 = Craft::$app->getSites()->getPrimarySite()->id;
    $site2 = matrix_ensure_second_site($section)->id;

    $entriesBySite = [
        $site1 => [
            'orchid' => matrix_create_entry($section, $site1, "Alpha Orchid Velocity Pageprobe $token"),
            'lavender' => matrix_create_entry($section, $site1, "Beta Lavender Beacon Pageprobe $token"),
            'cedar' => matrix_create_entry($section, $site1, "Gamma Cedar Pageprobe $token"),
            'delete' => matrix_create_entry($section, $site1, "Delta Deleteprobe Pageprobe $token"),
            'antibiotic' => matrix_create_entry($section, $site1, 'Antibiotic Oil', "antibiotic-oil-$token"),
            'antibioti' => matrix_create_entry($section, $site1, "Antibioti Exact $token"),
            'why' => matrix_create_entry($section, $site1, "Why do you sell the supplements you sell? $token"),
        ],
        $site2 => [
            'orchid' => matrix_create_entry($section, $site2, "Alpha Orchid Vitesse Pageprobe $token"),
            'lavender' => matrix_create_entry($section, $site2, "Beta Lavande Balise Pageprobe $token"),
            'cedar' => matrix_create_entry($section, $site2, "Gamma Cedre Pageprobe $token"),
            'delete' => matrix_create_entry($section, $site2, "Delta Supprimer Pageprobe $token"),
            'antibioti' => matrix_create_entry($section, $site2, "Antibioti Exact $token"),
        ],
    ];
    matrix_pass('fixture entries', ['token' => $token, 'site1' => $site1, 'site2' => $site2]);

    foreach (['mysql', 'file', 'craft', 'redis', 'mongodb'] as $driver) {
        matrix_run_driver($driver, $token, $entriesBySite, $site1, $site2);
    }

    foreach (['mysql', 'file', 'craft', 'redis', 'mongodb'] as $driver) {
        matrix_run_stats_command($plugin, $driver, $token);
    }
    matrix_pass('stats command driver options');

    $mysql = matrix_adapter('mysql', $token);
    Craft::$app->set('search', $mysql);
    $mysql->clearIndex($site1);
    $queueEntry = matrix_create_entry($section, $site1, "Queueprobe Search $token");
    Craft::$app->getQueue()->push(new craft\queue\jobs\UpdateSearchIndex([
        'elementType' => Entry::class,
        'elementId' => $queueEntry->id,
        'siteId' => $site1,
        'fieldHandles' => null,
        'queued' => false,
    ]));
    Craft::$app->getQueue()->run(false);
    matrix_assert(matrix_search_ids($mysql, 'queueprobe', $site1) === [$queueEntry->id . "-$site1"], 'queue indexing failed');
    matrix_pass('queue indexing');

    $settingsHtml = Craft::$app->getView()->renderTemplate('bramble-search/_settings', [
        'settings' => $plugin->getSettings(),
    ]);
    matrix_assert(strlen($settingsHtml) > 1000, 'settings template rendered unexpectedly short', ['length' => strlen($settingsHtml)]);
    matrix_pass('settings template render', ['length' => strlen($settingsHtml)]);

    if (is_dir(Craft::getAlias('@runtime') . '/bramble-search-test-cleanup')) {
        FileHelper::removeDirectory(Craft::getAlias('@runtime') . '/bramble-search-test-cleanup');
    }

    matrix_pass('Craft 5 feature matrix complete', ['token' => $token]);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
