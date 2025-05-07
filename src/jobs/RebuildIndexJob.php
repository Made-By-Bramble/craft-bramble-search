<?php

namespace MadeByBramble\BrambleSearch\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;

/**
 * Rebuild Index Job
 *
 * Queue job that rebuilds the search index for a specific site.
 * Processes entries in batches to avoid memory issues.
 */
class RebuildIndexJob extends BaseJob
{
    /**
     * The site ID to rebuild the index for
     */
    public ?int $siteId = null;

    /**
     * The batch size for processing entries to avoid memory issues
     */
    public int $batchSize = 100;

    /**
     * Execute the job
     *
     * @param craft\queue\QueueInterface $queue The queue the job belongs to
     */
    public function execute($queue): void
    {
        // Get the site
        $site = Craft::$app->getSites()->getSiteById($this->siteId);

        if (!$site) {
            throw new \Exception("Site with ID {$this->siteId} not found");
        }

        // Clear the existing index for this site before rebuilding
        Craft::info("Starting index rebuild for site ID: {$site->id}", __METHOD__);
        $this->clearIndex($site->id);

        // Get the total number of entries for this site
        // Exclude drafts, revisions, provisional drafts, and entries without titles
        $entryQuery = Entry::find()
            ->site($site)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->provisionalDrafts(false)
            ->andWhere(['not', ['title' => null]])
            ->andWhere(['not', ['title' => '']]);
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

        Craft::info("Index rebuild completed for site ID: {$site->id}", __METHOD__);
    }

    /**
     * Return the default description for this job
     *
     * @return string The job description
     */
    protected function defaultDescription(): string
    {
        return 'Rebuilding search index';
    }

    /**
     * Index a single entry in the search index
     *
     * @param Entry $entry The entry to index
     * @return bool Whether the indexing was successful
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
     * Filters fields based on their searchable property
     *
     * @param Entry $entry The entry to get field handles for
     * @return array List of searchable field handles
     */
    protected function getIndexableFieldHandles(Entry $entry): array
    {
        $fieldHandles = [];
        $fieldLayout = $entry->getFieldLayout();

        if (!$fieldLayout) {
            return $fieldHandles;
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            // Skip fields that shouldn't be indexed
            if (!$field->searchable) {
                continue;
            }

            $fieldHandles[] = $field->handle;
        }

        return $fieldHandles;
    }

    /**
     * Clear the search index for a specific site
     *
     * Uses the search service's clearIndex method if available
     *
     * @param int $siteId The site ID to clear the index for
     */
    protected function clearIndex(int $siteId): void
    {
        Craft::info("Clearing search index for site ID: $siteId", __METHOD__);

        // Get the search service
        $searchService = Craft::$app->getSearch();

        // Check if the search service is one of our adapters
        if (method_exists($searchService, 'clearIndex')) {
            // If our search service has a clearIndex method, use it
            $searchService->clearIndex($siteId);
        } else {
            // Otherwise, log a warning
            Craft::warning("Search service does not support clearIndex method. Index may not be fully cleared.", __METHOD__);
        }
    }
}
