<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\helpers;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\ElementHelper;

final class IndexableElements
{
    public static function validateType(string $type): void
    {
        if (!is_a($type, ElementInterface::class, true) || (new \ReflectionClass($type))->isAbstract()) {
            throw new \RuntimeException('The rebuild references an unavailable element type.');
        }
    }

    public static function query(string $type, int $siteId): ElementQueryInterface
    {
        self::validateType($type);
        $query = $type::find()->siteId($siteId)->status(Element::STATUS_ENABLED)
            ->drafts(false)->revisions(false)->provisionalDrafts(false)->offset(null)->limit(null)->orderBy('elements.id ASC');
        if ($type::hasTitles()) {
            $query->andWhere(['not', ['title' => null]])->andWhere(['not', ['title' => '']]);
        }
        if (self::siteIdForType($type, $siteId) !== $siteId) {
            $query->andWhere('0=1');
        }

        return $query;
    }

    public static function isEligible(ElementInterface $element): bool
    {
        return ($element->dateDeleted ?? null) === null
            && $element->enabled && $element->getEnabledForSite()
            && !ElementHelper::isDraftOrRevision($element)
            && !(property_exists($element, 'isProvisionalDraft') && $element->isProvisionalDraft)
            && (!$element::hasTitles() || ($element->title !== null && (string)$element->title !== ''));
    }

    public static function load(string $type, int $id, int $siteId): ?ElementInterface
    {
        self::assertFreshReadContext();
        self::validateType($type);

        $element = $type::find()->id($id)->siteId($siteId)->status(null)->drafts(null)->revisions(null)
            ->provisionalDrafts(null)->trashed(null)->cache(false)->one();
        if ($element !== null && $element->siteId !== $siteId) {
            throw new \RuntimeException('Fresh element hydration does not match the locked site identity.');
        }

        return $element;
    }

    public static function siteIdForType(string $type, int $siteId): int
    {
        self::validateType($type);

        return $type::isLocalized() ? $siteId : Craft::$app->getSites()->getPrimarySite()->id;
    }

    public static function assertFreshReadContext(): void
    {
        if (Craft::$app->getDb()->getTransaction()?->getIsActive() === true) {
            throw new \RuntimeException('Fresh background indexing must run outside an existing application transaction.');
        }
    }

    public static function currentIds(array $types, int $siteId): array
    {
        $ids = [];
        foreach ($types as $type) {
            $last = 0;
            do {
                $page = self::query($type, $siteId)->andWhere(['>', 'elements.id', $last])->limit(1000)->ids();
                foreach ($page as $id) {
                    $ids[(int)$id] = true;
                    $last = (int)$id;
                }
            } while (count($page) === 1000);
        }

        return array_keys($ids);
    }
}
