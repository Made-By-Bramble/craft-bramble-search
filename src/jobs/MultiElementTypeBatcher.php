<?php

namespace MadeByBramble\BrambleSearch\jobs;

use craft\base\Batchable;
use craft\elements\db\ElementQuery;
use yii\db\Connection as YiiConnection;

/**
 * MultiElementTypeBatcher
 *
 * A Batchable implementation that processes multiple element type queries sequentially.
 * Used by RebuildIndexJob to index all registered element types.
 */
class MultiElementTypeBatcher implements Batchable
{
    /**
     * @var ElementQuery[] Array of element queries to process
     */
    private array $queries;

    /**
     * @var int Current query index
     */
    private int $currentQueryIndex = 0;

    /**
     * @var int Total count across all queries (cached)
     */
    private ?int $totalCount = null;

    /**
     * @var YiiConnection|null Database connection
     */
    private ?YiiConnection $db;

    /**
     * Constructor
     *
     * @param ElementQuery[] $queries Array of element queries to process
     * @param YiiConnection|null $db Database connection
     */
    public function __construct(array $queries, ?YiiConnection $db = null)
    {
        $this->queries = $queries;
        $this->db = $db;
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
        $itemsProcessed = 0;
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

                // Get items from this query
                $queryLimit = min($itemsToTake, $queryCount - $queryOffset);
                $querySlice = $query->offset($queryOffset)->limit($queryLimit)->all();

                foreach ($querySlice as $item) {
                    yield $item;
                    $itemsProcessed++;
                    $itemsToTake--;

                    if ($itemsToTake <= 0) {
                        return;
                    }
                }

                // Move to next query if we need more items
                $queryIndex++;
                while ($queryIndex < count($this->queries) && $itemsToTake > 0) {
                    $nextQuery = $this->queries[$queryIndex];
                    $nextQueryLimit = min($itemsToTake, $nextQuery->count());
                    $nextQuerySlice = $nextQuery->limit($nextQueryLimit)->all();

                    foreach ($nextQuerySlice as $item) {
                        yield $item;
                        $itemsProcessed++;
                        $itemsToTake--;

                        if ($itemsToTake <= 0) {
                            return;
                        }
                    }

                    $queryIndex++;
                }

                return;
            }

            $currentOffset += $queryCount;
        }

        // If we get here, the offset is beyond all queries
        return;
    }
}
