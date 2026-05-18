<?php

declare(strict_types=1);

use craft\base\FieldInterface;
use craft\base\Field;
use craft\db\Table as DbTable;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\ContentBlock as ContentBlockElement;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
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
use craft\enums\PropagationMethod;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craft\models\TagGroup;
use MadeByBramble\BrambleSearch\adapters\BaseSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\CraftCacheSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\FileSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\MongoDbSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\MySqlSearchAdapter;
use MadeByBramble\BrambleSearch\adapters\RedisSearchAdapter;

require __DIR__ . '/bootstrap.php';

final class LiveFieldFailure extends RuntimeException
{
}

function live_pass(string $message, array $context = []): void
{
    echo '[PASS] ' . $message;
    if ($context !== []) {
        echo ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
    echo PHP_EOL;
}

function live_assert(bool $condition, string $message, array $context = []): void
{
    if (!$condition) {
        throw new LiveFieldFailure($message . ($context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES)));
    }
}

function live_reflect(object $object, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionClass($object);
    $method = $reflection->getMethod($method);
    $method->setAccessible(true);

    return $method->invoke($object, ...$args);
}

function live_register_mongodb_library(): void
{
    static $registered = false;

    if ($registered || class_exists(MongoDB\Client::class)) {
        return;
    }

    $vendor = dirname(__DIR__) . '/vendor';
    $mongodbSrc = $vendor . '/mongodb/mongodb/src';

    live_assert(is_dir($mongodbSrc), 'MongoDB Composer library is not installed in plugin vendor');

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

function live_adapter(string $driver, string $token): BaseSearchAdapter
{
    putenv('BRAMBLE_SEARCH_REDIS_HOST=redis');
    putenv('BRAMBLE_SEARCH_REDIS_PORT=6379');
    putenv("BRAMBLE_SEARCH_REDIS_KEY_PREFIX=bramble_search_live_$token:");
    putenv('BRAMBLE_SEARCH_MONGODB_URI=mongodb://mongodb:27017');
    putenv("BRAMBLE_SEARCH_MONGODB_DATABASE=bramble_search_live_$token");

    if ($driver === 'mongodb') {
        live_register_mongodb_library();
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

function live_standard_field_classes(): array
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

    $installed = [];
    foreach (glob(Craft::getAlias('@craft/fields') . '/*.php') ?: [] as $file) {
        $class = 'craft\\fields\\' . pathinfo($file, PATHINFO_FILENAME);
        if ($class === MissingField::class || !class_exists($class) || !is_a($class, FieldInterface::class, true)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if (!$reflection->isAbstract()) {
            $installed[] = $class;
        }
    }

    sort($classes);
    sort($installed);
    live_assert($classes === $installed, 'Standard Craft field coverage list is out of sync with this Craft 5 install', [
        'missing' => array_values(array_diff($installed, $classes)),
        'extra' => array_values(array_diff($classes, $installed)),
    ]);

    return $classes;
}

function live_field_handle(string $class): string
{
    return 'bsLive' . substr($class, strrpos($class, '\\') + 1);
}

function live_layout(string $type, array $fields, bool $titleField = false): FieldLayout
{
    $layout = new FieldLayout([
        'type' => $type,
        'uid' => StringHelper::UUID(),
    ]);

    $elements = [];
    if ($titleField) {
        $elements[] = new EntryTitleField([
            'required' => true,
            'uid' => StringHelper::UUID(),
            'dateAdded' => new DateTime(),
        ]);
    }

    foreach ($fields as $field) {
        $elements[] = new CustomField($field, [
            'uid' => StringHelper::UUID(),
            'dateAdded' => new DateTime(),
        ]);
    }

    $tab = new FieldLayoutTab([
        'name' => 'Bramble Search Live Fields',
        'uid' => StringHelper::UUID(),
    ]);
    $tab->setLayout($layout);
    $tab->setElements($elements);
    $layout->setTabs([$tab]);

    return $layout;
}

function live_save_field(FieldInterface $field): FieldInterface
{
    $existing = Craft::$app->getFields()->getFieldByHandle($field->handle);
    if ($existing) {
        live_assert(get_class($existing) === get_class($field), 'Existing live field handle has a different field class', [
            'handle' => $field->handle,
            'existing' => get_class($existing),
            'expected' => get_class($field),
        ]);
        $field->id = $existing->id;
        $field->uid = $existing->uid;
        $field->context = $existing->context;
        $field->columnSuffix = $existing->columnSuffix;
    }

    live_assert(Craft::$app->getFields()->saveField($field), 'Failed to save live field', [
        'handle' => $field->handle,
        'type' => get_class($field),
        'errors' => $field->getErrors(),
    ]);

    $saved = Craft::$app->getFields()->getFieldByHandle($field->handle);
    live_assert($saved instanceof FieldInterface, 'Saved live field was not returned by handle', ['handle' => $field->handle]);

    return $saved;
}

function live_first_test_section(): Section
{
    $section = Craft::$app->getEntries()->getSectionByHandle('test');
    if (!$section) {
        $sections = Craft::$app->getEntries()->getAllSections();
        $section = $sections[0] ?? null;
    }

    live_assert($section instanceof Section, 'No section is available for live entry fixtures');
    live_assert($section->type !== Section::TYPE_SINGLE, 'Live field fixture section must support multiple entries', [
        'section' => $section->handle,
    ]);

    return $section;
}

function live_ensure_second_site(Section $section): Site
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

        live_assert($sites->saveSite($site), 'Failed to create secondary Craft 5 live field test site', $site->getErrors());
        $sites->refreshSites();
        $site = $sites->getSiteByHandle('brambleSearchFr', true);
    }

    live_assert($site instanceof Site, 'Secondary Craft 5 live field test site is not available');

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
        live_assert(Craft::$app->getEntries()->saveSection($section, false), 'Failed to enable live fixture section for secondary site', $section->getErrors());
    }

    return $site;
}

