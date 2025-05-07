<?php

namespace MadeByBramble\BrambleSearch\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;

/**
 * Rebuild Index job
 */
class RebuildIndexJob extends BaseJob
{
    /**
     * @var int|null The site ID to rebuild the index for
     */
    public ?int $siteId = null;

    /**
     * @var int The batch size for processing entries
     */
    public int $batchSize = 100;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        // Get the site
        $site = Craft::$app->getSites()->getSiteById($this->siteId);

        if (!$site) {
            throw new \Exception("Site with ID {$this->siteId} not found");
        }

        // Get the total number of entries for this site
        $entryQuery = Entry::find()->site($site)->status(null);
        $count = $entryQuery->count();

        if ($count === 0) {
            return;
        }

        // Process entries in batches
        $offset = 0;
        $step = 0;

        while ($offset < $count) {
            $entries = $entryQuery->offset($offset)->limit($this->batchSize)->all();

            foreach ($entries as $i => $entry) {
                $this->indexEntry($entry);

                $this->setProgress(
                    $queue,
                    ($step * $this->batchSize + $i) / $count,
                    Craft::t('bramble-search', 'Indexed {current} of {total}', [
                        'current' => $step * $this->batchSize + $i + 1,
                        'total' => $count
                    ])
                );
            }

            $offset += $this->batchSize;
            $step++;
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return 'Rebuilding search index';
    }

    /**
     * Index an entry
     *
     * @param Entry $entry
     * @return bool
     */
    protected function indexEntry(Entry $entry): bool
    {
        try {
            // Get all field handles that can be indexed
            $fieldHandles = $this->getIndexableFieldHandles($entry);

            // Use the search service to index the entry
            return Craft::$app->getSearch()->indexElementAttributes($entry, $fieldHandles);
        } catch (\Throwable $e) {
            Craft::error("Error indexing entry {$entry->id}: {$e->getMessage()}", __METHOD__);
            return false;
        }
    }

    /**
     * Get the field handles that can be indexed for an entry
     *
     * @param Entry $entry
     * @return array
     */
    protected function getIndexableFieldHandles(Entry $entry): array
    {
        $fieldHandles = [];

        foreach ($entry->getFieldLayout()->getCustomFields() as $field) {
            // Skip fields that shouldn't be indexed
            if (!$field->searchable) {
                continue;
            }

            $fieldHandles[] = $field->handle;
        }

        return $fieldHandles;
    }
}
