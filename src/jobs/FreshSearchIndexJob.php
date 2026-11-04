<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\jobs;

use Craft;
use craft\queue\jobs\UpdateSearchIndex;
use MadeByBramble\BrambleSearch\adapters\BaseSearchAdapter;
use MadeByBramble\BrambleSearch\helpers\IndexableElements;

final class FreshSearchIndexJob extends UpdateSearchIndex
{
    public bool $queued = false;

    public function execute($queue): void
    {
        $adapter = Craft::$app->getSearch();
        if (!($adapter instanceof BaseSearchAdapter)) {
            parent::execute($queue);

            return;
        }
        IndexableElements::assertFreshReadContext();
        if ($this->queued) {
            if (!is_int($this->elementId) || !is_int($this->siteId)) {
                throw new \RuntimeException('Native queued indexing requires scalar element and site IDs.');
            }
            $adapter->indexElementIfQueued($this->elementId, $this->siteId, $this->elementType);

            return;
        }
        IndexableElements::validateType($this->elementType);
        $query = $this->elementType::find()->drafts(null)->provisionalDrafts(null)->id($this->elementId)->siteId($this->siteId)->status(null);
        $siteIds = $query->siteId === '*' ? Craft::$app->getSites()->getAllSiteIds() : (array)($query->siteId ?: Craft::$app->getSites()->getCurrentSite()->id);
        if (!$this->elementType::isLocalized()) {
            $siteIds = [Craft::$app->getSites()->getPrimarySite()->id];
        }
        $total = (int)(clone $query)->count();
        $processed = 0;
        foreach ($siteIds as $siteId) {
            if (!ctype_digit((string)$siteId) || (int)$siteId <= 0) {
                throw new \RuntimeException('Native persisted indexing requires valid site IDs.');
            }
            $last = 0;
            do {
                $ids = (clone $query)->siteId((int)$siteId)->offset(null)->limit(20)->orderBy('elements.id ASC')->andWhere(['>', 'elements.id', $last])->ids();
                foreach ($ids as $id) {
                    $last = (int)$id;
                    if (!$adapter->indexPersistedElement($this->elementType, $last, (int)$siteId, $this->fieldHandles)) {
                        throw new \RuntimeException('Native persisted indexing did not publish current content.');
                    }
                    $this->setProgress($queue, min(1, ++$processed / max(1, $total)));
                }
            } while (count($ids) === 20);
        }
    }
}