function live_default_user(): User
{
    $user = User::find()->admin()->status(null)->one() ?? User::find()->status(null)->one();
    live_assert($user instanceof User, 'No user is available for live field fixtures');

    return $user;
}

function live_save_entry_type(string $handle, string $name, array $fields): EntryType
{
    $entryType = Craft::$app->getEntries()->getEntryTypeByHandle($handle) ?? new EntryType();
    $entryType->name = $name;
    $entryType->handle = $handle;
    $entryType->hasTitleField = true;
    $entryType->showSlugField = true;
    $entryType->showStatusField = true;
    $entryType->setFieldLayout(live_layout(Entry::class, $fields, true));

    live_assert(Craft::$app->getEntries()->saveEntryType($entryType), 'Failed to save live entry type', [
        'handle' => $handle,
        'errors' => $entryType->getErrors(),
    ]);

    $saved = Craft::$app->getEntries()->getEntryTypeByHandle($handle);
    live_assert($saved instanceof EntryType, 'Saved live entry type was not returned by handle', ['handle' => $handle]);

    return $saved;
}

function live_attach_entry_type_to_section(Section $section, EntryType $entryType): void
{
    $entryTypes = $section->getEntryTypes();
    foreach ($entryTypes as $existing) {
        if ($existing->id === $entryType->id) {
            return;
        }
    }

    $entryTypes[] = $entryType;
    $section->setEntryTypes($entryTypes);

    live_assert(Craft::$app->getEntries()->saveSection($section), 'Failed to attach live entry type to fixture section', [
        'section' => $section->handle,
        'entryType' => $entryType->handle,
        'errors' => $section->getErrors(),
    ]);
}

function live_category_group(): CategoryGroup
{
    $handle = 'bsLiveCategories';
    $group = Craft::$app->getCategories()->getGroupByHandle($handle);
    if ($group) {
        return $group;
    }

    $group = new CategoryGroup([
        'name' => 'Bramble Search Live Categories',
        'handle' => $handle,
    ]);

    $siteSettings = [];
    foreach (Craft::$app->getSites()->getAllSites() as $site) {
        $siteSettings[] = new CategoryGroup_SiteSettings([
            'siteId' => $site->id,
            'hasUrls' => false,
        ]);
    }
    $group->setSiteSettings($siteSettings);

    live_assert(Craft::$app->getCategories()->saveGroup($group), 'Failed to save live category group', $group->getErrors());

    return Craft::$app->getCategories()->getGroupByHandle($handle);
}

function live_tag_group(): TagGroup
{
    $handle = 'bsLiveTags';
    $group = Craft::$app->getTags()->getTagGroupByHandle($handle);
    if ($group) {
        return $group;
    }

    $group = new TagGroup([
        'name' => 'Bramble Search Live Tags',
        'handle' => $handle,
    ]);

    live_assert(Craft::$app->getTags()->saveTagGroup($group), 'Failed to save live tag group', $group->getErrors());

    return Craft::$app->getTags()->getTagGroupByHandle($handle);
}

