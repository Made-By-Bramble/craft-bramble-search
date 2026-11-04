<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\adapters;

use Craft;
use craft\db\Query;
use MadeByBramble\BrambleSearch\helpers\IndexableElements;
use MadeByBramble\BrambleSearch\helpers\PrefixIntegrityException;
use MadeByBramble\BrambleSearch\helpers\RebuildState;

/** Keeps derived prefix state inside document publication and the ordinary rebuild. */
trait PrefixLifecycleTrait
{
    protected function hasPrefixDictionary(): bool
    {
        return false;
    }

    protected function prefixLifecycleState(int $siteId): array
    {
        if ($siteId <= 0) {
            throw new \InvalidArgumentException('An index lifecycle requires a positive site ID.');
        }
        try {
            $state = $this->readIndexRecord('prefix_lifecycle:' . $siteId);
        } catch (\JsonException $error) {
            throw new PrefixIntegrityException('Invalid prefix lifecycle encoding.', 0, $error);
        }
        if ($state === null) {
            return $this->emptyPrefixLifecycleState();
        }
        if (($state['version'] ?? null) !== 2 || !is_array($state['retired'] ?? null)
            || !array_is_list($state['retired']) || !is_array($state['cleanup'] ?? null)
            || !is_bool($state['legacyComplete'] ?? null) || !is_bool($state['buildingInitialized'] ?? null)
            || !array_key_exists('legacyCursor', $state) || (!is_array($state['legacyCursor']) && !is_int($state['legacyCursor']) && $state['legacyCursor'] !== null)) {
            throw new PrefixIntegrityException('Invalid prefix lifecycle state.');
        }
        foreach (['active', 'building', 'buildingRun', 'activatedRun', 'failedRun'] as $key) {
            if (!array_key_exists($key, $state) || ($state[$key] !== null && (!is_string($state[$key]) || preg_match('/\A[a-f0-9]{32}\z/D', $state[$key]) !== 1))) {
                throw new PrefixIntegrityException('Invalid prefix lifecycle identity.');
            }
        }
        $epochs = array_values(array_filter([$state['active'], $state['building'], ...$state['retired']], static fn(mixed $epoch): bool => $epoch !== null));
        foreach ($epochs as $epoch) {
            if (!is_string($epoch) || preg_match('/\A[a-f0-9]{32}\z/D', $epoch) !== 1) {
                throw new PrefixIntegrityException('Invalid retired prefix epoch.');
            }
        }
        if (count($epochs) > 2 || count(array_unique($epochs, SORT_STRING)) !== count($epochs)
            || ($state['building'] === null) !== ($state['buildingRun'] === null)) {
            throw new PrefixIntegrityException('Inconsistent prefix lifecycle ownership.');
        }

        if (isset($state['retiring']) && !is_bool($state['retiring'])) {
            throw new PrefixIntegrityException('Invalid prefix retirement state.');
        }
        $this->validatePrefixLifecycleCursors($state);

        return $state;
    }

    protected function emptyPrefixLifecycleState(): array
    {
        return ['version' => 2, 'active' => null, 'building' => null, 'buildingRun' => null, 'buildingInitialized' => false, 'activatedRun' => null, 'failedRun' => null, 'retired' => [], 'cleanup' => [], 'legacyComplete' => false, 'legacyCursor' => null];
    }

    protected function savePrefixLifecycleState(int $siteId, array $state): void
    {
        $this->writeIndexRecord('prefix_lifecycle:' . $siteId, $state);
    }

