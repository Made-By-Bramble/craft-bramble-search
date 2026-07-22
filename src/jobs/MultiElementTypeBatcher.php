<?php

namespace MadeByBramble\BrambleSearch\jobs;

use craft\base\Batchable;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;

/**
 * MultiElementTypeBatcher
 *
 * A Batchable implementation that processes multiple element type queries sequentially.
 * Used by RebuildIndexJob to index all registered element types.
 */
class MultiElementTypeBatcher implements Batchable
{
    /**
     * Maximum elements hydrated per fetch. Craft's BaseBatchedJob memory guard measures
     * average memory use against the FIRST processed item, so eagerly hydrating a full
     * 100-item batch in one ->all() call attributes the whole spike to item 1 and breaks
     * the batch almost immediately. Small chunks keep that average honest.
     */
    private const CHUNK_SIZE = 20;

    /**
     * @var ElementQueryInterface[] Array of element queries to process
     */
    private array $queries;

    /**
     * @var int Total count across all queries (cached)
     */
    private ?int $totalCount = null;

    /**
     * Constructor
     *
     * @param ElementQueryInterface[] $queries Array of element queries to process
     */
    public function __construct(array $queries)
    {
        $this->queries = $queries;
    }

    /**
     * @inheritdoc
     */
    public function count(): int
    {
        if ($this->totalCount === null) {
            $this->totalCount = 0;
            foreach ($this->queries as $query) {
                $this->totalCount += $query->count();
            }
        }

        return $this->totalCount;
    }

    /**
     * @inheritdoc
     */
    public function getSlice(int $offset, int $limit): iterable
    {
        $itemsToSkip = $offset;
        $itemsToTake = $limit;

        // Find which query contains the offset
        $currentOffset = 0;
        foreach ($this->queries as $queryIndex => $query) {
            $queryCount = $query->count();

            // Check if the offset falls within this query
            if ($itemsToSkip < $currentOffset + $queryCount) {
                // Calculate the offset within this query
                $queryOffset = $itemsToSkip - $currentOffset;
                $queryLimit = min($itemsToTake, $queryCount - $queryOffset);

                foreach ($this->fetchChunked($query, $queryOffset, $queryLimit) as $item) {
                    yield $item;
                    $itemsToTake--;

                    if ($itemsToTake <= 0) {
                        return;
                    }
                }

                // Move to next query if we need more items
                $nextIndex = $queryIndex + 1;
                while ($nextIndex < count($this->queries) && $itemsToTake > 0) {
                    $nextQuery = $this->queries[$nextIndex];
                    $nextQueryLimit = min($itemsToTake, $nextQuery->count());

                    foreach ($this->fetchChunked($nextQuery, 0, $nextQueryLimit) as $item) {
                        yield $item;
                        $itemsToTake--;

                        if ($itemsToTake <= 0) {
                            return;
                        }
                    }

                    $nextIndex++;
                }

                return;
            }

            $currentOffset += $queryCount;
        }

        // If we get here, the offset is beyond all queries
        return;
    }

    /**
     * Fetch and yield a query's [offset, offset + limit) window in bounded chunks instead
     * of hydrating it all at once. Clones the query per chunk so the shared query objects
     * in $this->queries are never mutated.
     *
     * @param ElementQueryInterface $query
     * @return iterable<ElementInterface>
     */
    private function fetchChunked(object $query, int $offset, int $limit): iterable
    {
        $fetched = 0;

        while ($fetched < $limit) {
            $chunkSize = min(self::CHUNK_SIZE, $limit - $fetched);
            $chunk = (clone $query)->offset($offset + $fetched)->limit($chunkSize)->all();

            foreach ($chunk as $item) {
                yield $item;
            }

            $chunkCount = count($chunk);
            unset($chunk);

            if ($chunkCount === 0) {
                return;
            }

            $fetched += $chunkCount;
        }
    }
}