function live_related_entry(Section $section, EntryType $entryType, int $siteId, User $author, string $token): Entry
{
    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $entryType->id;
    $entry->siteId = $siteId;
    $entry->title = "bsliveentries$token";
    $entry->slug = "bsliveentries-$token";
    $entry->enabled = true;
    $entry->enabledForSite = true;
    $entry->setAuthorIds([$author->id]);

    live_assert(Craft::$app->getElements()->saveElement($entry, true, true, false), 'Failed to save live related entry', [
        'errors' => $entry->getErrors(),
    ]);

    return $entry;
}

function live_related_asset(string $token): Asset
{
    $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
    live_assert($volume !== null, 'No volume is available for live asset field testing');

    $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
    live_assert($folder !== null, 'No root asset folder is available for live asset field testing');

    $tempDir = Craft::$app->getPath()->getTempPath() . '/bramble-search-live-fields';
    FileHelper::createDirectory($tempDir);
    $tempFile = "$tempDir/bsliveasset-$token.txt";
    file_put_contents($tempFile, "bsliveasset$token\n");

    $asset = new Asset();
    $asset->tempFilePath = $tempFile;
    $asset->filename = "bsliveasset-$token.txt";
    $asset->newFolderId = $folder->id;
    $asset->volumeId = $volume->id;
    $asset->folderId = $folder->id;
    $asset->title = "bsliveasset$token";
    $asset->enabled = true;
    $asset->setScenario(Asset::SCENARIO_CREATE);

    live_assert(Craft::$app->getElements()->saveElement($asset, false, true, false), 'Failed to save live asset target', [
        'errors' => $asset->getErrors(),
    ]);

    return $asset;
}

function live_related_category(int $siteId, string $token): Category
{
    $group = live_category_group();
    $category = new Category();
    $category->groupId = $group->id;
    $category->siteId = $siteId;
    $category->title = "bslivecategory$token";
    $category->slug = "bslivecategory-$token";
    $category->enabled = true;

    live_assert(Craft::$app->getElements()->saveElement($category, false, true, false), 'Failed to save live category target', [
        'errors' => $category->getErrors(),
    ]);

    return $category;
}

function live_related_tag(string $token): Tag
{
    $group = live_tag_group();
    $tag = new Tag();
    $tag->groupId = $group->id;
    $tag->title = "bslivetag$token";

    live_assert(Craft::$app->getElements()->saveElement($tag, false, true, false), 'Failed to save live tag target', [
        'errors' => $tag->getErrors(),
    ]);

    return $tag;
}

function live_related_user(string $token): User
{
    $user = new User();
    $user->username = "bsliveuser$token";
    $user->email = "bsliveuser$token@example.test";
    $user->firstName = "bsliveuser$token";
    $user->lastName = 'Fixture';
    $user->pending = false;
    $user->active = true;

    live_assert(Craft::$app->getElements()->saveElement($user, false, true, false), 'Failed to save live user target', [
        'errors' => $user->getErrors(),
    ]);

    return $user;
}