    /** @internal */
    public function beginDerivedRebuild(int $siteId, string $runId): bool
    {
        if (!$this->hasPrefixDictionary()) {
            return true;
        }
        $this->requirePrefixRunId($runId);

        return $this->withPublicationGuard(function() use ($siteId, $runId): bool {
            $this->assertRebuildContext();
            try {
                $state = $this->prefixLifecycleState($siteId);
            } catch (PrefixIntegrityException $error) {
                $state = $this->emptyPrefixLifecycleState();
                $this->savePrefixLifecycleState($siteId, $state);
                Craft::error('Untrusted prefix state will be replaced by the ordinary rebuild: ' . $error->getMessage(), 'bramble-search');
            }
            if ($state['failedRun'] === $runId) {
                throw new PrefixIntegrityException('The replacement prefix epoch failed its integrity checks.');
            }
            if ($state['activatedRun'] === $runId) {
                return true;
            }
            if ($state['buildingRun'] === $runId) {
                $this->initializeBuildingPrefixEpoch($siteId, $state);
                return true;
            }
            if ($state['building'] !== null) {
                $state['retired'][] = $state['building'];
                $state['building'] = null;
                $state['buildingRun'] = null;
                $this->savePrefixLifecycleState($siteId, $state);
            }
            if ($state['retired'] !== []) {
                $state = $this->cleanupRetiredPrefixStep($siteId, $state);
                $this->savePrefixLifecycleState($siteId, $state);

                return false;
            }
            if (!$state['legacyComplete']) {
                $result = $this->cleanupLegacyPrefixStep($siteId, $state['legacyCursor']);
                $state['legacyCursor'] = $result['cursor'];
                $state['legacyComplete'] = $result['done'];
                $this->savePrefixLifecycleState($siteId, $state);
                if (!$result['done']) {
                    return false;
                }
            }
            $state['retiring'] = false;
            $state['buildingInitialized'] = false;
            $state['building'] = $runId;
            $state['buildingRun'] = $runId;
            $state['failedRun'] = null;
            $this->savePrefixLifecycleState($siteId, $state);
            $this->mutationCheckpoint('prefixEpochIntent', $siteId, 0);
            $this->initializeBuildingPrefixEpoch($siteId, $state);
            $this->mutationCheckpoint('prefixEpochStarted', $siteId, 0);

            return true;
        });
    }

