<?php

namespace MadeByBramble\BrambleSearch\jobs;

use Craft;
use craft\db\QueryBatcher;
use craft\elements\Entry;
use craft\queue\BaseBatchedJob;

/**
 * Rebuild Index Job
 *
 * Queue job that rebuilds the search index for a specific site.
 * Uses Craft's batched job system to automatically split processing across
 * multiple job executions, preventing timeouts on large sites.
 */
class RebuildIndexJob extends BaseBatchedJob
{
    /**
     * The site ID to rebuild the index for
     */
    public ?int $siteId = null;

    /**
     * The batch size for processing entries per job execution
     * Craft will automatically create child jobs for each batch
     */
    public int $batchSize = 100;

    /**
     * Lifecycle hook: runs once before all batches are processed
     * Clears the existing index for the site before rebuilding
     *
     * @return void
     */
    protected function before(): void
    {
        // Get the site
        $site = Craft::$app->getSites()->getSiteById($this->siteId);

        if (!$site) {
            throw new \Exception("Site with ID {$this->siteId} not found");
        }

        // Clear the existing index for this site before rebuilding
        Craft::info("Starting index rebuild for site ID: {$site->id}", __METHOD__);
        $this->clearIndex($site->id);
    }

    /**
     * Load the data to be processed in batches
     * Returns a QueryBatcher that will be automatically split into child jobs
     *
     * @return \craft\base\Batchable The batcher containing the entry query
     */
    public function loadData(): \craft\base\Batchable
    {
        // Get the site
        $site = Craft::$app->getSites()->getSiteById($this->siteId);

        if (!$site) {
            throw new \Exception("Site with ID {$this->siteId} not found");
        }

        // Build the entry query
        // Exclude drafts, revisions, provisional drafts, and entries without titles
        $entryQuery = Entry::find()
            ->site($site)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->provisionalDrafts(false)
            ->andWhere(['not', ['title' => null]])
            ->andWhere(['not', ['title' => '']])
            ->orderBy('id ASC'); // Required for QueryBatcher stability

        // Return a QueryBatcher that will handle the batch processing
        return new QueryBatcher($entryQuery);
    }

    /**
     * Process a single item from the batch
     * Called once per entry by Craft's batching system
     *
     * @param Entry $item The entry to index
     * @return void
     */
    public function processItem($item): void
    {
        $this->indexEntry($item);
    }

    /**
     * Lifecycle hook: runs once after all batches are processed
     * Logs completion of the index rebuild
     *
     * @return void
     */
    protected function after(): void
    {
        Craft::info("Index rebuild completed for site ID: {$this->siteId}", __METHOD__);
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
