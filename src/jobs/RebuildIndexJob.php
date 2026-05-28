<?php

namespace MadeByBramble\BrambleSearch\jobs;

use Craft;
use craft\base\Batchable;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\queue\BaseBatchedJob;
use MadeByBramble\BrambleSearch\adapters\BaseSearchAdapter;

/**
 * Rebuild Index Job
 *
 * Queue job that rebuilds the search index for a specific site.
 * Uses Craft's batched job system to automatically split processing across
 * multiple job executions, preventing timeouts on large sites.
 */
class RebuildIndexJob extends BaseBatchedJob
{
    private const LOCK_TTL = 21600;

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

        $searchService = $this->getSearchAdapter();
        $this->acquireRebuildLock($site->id);

        try {
            // Enable bulk mode to skip per-element updateTotalDocCount
            $this->setBulkMode($searchService, true);

            // Clear the existing index for this site before rebuilding
            Craft::info("Starting index rebuild for site ID: {$site->id}", __METHOD__);
            $this->clearIndex($site->id);
        } catch (\Throwable $e) {
            $this->releaseRebuildLock($site->id);
            throw $e;
        }
    }

    protected function beforeBatch(): void
    {
        $this->setBulkMode($this->getSearchAdapter(), true);
    }

    protected function afterBatch(): void
    {
        $this->setBulkMode($this->getSearchAdapter(), false);
    }

    /**
     * Load the data to be processed in batches
     * Returns a MultiElementTypeBatcher that processes all registered element types
     *
     * @return \craft\base\Batchable The batcher containing queries for all element types
     */
    public function loadData(): Batchable
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
                ->status(Element::STATUS_ENABLED)
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
    public function processItem(mixed $item): void
    {
        if (!($item instanceof ElementInterface)) {
            $this->setBulkMode($this->getSearchAdapter(), false);
            $this->releaseRebuildLock($this->siteId);
            throw new \RuntimeException('Rebuild index batch contained a non-element item.');
        }

        if (!$this->indexElement($item)) {
            $elementType = get_class($item);
            $this->setBulkMode($this->getSearchAdapter(), false);
            $this->releaseRebuildLock($this->siteId);
            throw new \RuntimeException("Failed to index {$elementType} {$item->id} for site {$item->siteId}");
        }
    }

    /**
     * Lifecycle hook: runs once after all batches are processed
     * Logs completion of the index rebuild
     *
     * @return void
     */
    protected function after(): void
    {
        $searchService = $this->getSearchAdapter();

        try {
            // Recalculate metadata once after all batches
            if (method_exists($searchService, 'refreshTotalDocCount')) {
                $searchService->refreshTotalDocCount();
            }
            if (method_exists($searchService, 'refreshTotalLength')) {
                $searchService->refreshTotalLength();
            }
        } finally {
            // Always reset bulk mode, even if metadata refresh fails
            $this->setBulkMode($searchService, false);
            $this->releaseRebuildLock($this->siteId);
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

        $searchService = $this->getSearchAdapter();

        if (!$searchService->clearIndex($siteId)) {
            throw new \RuntimeException("Failed to clear Bramble Search index for site ID: $siteId");
        }
    }

    protected function getSearchAdapter(): BaseSearchAdapter
    {
        $searchService = Craft::$app->getSearch();

        if (!($searchService instanceof BaseSearchAdapter)) {
            throw new \RuntimeException('Bramble Search is not active as the Craft search service.');
        }

        return $searchService;
    }

    protected function setBulkMode(BaseSearchAdapter $searchService, bool $enabled): void
    {
        if (property_exists($searchService, 'bulkMode')) {
            $searchService->bulkMode = $enabled;
        }
    }

    protected function acquireRebuildLock(int $siteId): void
    {
        if (!Craft::$app->getCache()->add($this->rebuildLockKey($siteId), time(), self::LOCK_TTL)) {
            throw new \RuntimeException("A Bramble Search index rebuild is already running for site ID: $siteId");
        }
    }

    protected function releaseRebuildLock(?int $siteId): void
    {
        if ($siteId !== null) {
            Craft::$app->getCache()->delete($this->rebuildLockKey($siteId));
        }
    }

    protected function rebuildLockKey(int $siteId): string
    {
        return "bramble-search:rebuild:$siteId";
    }
}