    /** @internal */
    public function advanceDerivedRebuild(int $siteId, string $runId, array $checkpoint): array
    {
        if (!$this->hasPrefixDictionary()) {
            return $this->withPublicationGuard(function(): array {
                $this->assertRebuildContext();
                return ['phase' => 'complete'];
            });
        }
        $this->requirePrefixRunId($runId);
        $phase = $checkpoint['phase'] ?? 'catalogue';
        if (!in_array($phase, ['catalogue', 'membership', 'pending', 'activate', 'cleanup', 'complete'], true)) {
            throw new \RuntimeException('Invalid derived rebuild checkpoint.');
        }
        $state = $this->withPublicationGuard(fn(): array => $this->prefixLifecycleState($siteId));
        if ($state['activatedRun'] === $runId) {
            $phase = 'cleanup';
        } elseif ($state['failedRun'] === $runId || $state['buildingRun'] !== $runId) {
            throw new PrefixIntegrityException('The rebuild no longer owns a valid replacement prefix epoch.');
        }
        if ($phase === 'catalogue' || $phase === 'membership') {
            $page = $this->withPublicationGuard(function() use ($siteId, $runId, $phase, $checkpoint): array {
                $this->assertRebuildContext();
                $state = $this->prefixLifecycleState($siteId);
                if ($state['buildingRun'] !== $runId) {
                    throw new PrefixIntegrityException('Prefix membership reconciliation lost its owner.');
                }

                return $this->prefixDocumentPage($siteId, $phase === 'membership' ? $state['building'] : null, $checkpoint['cursor'] ?? null, 20);
            });
            foreach ($page['documents'] as $docId) {
                $this->reconcilePrefixDocument($siteId, (string)$docId, false);
            }
            $this->mutationCheckpoint('prefixLifecyclePage', $siteId, 0);

            return $page['done'] ? ['phase' => $phase === 'catalogue' ? 'membership' : 'pending'] : ['phase' => $phase, 'cursor' => $page['cursor']];
        }
        if ($phase === 'pending') {
            $pending = $this->withPublicationGuard(fn(): array => array_slice(array_keys($this->pendingPrefixDocuments($siteId)), 0, 20));
            foreach ($pending as $docId) {
                $this->reconcilePrefixDocument($siteId, (string)$docId, true);
            }

            return ['phase' => $pending === [] ? 'activate' : 'pending'];
        }
        if ($phase === 'activate') {
            $this->mutationCheckpoint('prefixBeforeActivation', $siteId, 0);
            return $this->withPublicationGuard(function() use ($siteId, $runId): array {
                $this->assertRebuildContext();
                $state = $this->prefixLifecycleState($siteId);
                if ($state['activatedRun'] === $runId) {
                    return ['phase' => 'cleanup'];
                }
                if ($state['buildingRun'] !== $runId || $state['failedRun'] === $runId) {
                    throw new PrefixIntegrityException('Prefix activation no longer matches its owner.');
                }
                if ($this->pendingPrefixDocuments($siteId) !== []) {
                    return ['phase' => 'pending'];
                }
                $this->verifyPrefixEpoch($siteId, $state['building']);
                if ($state['active'] !== null) {
                    $state['retired'][] = $state['active'];
                }
                $state['active'] = $state['building'];
                $state['building'] = null;
                $state['buildingRun'] = null;
                $state['activatedRun'] = $runId;
                $this->savePrefixLifecycleState($siteId, $state);
                $this->mutationCheckpoint('prefixEpochActivated', $siteId, 0);

                return ['phase' => 'cleanup'];
            });
        }

        return $this->withPublicationGuard(function() use ($siteId, $runId): array {
            $this->assertRebuildContext();
            $state = $this->prefixLifecycleState($siteId);
            if ($state['activatedRun'] !== $runId || $state['active'] === null) {
                throw new PrefixIntegrityException('Prefix cleanup lost the activated epoch.');
            }
            $this->verifyPrefixEpoch($siteId, $state['active']);
            $state = $this->cleanupRetiredPrefixStep($siteId, $state);
            $this->savePrefixLifecycleState($siteId, $state);
            $this->mutationCheckpoint('prefixEpochCleanup', $siteId, 0);

            return ['phase' => $state['retired'] === [] ? 'complete' : 'cleanup'];
        });
    }

    protected function reconcilePrefixDocument(int $siteId, string $docId, bool $retrySource): void
    {
        [, $elementId] = $this->prefixDocumentIdentity($docId, $siteId);
        if ($retrySource) {
            $type = (new Query())->select('type')->from('{{%elements}}')->where(['id' => $elementId])->scalar();
            if (is_string($type) && class_exists($type) && is_subclass_of($type, \craft\base\Element::class)) {
                IndexableElements::validateType($type);
                $success = IndexableElements::siteIdForType($type, $siteId) === $siteId
                    ? $this->indexPersistedElement($type, $elementId, $siteId, null)
                    : $this->deleteElementFromIndex($elementId, $siteId);
            } else {
                $success = $this->deleteElementFromIndex($elementId, $siteId);
            }
            if (!$success) {
                throw new \RuntimeException('A pending search document could not be recovered.');
            }

            return;
        }
        $this->withElementIndexLockById($elementId, $siteId, function() use ($siteId, $elementId): bool {
            return $this->withPublicationGuard(function() use ($siteId, $elementId): bool {
                $this->assertRebuildContext();
                $exists = $this->documentExists($siteId, $elementId);
                $this->publishPrefixMembership($siteId, $elementId, $exists ? array_keys($this->getDocumentTerms($siteId, $elementId)) : [], $exists, $exists);

                return true;
            });
        });
    }

    protected function cleanupRetiredPrefixStep(int $siteId, array $state): array
    {
        if ($state['retired'] === []) {
            return $state;
        }
        $epoch = $state['retired'][0];
        if ($epoch === $state['active'] || $epoch === $state['building']) {
            throw new PrefixIntegrityException('Cannot clean a currently maintained prefix epoch.');
        }
        $result = $this->cleanupPrefixEpochStep($siteId, $epoch, $state['cleanup'], 20);
        $state['cleanup'] = $result['checkpoint'];
        if ($result['done']) {
            array_shift($state['retired']);
            $state['cleanup'] = [];
        }

        return $state;
    }