function live_field_value_and_expected(
    string $class,
    string $handle,
    string $token,
    Entry $relatedEntry,
    Asset $asset,
    Category $category,
    Tag $tag,
    User $user,
    FieldInterface $nestedField,
    EntryType $matrixEntryType,
): array {
    $baseMarker = strtolower($handle . $token);

    $value = match ($class) {
        Addresses::class => [
            'new1' => [
                'enabled' => true,
                'title' => "bsliveaddress$token",
                'fullName' => "bsliveaddress$token",
                'firstName' => "bsliveaddress$token",
                'lastName' => 'Fixture',
                'countryCode' => 'US',
                'administrativeArea' => 'CA',
                'locality' => "bslivelocality$token",
                'postalCode' => '90210',
                'addressLine1' => "bslivestreet$token",
                'organization' => "bsliveorg$token",
            ],
        ],
        Assets::class => [$asset->id],
        ButtonGroup::class, Dropdown::class, RadioButtons::class => $baseMarker,
        Categories::class => [$category->id],
        Checkboxes::class, MultiSelect::class => [$baseMarker],
        Color::class => '#a1b2c3',
        ContentBlock::class => [
            'fields' => [
                $nestedField->handle => "bslivecontentblock$token",
            ],
        ],
        Country::class => 'GB',
        Date::class => new DateTime('2026-05-18 09:15:00', new DateTimeZone('UTC')),
        Email::class => "$baseMarker@example.test",
        Entries::class => [$relatedEntry->id],
        Icon::class => 'magnifying-glass',
        Json::class => ['marker' => $baseMarker],
        Lightswitch::class => true,
        Link::class => ['type' => 'url', 'value' => "https://example.test/$baseMarker", 'label' => $baseMarker],
        Matrix::class => [
            'entries' => [
                'new1' => [
                    'type' => $matrixEntryType->handle,
                    'title' => "bslivematrix$token",
                    'slug' => "bslivematrix-$token",
                    'enabled' => true,
                    'fields' => [
                        $nestedField->handle => "bslivematrixfield$token",
                    ],
                ],
            ],
            'sortOrder' => ['new1'],
        ],
        Money::class => ['value' => '765.43', 'currency' => 'GBP'],
        Number::class => 765431,
        PlainText::class => $baseMarker,
        Range::class => 765432,
        Table::class => [['search' => $baseMarker]],
        Tags::class => [$tag->id],
        Time::class => '09:15',
        Url::class => ['type' => 'url', 'value' => "https://example.test/$baseMarker", 'label' => $baseMarker],
        Users::class => [$user->id],
        default => $baseMarker,
    };

    $expected = match ($class) {
        Addresses::class => ["bsliveaddress$token"],
        Assets::class => ["bsliveasset$token"],
        Categories::class => ["bslivecategory$token"],
        Color::class => ['a1b2c3'],
        ContentBlock::class => ["bslivecontentblock$token"],
        Country::class => ['gb'],
        Date::class, Money::class, Time::class => [],
        Entries::class => ["bsliveentries$token"],
        Icon::class => ['magnifying', 'glass'],
        Lightswitch::class => ['1'],
        Matrix::class => ["bslivematrix$token", "bslivematrixfield$token"],
        Number::class => ['765431'],
        Range::class => ['765432'],
        Tags::class => ["bslivetag$token"],
        Users::class => ["bsliveuser$token"],
        default => [$baseMarker],
    };

    return [$value, $expected];
}

function live_values_and_expected(
    array $sourceClasses,
    string $token,
    Entry $relatedEntry,
    Asset $asset,
    Category $category,
    Tag $tag,
    User $user,
    FieldInterface $nestedField,
    EntryType $matrixEntryType,
): array {
    $values = [];
    $expected = [];

    foreach ($sourceClasses as $handle => $class) {
        [$values[$handle], $expected[$handle]] = live_field_value_and_expected(
            $class,
            $handle,
            $token,
            $relatedEntry,
            $asset,
            $category,
            $tag,
            $user,
            $nestedField,
            $matrixEntryType,
        );
    }

    return [$values, $expected];
}

