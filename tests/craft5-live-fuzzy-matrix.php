<?php

declare(strict_types=1);

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\PlainText;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use MadeByBramble\BrambleSearch\adapters\BaseSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\CraftCacheSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\FileSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\MongoDbSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\MySqlSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\RedisSearchAdapter;

require __DIR__ . '/bootstrap.php';

final class LiveFuzzyFailure extends RuntimeException
{
}

function fuzzy_pass(string $message, array $context = []): void
{
    echo '[PASS] ' . $message;
    if ($context !== []) {
        echo ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
    echo PHP_EOL;
}

function fuzzy_assert(bool $condition, string $message, array $context = []): void
{
    if (!$condition) {
        throw new LiveFuzzyFailure($message . ($context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES)));
    }
}

function fuzzy_register_mongodb_library(): void
{
    static $registered = false;

    if ($registered || class_exists(MongoDB\Client::class)) {
        return;
    }

    $vendor = dirname(__DIR__) . '/vendor';
    $mongodbSrc = $vendor . '/mongodb/mongodb/src';

    fuzzy_assert(is_dir($mongodbSrc), 'MongoDB Composer library is not installed in plugin vendor');

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

function fuzzy_adapter(string $driver, string $token): BaseSearchAdapter
{
    putenv('BRAMBLE_SEARCH_REDIS_HOST=redis');
    putenv('BRAMBLE_SEARCH_REDIS_PORT=6379');
    putenv("BRAMBLE_SEARCH_REDIS_KEY_PREFIX=bramble_search_fuzzy_$token:");
    putenv('BRAMBLE_SEARCH_MONGODB_URI=mongodb://mongodb:27017');
    putenv("BRAMBLE_SEARCH_MONGODB_DATABASE=bramble_search_fuzzy_$token");

    if ($driver === 'mongodb') {
        fuzzy_register_mongodb_library();
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

function fuzzy_layout(array $fields): FieldLayout
{
    $layout = new FieldLayout([
        'type' => Entry::class,
        'uid' => StringHelper::UUID(),
    ]);
    $elements = [
        new EntryTitleField([
            'required' => true,
            'uid' => StringHelper::UUID(),
            'dateAdded' => new DateTime(),
        ]),
    ];

    foreach ($fields as $field) {
        $elements[] = new CustomField($field, [
            'uid' => StringHelper::UUID(),
            'dateAdded' => new DateTime(),
        ]);
    }

    $tab = new FieldLayoutTab([
        'name' => 'Bramble Search Live Fuzzy Fields',
        'uid' => StringHelper::UUID(),
    ]);
    $tab->setLayout($layout);
    $tab->setElements($elements);
    $layout->setTabs([$tab]);

    return $layout;
}

function fuzzy_save_field(FieldInterface $field): FieldInterface
{
    $existing = Craft::$app->getFields()->getFieldByHandle($field->handle);
    if ($existing) {
        fuzzy_assert(get_class($existing) === get_class($field), 'Existing fuzzy field handle has a different field class', [
            'handle' => $field->handle,
            'existing' => get_class($existing),
            'expected' => get_class($field),
        ]);
        $field->id = $existing->id;
        $field->uid = $existing->uid;
        $field->context = $existing->context;
        $field->columnSuffix = $existing->columnSuffix;
    }

    fuzzy_assert(Craft::$app->getFields()->saveField($field), 'Failed to save fuzzy field', [
        'handle' => $field->handle,
        'errors' => $field->getErrors(),
    ]);

    $saved = Craft::$app->getFields()->getFieldByHandle($field->handle);
    fuzzy_assert($saved instanceof FieldInterface, 'Saved fuzzy field was not returned by handle', ['handle' => $field->handle]);

    return $saved;
}

function fuzzy_first_test_section(): Section
{
    $section = Craft::$app->getEntries()->getSectionByHandle('test');
    if (!$section) {
        $sections = Craft::$app->getEntries()->getAllSections();
        $section = $sections[0] ?? null;
    }

    fuzzy_assert($section instanceof Section, 'No section is available for live fuzzy fixtures');
    fuzzy_assert($section->type !== Section::TYPE_SINGLE, 'Live fuzzy fixture section must support multiple entries', [
        'section' => $section->handle,
    ]);

    return $section;
}

function fuzzy_default_user(): User
{
    $user = User::find()->admin()->status(null)->one() ?? User::find()->status(null)->one();
    fuzzy_assert($user instanceof User, 'No user is available for live fuzzy fixtures');

    return $user;
}

function fuzzy_save_entry_type(array $fields): EntryType
{
    $entryType = Craft::$app->getEntries()->getEntryTypeByHandle('bsLiveFuzzyEntry') ?? new EntryType();
    $entryType->name = 'Bramble Search Live Fuzzy Entry';
    $entryType->handle = 'bsLiveFuzzyEntry';
    $entryType->hasTitleField = true;
    $entryType->showSlugField = true;
    $entryType->showStatusField = true;
    $entryType->setFieldLayout(fuzzy_layout($fields));

    fuzzy_assert(Craft::$app->getEntries()->saveEntryType($entryType), 'Failed to save fuzzy entry type', [
        'errors' => $entryType->getErrors(),
    ]);

    $saved = Craft::$app->getEntries()->getEntryTypeByHandle('bsLiveFuzzyEntry');
    fuzzy_assert($saved instanceof EntryType, 'Saved fuzzy entry type was not returned by handle');

    return $saved;
}

function fuzzy_attach_entry_type_to_section(Section $section, EntryType $entryType): void
{
    foreach ($section->getEntryTypes() as $existing) {
        if ($existing->id === $entryType->id) {
            return;
        }
    }

    $entryTypes = $section->getEntryTypes();
    $entryTypes[] = $entryType;
    $section->setEntryTypes($entryTypes);

    fuzzy_assert(Craft::$app->getEntries()->saveSection($section), 'Failed to attach fuzzy entry type to section', [
        'section' => $section->handle,
        'entryType' => $entryType->handle,
        'errors' => $section->getErrors(),
    ]);
}

function fuzzy_create_entry(
    Section $section,
    EntryType $entryType,
    int $siteId,
    User $author,
    string $title,
    string $fieldText,
    string $fieldHandle,
    string $slug,
): Entry {
    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $entryType->id;
    $entry->siteId = $siteId;
    $entry->title = $title;
    $entry->slug = $slug;
    $entry->enabled = true;
    $entry->enabledForSite = true;
    $entry->setAuthorIds([$author->id]);
    $entry->setFieldValue($fieldHandle, $fieldText);

    fuzzy_assert(Craft::$app->getElements()->saveElement($entry, true, false, true), 'Failed to save live fuzzy entry', [
        'title' => $title,
        'errors' => $entry->getErrors(),
    ]);

    $fresh = Entry::find()->id($entry->id)->siteId($siteId)->status(null)->one();
    fuzzy_assert($fresh instanceof Entry, 'Live fuzzy entry was not reloadable after save', [
        'entry' => $entry->id,
    ]);

    return $fresh;
}

function fuzzy_search_keys(EntryType $entryType, array $fixtures, string $term, int $siteId): array
{
    $ids = Entry::find()
        ->typeId($entryType->id)
        ->siteId($siteId)
        ->status(null)
        ->search($term)
        ->ids();

    $ids = array_map('intval', $ids);
    $fixtureKeysById = [];
    foreach ($fixtures as $key => $entry) {
        $fixtureKeysById[(int)$entry->id] = $key;
    }

    $keys = [];
    foreach ($ids as $id) {
        if (isset($fixtureKeysById[$id])) {
            $keys[] = $fixtureKeysById[$id];
        }
    }

    return $keys;
}

function fuzzy_run_driver(string $driver, string $token, array $fixtures, EntryType $entryType, int $siteId, string $fieldHandle): void
{
    $adapter = fuzzy_adapter($driver, $token);
    Craft::$app->set('search', $adapter);
    $adapter->clearIndex($siteId);

    foreach ($fixtures as $entry) {
        $adapter->indexElementAttributes($entry, [$fieldHandle]);
    }

    $cases = [
        ['term' => 'Antibiotic Oil', 'contains' => ['antibiotic'], 'not' => ['antibody']],
        ['term' => 'Antibioti', 'contains' => ['antibiotic', 'antibiotiExact'], 'not' => ['antibody'], 'first' => 'antibiotiExact'],
        ['term' => 'antibotic', 'contains' => ['antibiotic'], 'not' => ['antibody']],
        ['term' => 'antibio', 'contains' => ['antibiotic'], 'not' => ['antibody']],
        ['term' => 'antibioti oil', 'exact' => ['antibiotic']],
        ['term' => 'antibioti capsules', 'exact' => ['antibioticCapsules']],
        ['term' => 'lavendr', 'contains' => ['lavender']],
        ['term' => 'lavendar', 'contains' => ['lavender']],
        ['term' => 'laven', 'contains' => ['lavender']],
        ['term' => 'gigner', 'contains' => ['ginger']],
        ['term' => 'minerlas', 'contains' => ['mineral']],
        ['term' => 'minera', 'contains' => ['mineral']],
        ['term' => 'supplemnts', 'contains' => ['supplementField']],
        ['term' => 'supplem', 'contains' => ['supplementField']],
        ['term' => 'probotic', 'contains' => ['probioticField']],
        ['term' => 'probi', 'contains' => ['probioticField']],
        ['term' => 'turmeric', 'contains' => ['turmericField']],
        ['term' => 'turmer', 'contains' => ['turmericField']],
        ['term' => 'Why', 'exact' => ['why']],
        ['term' => 'why', 'exact' => ['why']],
        ['term' => 'whi', 'contains' => ['why'], 'not' => ['whey']],
        ['term' => 'cat', 'exact' => []],
    ];

    foreach ($cases as $case) {
        $keys = fuzzy_search_keys($entryType, $fixtures, $case['term'], $siteId);

        if (isset($case['exact'])) {
            fuzzy_assert($keys === $case['exact'], "$driver live fuzzy case failed exact expectation", [
                'term' => $case['term'],
                'expected' => $case['exact'],
                'actual' => $keys,
            ]);
            continue;
        }

        foreach ($case['contains'] ?? [] as $expectedKey) {
            fuzzy_assert(in_array($expectedKey, $keys, true), "$driver live fuzzy case missed expected entry", [
                'term' => $case['term'],
                'expected' => $expectedKey,
                'actual' => $keys,
            ]);
        }

        foreach ($case['not'] ?? [] as $unexpectedKey) {
            fuzzy_assert(!in_array($unexpectedKey, $keys, true), "$driver live fuzzy case returned unrelated entry", [
                'term' => $case['term'],
                'unexpected' => $unexpectedKey,
                'actual' => $keys,
            ]);
        }

        if (isset($case['first'])) {
            fuzzy_assert(($keys[0] ?? null) === $case['first'], "$driver live fuzzy case did not prefer exact result", [
                'term' => $case['term'],
                'expectedFirst' => $case['first'],
                'actual' => $keys,
            ]);
        }
    }

    fuzzy_pass("$driver live fuzzy matrix", [
        'entries' => count($fixtures),
        'cases' => count($cases),
    ]);
}

try {
    $token = substr(bin2hex(random_bytes(4)), 0, 8);
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $section = fuzzy_first_test_section();
    $author = fuzzy_default_user();
    $field = fuzzy_save_field(new PlainText([
        'name' => 'Bramble Search Live Fuzzy Text',
        'handle' => 'bsLiveFuzzyText',
        'context' => 'global',
        'searchable' => true,
    ]));
    $entryType = fuzzy_save_entry_type([$field]);
    fuzzy_attach_entry_type_to_section($section, $entryType);

    $fieldHandle = $field->handle;
    $fixtureData = [
        'antibiotic' => ['Antibiotic Oil', 'cold pressed oregano carrier remedy marker', "antibiotic-oil-$token"],
        'antibiotiExact' => ['Antibioti Exact', 'intentional exact typo control marker', "antibioti-exact-$token"],
        'antibioticCapsules' => ['Antibiotic Capsules', 'capsule comparison marker', "antibiotic-capsules-$token"],
        'antibody' => ['Antibody Research', 'laboratory control marker', "antibody-research-$token"],
        'lavender' => ['Lavender Extract', 'botanical calm marker', "lavender-extract-$token"],
        'ginger' => ['Ginger Root', 'warming digestive marker', "ginger-root-$token"],
        'mineral' => ['Mineral Complex', 'selenium magnesium balance marker', "mineral-complex-$token"],
        'supplementField' => ['Daily Wellness Notes', 'supplements adaptogens nutrition marker', "daily-wellness-notes-$token"],
        'probioticField' => ['Gut Flora Notes', 'probiotic cultures microbiome marker', "gut-flora-notes-$token"],
        'turmericField' => ['Golden Root Notes', 'turmeric curcumin antioxidant marker', "golden-root-notes-$token"],
        'why' => ['Why do you sell the supplements you sell?', 'policy explanation marker', "why-supplements-$token"],
        'whey' => ['Whey Protein Guide', 'whey isolate nutrition marker', "whey-protein-guide-$token"],
        'caterpillar' => ['Caterpillar Study', 'distant shared prefix rejection marker', "caterpillar-study-$token"],
    ];

    $fixtures = [];
    foreach ($fixtureData as $key => [$title, $fieldText, $slug]) {
        $fixtures[$key] = fuzzy_create_entry($section, $entryType, $siteId, $author, $title, $fieldText, $fieldHandle, $slug);
    }

    fuzzy_pass('live fuzzy fixtures saved', [
        'token' => $token,
        'site' => $siteId,
        'entryType' => $entryType->handle,
        'entries' => count($fixtures),
    ]);

    foreach (['mysql', 'file', 'craft', 'redis', 'mongodb'] as $driver) {
        fuzzy_run_driver($driver, $token, $fixtures, $entryType, $siteId, $fieldHandle);
    }

    fuzzy_pass('Craft 5 live fuzzy matrix complete', ['token' => $token]);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