    protected function publishLifecyclePrefixMembership(int $siteId, int $elementId, array $terms, bool $exists): void
    {
        if (!$this->hasPrefixDictionary()) {
            return;
        }
        $this->withPublicationGuard(function() use ($siteId, $elementId, $terms, $exists): void {
            $state = $this->prefixLifecycleState($siteId);
            $this->initializeBuildingPrefixEpoch($siteId, $state);
            foreach (['active', 'building'] as $target) {
                $epoch = $state[$target];
                if ($epoch === null) {
                    continue;
                }
                try {
                    $this->publishPrefixEpochMembership($siteId, $epoch, $elementId, $this->normalizePrefixTerms($terms), $exists);
                    $this->mutationCheckpoint('prefixEpochPublished', $siteId, $elementId);
                } catch (PrefixIntegrityException $error) {
                    $this->fencePrefixEpoch($siteId, $epoch, $error);
                    throw $error;
                }
            }
        });
    }

    protected function fencePrefixEpoch(int $siteId, string $epoch, PrefixIntegrityException $error): void
    {
        $state = $this->prefixLifecycleState($siteId);
        foreach (['active', 'building'] as $target) {
            if ($state[$target] === $epoch) {
                if ($target === 'building') {
                    $state['failedRun'] = $state['buildingRun'];
                    RebuildState::fail($this->getIndexIdentity(), $siteId, $state['buildingRun']);
                    $state['buildingRun'] = null;
                }
                $state[$target] = null;
                $state['retired'][] = $epoch;
            }
        }
        $this->savePrefixLifecycleState($siteId, $state);
        Craft::error('Invalid derived search data was fenced: ' . $error->getMessage(), 'bramble-search');
    }

    protected function prefixTermsFromActiveEpoch(string $prefix, int $siteId, int $limit): ?array
    {
        return $this->withPublicationGuard(function() use ($prefix, $siteId, $limit): ?array {
            try {
                $state = $this->prefixLifecycleState($siteId);
            } catch (PrefixIntegrityException) {
                return null;
            }
            if ($state['active'] === null) {
                return null;
            }
            $pending = $this->pendingPrefixDocuments($siteId);
            try {
                foreach (array_slice(array_keys($pending), 0, 20) as $docId) {
                    [, $elementId] = $this->prefixDocumentIdentity((string)$docId, $siteId);
                    $exists = $this->documentExists($siteId, $elementId);
                    $this->publishLifecyclePrefixMembership($siteId, $elementId, $exists ? array_keys($this->getDocumentTerms($siteId, $elementId)) : [], $exists);
                }
                if (count($pending) > 20) {
                    return null;
                }
            } catch (PrefixIntegrityException) {
                return null;
            }
            $state = $this->prefixLifecycleState($siteId);
            if ($state['active'] === null) {
                return null;
            }
            try {
                $this->assertPrefixEpoch($siteId, $state['active']);

                return $this->queryPrefixEpoch($siteId, $state['active'], $prefix, $limit);
            } catch (PrefixIntegrityException $error) {
                $this->fencePrefixEpoch($siteId, $state['active'], $error);

                return null;
            }
        });
    }