function live_build_fields(string $token, Entry $relatedEntry, Asset $asset, Category $category, Tag $tag, User $user): array
{
    $classes = live_standard_field_classes();
    $nestedField = live_save_field(new PlainText([
        'name' => 'Bramble Search Live Nested Text',
        'handle' => 'bsLiveNestedText',
        'context' => 'global',
        'searchable' => true,
    ]));

    $matrixEntryType = live_save_entry_type('bsLiveMatrixBlock', 'Bramble Search Live Matrix Block', [$nestedField]);

    $fields = [];
    $values = [];
    $expected = [];
    $sourceClasses = [];
    $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;

    foreach ($classes as $class) {
        $handle = live_field_handle($class);
        $shortName = substr($class, strrpos($class, '\\') + 1);
        $baseMarker = strtolower($handle . $token);

        /** @var FieldInterface $field */
        $field = new $class([
            'name' => 'Bramble Search ' . $shortName,
            'handle' => $handle,
            'context' => 'global',
            'searchable' => true,
        ]);
        if (in_array(Field::TRANSLATION_METHOD_SITE, $field::supportedTranslationMethods(), true)) {
            $field->translationMethod = Field::TRANSLATION_METHOD_SITE;
        }

        if (property_exists($field, 'options')) {
            $field->options = [
                ['label' => $baseMarker, 'value' => $baseMarker, 'default' => false],
                ['label' => strtolower($handle . 'fr' . $token), 'value' => strtolower($handle . 'fr' . $token), 'default' => false],
            ];
        }

        if ($class === Assets::class && $volume) {
            $field->sources = '*';
            $field->defaultUploadLocationSource = 'volume:' . $volume->uid;
            $field->restrictedLocationSource = 'volume:' . $volume->uid;
        } elseif (is_a($class, craft\fields\BaseRelationField::class, true)) {
            $field->sources = '*';
        }

        if ($class === Table::class) {
            $field->columns = [
                'search' => [
                    'heading' => 'Search',
                    'handle' => 'search',
                    'type' => 'singleline',
                ],
            ];
        } elseif ($class === Matrix::class) {
            $field->setEntryTypes([$matrixEntryType]);
            $field->viewMode = Matrix::VIEW_MODE_BLOCKS;
        } elseif ($class === ContentBlock::class) {
            $field->setFieldLayout(live_layout(ContentBlockElement::class, [$nestedField], false));
        } elseif ($class === Link::class) {
            $field->types = ['url'];
            $field->showLabelField = true;
        } elseif ($class === Money::class) {
            $field->currency = 'GBP';
        } elseif ($class === Color::class) {
            $field->allowCustomColors = true;
        } elseif ($class === Range::class) {
            $field->max = 999999;
        }

        $field = live_save_field($field);
        $fields[$handle] = $field;
        $sourceClasses[$handle] = $class;
        [$values[$handle], $expected[$handle]] = live_field_value_and_expected(
            $class,
            $handle,
            $token,
            $relatedEntry,
            $asset,
            $category,
            $tag,
            $user,
            $nestedField,
            $matrixEntryType,
        );
    }

    return [$fields, $values, $expected, $sourceClasses, $nestedField, $matrixEntryType];
}

function live_create_entry(Section $section, EntryType $entryType, int $siteId, User $author, array $values, string $token): Entry
{
    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $entryType->id;
    $entry->siteId = $siteId;
    $entry->title = "Bramble Search Live Field Entry $token";
    $entry->slug = "bramble-search-live-field-entry-$token";
    $entry->enabled = true;
    $entry->enabledForSite = true;
    $entry->setAuthorIds([$author->id]);
    $entry->setFieldValues($values);

    Craft::$app->set('search', live_adapter('mysql', $token));
    live_assert(Craft::$app->getElements()->saveElement($entry, true, true, true), 'Failed to save live field entry through Craft elements service', [
        'errors' => $entry->getErrors(),
    ]);

    $fresh = Entry::find()
        ->id($entry->id)
        ->siteId($siteId)
        ->status(null)
        ->one();

    live_assert($fresh instanceof Entry, 'Live field entry was not reloadable after save', ['id' => $entry->id]);

    return $fresh;
}

function live_localize_entry(Entry $entry, int $siteId, User $author, string $token, array $values = [], ?string $title = null): Entry
{
    $localized = Entry::find()
        ->id($entry->id)
        ->siteId($siteId)
        ->status(null)
        ->one();

    live_assert($localized instanceof Entry, 'Live entry was not available in the secondary site', [
        'entry' => $entry->id,
        'site' => $siteId,
    ]);

    $localized->title = $title ?? "Bramble Search Live Field Entry $token";
    $localized->slug = StringHelper::slugify($localized->title);
    $localized->enabled = true;
    $localized->enabledForSite = true;
    $localized->setAuthorIds([$author->id]);
    if ($values !== []) {
        $localized->setFieldValues($values);
    }

    live_assert(Craft::$app->getElements()->saveElement($localized, true, false, true), 'Failed to localize live entry through Craft elements service', [
        'entry' => $entry->id,
        'site' => $siteId,
        'errors' => $localized->getErrors(),
    ]);

    $fresh = Entry::find()
        ->id($entry->id)
        ->siteId($siteId)
        ->status(null)
        ->one();

    live_assert($fresh instanceof Entry, 'Localized live entry was not reloadable after save', [
        'entry' => $entry->id,
        'site' => $siteId,
    ]);

    return $fresh;
}

function live_localize_category(Category $category, int $siteId, string $token): Category
{
    $localized = Category::find()
        ->id($category->id)
        ->siteId($siteId)
        ->status(null)
        ->one();

    live_assert($localized instanceof Category, 'Live category was not available in the secondary site', [
        'category' => $category->id,
        'site' => $siteId,
    ]);

    $localized->title = "bslivecategory$token";
    $localized->slug = "bslivecategory-$token";
    $localized->enabled = true;

    live_assert(Craft::$app->getElements()->saveElement($localized, false, false, false), 'Failed to localize live category target', [
        'errors' => $localized->getErrors(),
    ]);

    $fresh = Category::find()->id($category->id)->siteId($siteId)->status(null)->one();
    live_assert($fresh instanceof Category, 'Localized live category was not reloadable after save', [
        'category' => $category->id,
        'site' => $siteId,
    ]);

    return $fresh;
}

