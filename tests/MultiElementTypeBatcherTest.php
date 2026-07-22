<?php

declare(strict_types=1);

namespace MadeByBrambleTest\BrambleSearch;

use MadeByBramble\BrambleSearch\jobs\MultiElementTypeBatcher;
use PHPUnit\Framework\TestCase;

final class MultiElementTypeBatcherTest extends TestCase
{
    public function testGetSliceChunksWithinASingleQueryWithoutMutatingIt(): void
    {
        $query = new FakeElementQuery(range(1, 60));
        $batcher = new MultiElementTypeBatcher([$query]);

        $items = iterator_to_array($batcher->getSlice(0, 55), false);

        self::assertSame(range(1, 55), $items);
        self::assertGreaterThan(1, count($query->fetchedLimits()), 'Expected fetching in more than one chunk.');
        foreach ($query->fetchedLimits() as $limit) {
            self::assertLessThanOrEqual(20, $limit);
        }
        self::assertFalse($query->wasMutated, 'The shared query instance must never be mutated; only clones should be.');
    }

    public function testGetSliceYieldsExactCountAcrossQueryBoundaryWithChunking(): void
    {
        $first = new FakeElementQuery(range(1, 15));
        $second = new FakeElementQuery(range(101, 130));
        $batcher = new MultiElementTypeBatcher([$first, $second]);

        // Offset 5 into the first (15-item) query, limit 35: spans the rest of the
        // first query (10 items) plus 25 items from the second, forcing the second
        // query's fetch into multiple 20-item chunks.
        $items = iterator_to_array($batcher->getSlice(5, 35), false);

        self::assertSame(35, count($items));
        self::assertSame(array_merge(range(6, 15), range(101, 125)), $items);
        self::assertGreaterThan(1, count($second->fetchedOffsets()));
        self::assertFalse($first->wasMutated);
        self::assertFalse($second->wasMutated);
    }
}

/**
 * Minimal ElementQueryInterface-shaped stub: MultiElementTypeBatcher only calls
 * count(), offset(), limit(), and all() (via a cloned instance), so a full Craft
 * element query isn't needed to exercise the chunked-fetch behaviour.
 */
final class FakeElementQuery
{
    /**
     * Instance-local (NOT shared across clones): true only if offset()/limit() was
     * called directly on this exact object, proving the batcher never mutates the
     * shared query, only clones of it.
     */
    public bool $wasMutated = false;

    private int $offset = 0;
    private int $limit = PHP_INT_MAX;

    /**
     * Object handles survive `clone` (shallow copy), unlike plain array properties,
     * so this recorder stays shared between the original and every clone the batcher
     * fetches through — needed to observe how many chunked all() calls happened.
     */
    private \ArrayObject $calls;

    public function __construct(private readonly array $items)
    {
        $this->calls = new \ArrayObject(['offsets' => [], 'limits' => []]);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function offset(int $offset): static
    {
        $this->offset = $offset;
        $this->wasMutated = true;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;
        $this->wasMutated = true;

        return $this;
    }

    public function all(): array
    {
        $this->calls['offsets'] = [...$this->calls['offsets'], $this->offset];
        $this->calls['limits'] = [...$this->calls['limits'], $this->limit];

        return array_slice($this->items, $this->offset, $this->limit);
    }

    public function fetchedOffsets(): array
    {
        return $this->calls['offsets'];
    }

    public function fetchedLimits(): array
    {
        return $this->calls['limits'];
    }
}
