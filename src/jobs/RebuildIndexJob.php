<?php

namespace MadeByBramble\BrambleSearch\jobs;

use Craft;
use craft\base\ElementInterface;
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

        // Enable bulk mode to skip per-element updateTotalDocCount
        $searchService = Craft::$app->getSearch();
        if (property_exists($searchService, 'bulkMode')) {
            $searchService->bulkMode = true;
        }

        // Clear the existing index for this site before rebuilding
        Craft::info("Starting index rebuild for site ID: {$site->id}", __METHOD__);
        $this->clearIndex($site->id);
    }

    /**
     * Load the data to be processed in batches
     * Returns a MultiElementTypeBatcher that processes all registered element types
     *
     * @return \craft\base\Batchable The batcher containing queries for all element types
     */
    public function loadData(): \craft\base\Batchable
    {
        // Get the site
        $site = Craft::$app->getSites()->getSiteById($this->siteId);

        if (!$site) {
            throw new \Exception("Site with ID {$this->siteId} not found");
        }

        // Get all registered element types
        $elementTypes = Craft::$app->getElements()->getAllElementTypes();
        $queries = [];

        // Build queries for each element type
        foreach ($elementTypes as $elementType) {
            /** @var class-string<ElementInterface> $elementType */
            $query = $elementType::find()
                ->site($site)
                ->status(null)
                ->drafts(false)
                ->revisions(false)
                ->provisionalDrafts(false)
                ->orderBy('id ASC'); // Required for batch processing stability

            // Only filter by title if the element type has titles
            if ($elementType::hasTitles()) {
                $query->andWhere(['not', ['title' => null]])
                      ->andWhere(['not', ['title' => '']]);
            }

            $queries[] = $query;
        }

        // Return a MultiElementTypeBatcher that will handle batch processing for all element types
        return new MultiElementTypeBatcher($queries);
    }

    /**
     * Process a single item from the batch
     * Called once per element by Craft's batching system
     *
     * @param ElementInterface $item The element to index
     * @return void
     */
    public function processItem($item): void
    {
        $this->indexElement($item);
    }

    /**
     * Lifecycle hook: runs once after all batches are processed
     * Logs completion of the index rebuild
     *
     * @return void
     */
    protected function after(): void
    {
        $searchService = Craft::$app->getSearch();

        // Update total document count once after all batches
        if (method_exists($searchService, 'refreshTotalDocCount')) {
            $searchService->refreshTotalDocCount();
        }

        // Disable bulk mode
        if (property_exists($searchService, 'bulkMode')) {
            $searchService->bulkMode = false;
        }

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
     * Index a single element in the search index
     *
     * @param ElementInterface $element The element to index
     * @return bool Whether the indexing was successful
     */
    protected function indexElement(ElementInterface $element): bool
    {
        try {
            // Get all field handles that can be indexed
            $fieldHandles = $this->getIndexableFieldHandles($element);

            // Use the search service to index the element
            return Craft::$app->getSearch()->indexElementAttributes($element, $fieldHandles);
        } catch (\Throwable $e) {
            $elementType = get_class($element);
            Craft::error("Error indexing {$elementType} {$element->id}: {$e->getMessage()}", __METHOD__);
            return false;
        }
    }

    /**
     * Get the field handles that can be indexed for an element
     *
     * Filters fields based on their searchable property
     *
     * @param ElementInterface $element The element to get field handles for
     * @return array List of searchable field handles
     */
    protected function getIndexableFieldHandles(ElementInterface $element): array
    {
        $fieldHandles = [];
        $fieldLayout = $element->getFieldLayout();

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