function live_tokenize_keywords(BaseSearchAdapter $adapter, FieldInterface $field, Entry $entry): array
{
    $value = $entry->getFieldValue($field->handle);
    $keywords = $field->getSearchKeywords($value, $entry);
    $tokens = live_reflect($adapter, 'tokenize', $keywords);
    $tokens = live_reflect($adapter, 'filterStopWords', $tokens);

    return array_values(array_unique($tokens));
}

function live_entry_search_ids(EntryType $entryType, string $term, int $siteId): array
{
    return Entry::find()
        ->typeId($entryType->id)
        ->siteId($siteId)
        ->status(null)
        ->search($term)
        ->ids();
}

function live_fuzzy_term(string $term): ?string
{
    if (strlen($term) < 8 || !preg_match('/[a-z]{6}/', $term)) {
        return null;
    }

    return substr($term, 0, -1);
}

function live_assert_entry_field_searches(string $driver, Entry $entry, EntryType $entryType, array $fields, array $expected): array
{
    $adapter = Craft::$app->getSearch();
    $keywordFields = [];
    $emptyFields = [];
    $missingKeywordMarkers = [];
    $missingSearchResults = [];
    $missingFuzzyResults = [];

    foreach ($fields as $handle => $field) {
        $tokens = live_tokenize_keywords($adapter, $field, $entry);
        if ($tokens === []) {
            $emptyFields[] = $handle;
        } else {
            $keywordFields[] = $handle;
        }

        foreach ($tokens as $term) {
            $ids = live_entry_search_ids($entryType, $term, $entry->siteId);
            if (!in_array((string)$entry->id, array_map('strval', $ids), true)) {
                $missingSearchResults[$handle][$term] = $ids;
            }

            $fuzzy = live_fuzzy_term($term);
            if ($fuzzy !== null) {
                $fuzzyIds = live_entry_search_ids($entryType, $fuzzy, $entry->siteId);
                if (!in_array((string)$entry->id, array_map('strval', $fuzzyIds), true)) {
                    $missingFuzzyResults[$handle][$fuzzy] = $fuzzyIds;
                }
            }
        }

        foreach ($expected[$handle] as $term) {
            $term = strtolower($term);
            if (!in_array($term, $tokens, true)) {
                $missingKeywordMarkers[$handle][] = $term;
            }
        }
    }

    live_assert($missingKeywordMarkers === [], "$driver live field values did not emit expected Craft search keywords", [
        'entry' => $entry->id,
        'site' => $entry->siteId,
        'missing' => $missingKeywordMarkers,
    ]);
    live_assert($missingSearchResults === [], "$driver live field searches did not return the saved entry", [
        'entry' => $entry->id,
        'site' => $entry->siteId,
        'missing' => $missingSearchResults,
    ]);
    live_assert($missingFuzzyResults === [], "$driver live field fuzzy searches did not return the saved entry", [
        'entry' => $entry->id,
        'site' => $entry->siteId,
        'missing' => $missingFuzzyResults,
    ]);

    return [$keywordFields, $emptyFields];
}

