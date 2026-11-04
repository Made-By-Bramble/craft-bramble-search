<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\jobs;

use craft\base\Batchable;
use craft\db\Query;
use MadeByBramble\BrambleSearch\helpers\RebuildState;

final class RebuildManifestBatcher implements Batchable
{
    private int $total = 0;
    private array $types = [];

    public function __construct(string $identity, int $siteId, private string $runId)
    {
        $owner = RebuildState::owner($identity, $siteId);
        if ($owner === null) {
            throw new \RuntimeException('The rebuild manifest owner is missing.');
        }
        if ($owner['runId'] !== $runId || $owner['status'] === 'complete') {
            return;
        }
        if ($owner['status'] !== 'active') {
            throw new \RuntimeException('The rebuild manifest is not ready for execution.');
        }
        RebuildState::assertManifest($owner);
        $this->total = $owner['totalItems'];
        $this->types = $owner['types'];
    }

    public function count(): int
    {
        return $this->total;
    }

    public function getSlice(int $offset, int $limit): iterable
    {
        if ($offset < 0 || $limit < 0) {
            throw new \InvalidArgumentException('Manifest slices require nonnegative bounds.');
        }
        $end = min($this->total, $offset + $limit);
        while ($offset < $end) {
            $chunkIndex = intdiv($offset, RebuildState::CHUNK_SIZE);
            $chunk = (new Query())->from(RebuildState::CHUNKS)->where(['runId' => $this->runId, 'chunkIndex' => $chunkIndex])->one();
            if ($chunk === null || $chunk === false) {
                throw new \RuntimeException('A prepared rebuild chunk is missing.');
            }
            $items = json_decode($chunk['items'], true, 512, JSON_THROW_ON_ERROR);
            $expected = min(RebuildState::CHUNK_SIZE, $this->total - $chunkIndex * RebuildState::CHUNK_SIZE);
            if (!is_array($items) || !array_is_list($items) || count($items) !== $expected || (int)$chunk['itemCount'] !== $expected) {
                throw new \RuntimeException('A prepared rebuild chunk is malformed.');
            }
            foreach (array_slice($items, $offset % RebuildState::CHUNK_SIZE, min($end - $offset, $expected - $offset % RebuildState::CHUNK_SIZE)) as $item) {
                if (!is_array($item) || !array_is_list($item) || count($item) !== 2 || !is_int($item[0]) || $item[0] <= 0
                    || !is_int($item[1]) || !isset($this->types[$item[1]])) {
                    throw new \RuntimeException('A rebuild reference is malformed.');
                }
                yield ['elementId' => $item[0], 'elementType' => $this->types[$item[1]]];
                $offset++;
            }
        }
    }
}