    protected function retireDerivedSiteStep(int $siteId): bool
    {
        if (!$this->hasPrefixDictionary()) {
            return true;
        }
        return $this->withPublicationGuard(function() use ($siteId): bool {
            try {
                $state = $this->prefixLifecycleState($siteId);
            } catch (PrefixIntegrityException) {
                $state = $this->emptyPrefixLifecycleState();
            }
            if (!($state['retiring'] ?? false)) {
                foreach (['active', 'building'] as $target) {
                    if ($state[$target] !== null) {
                        $state['retired'][] = $state[$target];
                        $state[$target] = null;
                    }
                }
                $state['buildingRun'] = null;
                $state['buildingInitialized'] = false;
                $state['retiring'] = true;
                $state['legacyComplete'] = false;
                $state['legacyCursor'] = null;
                $this->savePrefixLifecycleState($siteId, $state);
            }
            if ($state['retired'] !== []) {
                $state = $this->cleanupRetiredPrefixStep($siteId, $state);
                $this->savePrefixLifecycleState($siteId, $state);
                return false;
            }
            if (!$state['legacyComplete']) {
                $result = $this->cleanupLegacyPrefixStep($siteId, $state['legacyCursor']);
                $state['legacyCursor'] = $result['cursor'];
                $state['legacyComplete'] = $result['done'];
                $this->savePrefixLifecycleState($siteId, $state);
                if (!$result['done']) {
                    return false;
                }
            }
            $this->deleteIndexRecord('prefix_lifecycle:' . $siteId);
            return true;
        });
    }

    protected function cancelDerivedRebuild(int $siteId): void
    {
        if (!$this->hasPrefixDictionary()) {
            return;
        }
        $this->withPublicationGuard(function() use ($siteId): void {
            try {
                $state = $this->prefixLifecycleState($siteId);
            } catch (PrefixIntegrityException) {
                $this->savePrefixLifecycleState($siteId, $this->emptyPrefixLifecycleState());
                return;
            }
            if ($state['building'] !== null) {
                $state['retired'][] = $state['building'];
                $state['failedRun'] = $state['buildingRun'];
                $state['building'] = null;
                $state['buildingRun'] = null;
                $state['buildingInitialized'] = false;
                $this->savePrefixLifecycleState($siteId, $state);
            }
        });
    }

    protected function validatePrefixLifecycleCursors(array $state): void
    {
    }

    protected function initializeBuildingPrefixEpoch(int $siteId, array $state): void
    {
        if ($state['building'] === null || $state['buildingInitialized']) {
            return;
        }
        try {
            $this->initializePrefixEpoch($siteId, $state['building']);
        } catch (PrefixIntegrityException $error) {
            $this->fencePrefixEpoch($siteId, $state['building'], $error);
            throw $error;
        }
        $state['buildingInitialized'] = true;
        $this->savePrefixLifecycleState($siteId, $state);
    }

    protected function verifyPrefixEpoch(int $siteId, string $epoch): void
    {
        try {
            $this->assertPrefixEpoch($siteId, $epoch);
        } catch (PrefixIntegrityException $error) {
            $this->fencePrefixEpoch($siteId, $epoch, $error);
            throw $error;
        }
    }

    protected function initializePrefixEpoch(int $siteId, string $epoch): void
    {
        throw new \LogicException('This driver does not use a prefix dictionary.');
    }

    protected function assertPrefixEpoch(int $siteId, string $epoch): void
    {
        throw new \LogicException('This driver does not use a prefix dictionary.');
    }

    protected function publishPrefixEpochMembership(int $siteId, string $epoch, int $elementId, array $terms, bool $exists): void
    {
        throw new \LogicException('This driver does not use a prefix dictionary.');
    }

    protected function queryPrefixEpoch(int $siteId, string $epoch, string $prefix, int $limit): array
    {
        throw new \LogicException('This driver does not use a prefix dictionary.');
    }

    protected function prefixDocumentPage(int $siteId, ?string $epoch, mixed $cursor, int $limit): array
    {
        throw new \LogicException('This driver does not use a prefix dictionary.');
    }

    protected function cleanupPrefixEpochStep(int $siteId, string $epoch, array $checkpoint, int $limit): array
    {
        throw new \LogicException('This driver does not use a prefix dictionary.');
    }

    protected function cleanupLegacyPrefixStep(int $siteId, mixed $cursor): array
    {
        throw new \LogicException('This driver does not use a prefix dictionary.');
    }

    protected function requirePrefixRunId(string $runId): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $runId) !== 1) {
            throw new \InvalidArgumentException('A derived rebuild requires a valid run identity.');
        }
    }
}