function live_run_driver(
    string $driver,
    string $token,
    Entry $entry,
    Entry $localizedEntry,
    EntryType $entryType,
    array $fields,
    array $expected,
    array $localizedExpected,
): void
{
    $adapter = live_adapter($driver, $token);
    Craft::$app->set('search', $adapter);
    $adapter->clearIndex($entry->siteId);
    $adapter->clearIndex($localizedEntry->siteId);
    $adapter->indexElementAttributes($entry, array_keys($fields));
    $adapter->indexElementAttributes($localizedEntry, array_keys($fields));

    [$keywordFields, $emptyFields] = live_assert_entry_field_searches($driver, $entry, $entryType, $fields, $expected);
    [$localizedKeywordFields, $localizedEmptyFields] = live_assert_entry_field_searches($driver, $localizedEntry, $entryType, $fields, $localizedExpected);

    $englishPlainText = strtolower(live_field_handle(PlainText::class) . $token);
    $localizedPlainText = strtolower(live_field_handle(PlainText::class) . 'fr' . $token);
    live_assert(live_entry_search_ids($entryType, $englishPlainText, $localizedEntry->siteId) === [], "$driver live field site isolation failed for English marker on localized site");
    live_assert(live_entry_search_ids($entryType, $localizedPlainText, $entry->siteId) === [], "$driver live field site isolation failed for localized marker on primary site");

    $localizedFuzzy = live_fuzzy_term($localizedPlainText);
    live_assert($localizedFuzzy !== null, 'Localized fuzzy marker could not be generated');
    $localizedFuzzyIds = live_entry_search_ids($entryType, $localizedFuzzy, $localizedEntry->siteId);
    live_assert(in_array((string)$localizedEntry->id, array_map('strval', $localizedFuzzyIds), true), "$driver localized live field fuzzy search failed", [
        'term' => $localizedFuzzy,
        'ids' => $localizedFuzzyIds,
    ]);

    live_pass("$driver live field matrix", [
        'entry' => $entry->id,
        'localizedEntry' => $localizedEntry->id,
        'sites' => [$entry->siteId, $localizedEntry->siteId],
        'fields' => count($fields),
        'keywordFields' => count($keywordFields),
        'emptyKeywordFields' => $emptyFields,
        'localizedKeywordFields' => count($localizedKeywordFields),
        'localizedEmptyKeywordFields' => $localizedEmptyFields,
    ]);
}

try {
    $token = substr(bin2hex(random_bytes(4)), 0, 8);
    $localizedToken = 'fr' . $token;
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $section = live_first_test_section();
    $localizedSite = live_ensure_second_site($section);
    $author = live_default_user();

    $nestedEntryType = live_save_entry_type('bsLiveRelatedEntry', 'Bramble Search Live Related Entry', []);
    live_attach_entry_type_to_section($section, $nestedEntryType);
    $relatedEntry = live_related_entry($section, $nestedEntryType, $siteId, $author, $token);
    $localizedRelatedEntry = live_localize_entry($relatedEntry, $localizedSite->id, $author, $localizedToken, [], "bsliveentries$localizedToken");
    $asset = live_related_asset($token);
    $localizedAsset = live_related_asset($localizedToken);
    $category = live_related_category($siteId, $token);
    $localizedCategory = live_localize_category($category, $localizedSite->id, $localizedToken);
    $tag = live_related_tag($token);
    $localizedTag = live_related_tag($localizedToken);
    $user = live_related_user($token);
    $localizedUser = live_related_user($localizedToken);

    [$fields, $values, $expected, $sourceClasses, $nestedField, $matrixEntryType] = live_build_fields($token, $relatedEntry, $asset, $category, $tag, $user);
    [$localizedValues, $localizedExpected] = live_values_and_expected(
        $sourceClasses,
        $localizedToken,
        $localizedRelatedEntry,
        $localizedAsset,
        $localizedCategory,
        $localizedTag,
        $localizedUser,
        $nestedField,
        $matrixEntryType,
    );
    foreach ([live_field_handle(Addresses::class), live_field_handle(ContentBlock::class), live_field_handle(Matrix::class)] as $handle) {
        unset($localizedValues[$handle]);
        $localizedExpected[$handle] = $expected[$handle] ?? [];
    }
    $entryType = live_save_entry_type('bsLiveAllFields', 'Bramble Search Live All Fields', array_values($fields));
    live_attach_entry_type_to_section($section, $entryType);
    $entry = live_create_entry($section, $entryType, $siteId, $author, $values, $token);
    $localizedEntry = live_localize_entry($entry, $localizedSite->id, $author, $localizedToken, $localizedValues);

    live_pass('live Craft field fixtures saved', [
        'token' => $token,
        'localizedToken' => $localizedToken,
        'entry' => $entry->id,
        'localizedEntry' => $localizedEntry->id,
        'sites' => [$siteId, $localizedSite->id],
        'fields' => count($fields),
        'projectConfigFields' => (new craft\db\Query())->from(DbTable::FIELDS)->where(['like', 'handle', 'bsLive%', false])->count(),
    ]);

    foreach (['mysql', 'file', 'craft', 'redis', 'mongodb'] as $driver) {
        live_run_driver($driver, $token, $entry, $localizedEntry, $entryType, $fields, $expected, $localizedExpected);
    }

    live_pass('Craft 5 live standard field matrix complete', ['token' => $token]);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
