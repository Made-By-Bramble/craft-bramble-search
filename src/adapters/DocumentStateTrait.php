<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\adapters;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\helpers\App;
use MadeByBramble\BrambleSearch\helpers\IndexableElements;
use MadeByBramble\BrambleSearch\helpers\RebuildState;
use MadeByBramble\BrambleSearch\Plugin;
use yii\mutex\Mutex;

/** Shared occurrence provenance and recoverable document publication. */
trait DocumentStateTrait
{
    private array $memoSourceStates = [];
    private ?Mutex $fallbackIndexMutex = null;
    protected int $publicationDepth = 0;
    private array $heldDocumentLocks = [];
    private ?array $rebuildContext = null;

    /** @internal */
    public function withRebuildContext(int $siteId, string $runId, callable $callback): mixed
    {
        $previous = $this->rebuildContext;
        $this->rebuildContext = ['siteId' => $siteId, 'runId' => $runId];
        try {
            return $callback();
        } finally {
            $this->rebuildContext = $previous;
        }
    }

    protected function assertRebuildContext(): void
    {
        if ($this->rebuildContext === null) {
            return;
        }
        $owner = RebuildState::owner($this->getIndexIdentity(), $this->rebuildContext['siteId']);
        if ($owner === null || $owner['runId'] !== $this->rebuildContext['runId']
            || !in_array($owner['status'], ['building', 'active'], true)
            || $owner['lifecycle']['phase'] === 'cancelled') {
            throw new \RuntimeException('The search rebuild was cancelled or superseded before publication.');
        }
    }

    abstract protected function readIndexRecord(string $key): ?array;

    abstract protected function writeIndexRecord(string $key, array $value): void;

    abstract protected function deleteIndexRecord(string $key): void;

    abstract protected function writeIndexTotals(int $documents, int $length): void;

    abstract protected function documentExists(int $siteId, int $elementId): bool;

    abstract protected function getIndexedDocumentIds(): array;

    public function getIndexIdentity(): string
    {
        return hash('sha256', $this->prefix . ':' . json_encode($this->getStorageNamespace(), JSON_THROW_ON_ERROR));
    }

    protected function getStorageNamespace(): array
    {
        return ['driver' => static::class];
    }

    protected function getIndexMutex(): Mutex
    {
        return Plugin::$plugin?->getIndexMutex() ?? ($this->fallbackIndexMutex ??= Craft::createObject(App::dbMutexConfig()));
    }

    protected function withPublicationGuard(callable $callback): mixed
    {
        if ($this->publicationDepth > 0) {
            return $callback();
        }

        $mutex = $this->getIndexMutex();
        $name = 'bs:publish:' . substr($this->getIndexIdentity(), 0, 32);
        if (!$mutex->acquire($name, 10)) {
            throw new \RuntimeException('Unable to acquire the search index publication guard.');
        }

        $this->publicationDepth++;
        $failure = null;
        try {
            return $callback();
        } catch (\Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            $this->publicationDepth--;
            $this->releaseIndexLock($mutex, $name, static function(): void {
            }, $failure);
        }
    }

    protected function withDocumentIndexLock(int $siteId, int $elementId, callable $callback): bool
    {
        $name = 'bs:doc:' . substr($this->getIndexIdentity(), 0, 24) . ":$siteId:$elementId";
        if (isset($this->heldDocumentLocks[$name])) {
            return (bool)$callback();
        }
        if ($this->publicationDepth > 0) {
            throw new \LogicException('Document locks must precede publication guards.');
        }
        $mutex = $this->getIndexMutex();
        if (!$mutex->acquire($name, 10)) {
            throw new \RuntimeException('Unable to acquire the search document lock.');
        }
        $this->heldDocumentLocks[$name] = true;
        $failure = null;
        try {
            return (bool)$callback();
        } catch (\Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            $this->releaseIndexLock($mutex, $name, function() use ($name): void {
                unset($this->heldDocumentLocks[$name]);
            }, $failure);
        }
    }

    protected function releaseIndexLock(Mutex $mutex, string $name, callable $onReleased, ?\Throwable $failure = null): void
    {
        try {
            $onReleased();
            if (!$mutex->release($name)) {
                throw new \RuntimeException('Unable to release a search index advisory lock.');
            }
        } catch (\Throwable $exception) {
            if ($failure !== null) {
                throw new \RuntimeException($exception->getMessage(), 0, $failure);
            }
            throw $exception;
        }
    }

    protected function readIndexRecordsBatch(array $keys): array
    {
        $records = [];
        foreach ($keys as $key) {
            $records[$key] = $this->readIndexRecord($key);
        }

        return $records;
    }

    protected function readDocumentSourceState(int $siteId, int $elementId): ?array
    {
        $this->synchronizeSearchMemo();
        $docId = "$siteId:$elementId";
        if (!array_key_exists($docId, $this->memoSourceStates)) {
            $this->memoSourceStates[$docId] = $this->readIndexRecord('term_sources:' . $docId);
        }

        return $this->memoSourceStates[$docId];
    }

    protected function warmDocumentSourceStates(array $docIds): void
    {
        $this->synchronizeSearchMemo();
        $missing = array_values(array_filter(array_unique($docIds), fn(string $id): bool => !array_key_exists($id, $this->memoSourceStates)));
        if ($missing === []) {
            return;
        }

        $records = $this->readIndexRecordsBatch(array_map(static fn(string $id): string => 'term_sources:' . $id, $missing));
        foreach ($missing as $docId) {
            $this->memoSourceStates[$docId] = $records['term_sources:' . $docId] ?? null;
        }
    }

    protected function resolveFieldScopedMatches(array $groups, array $matches, ElementQuery $query): array
    {
        if ($query->customFields === null) {
            return [$groups, $matches];
        }
        $ids = [];
        foreach ($groups as $documents) {
            $ids += $documents;
        }
        $this->warmDocumentSourceStates(array_keys($ids));
        foreach ($groups as $groupIndex => $documents) {
            foreach ($documents as $docId => $value) {
                $winner = null;
                foreach ($matches[$groupIndex][$docId]['candidates'] ?? [] as $candidate) {
                    $candidate = $this->selectFieldScopedMatch($docId, $candidate, $query);
                    if ($candidate !== null && ($winner === null || $candidate['confidence'] > $winner['confidence'])) {
                        $winner = $candidate;
                    }
                }
                if ($winner === null) {
                    unset($groups[$groupIndex][$docId], $matches[$groupIndex][$docId]);
                } else {
                    $matches[$groupIndex][$docId] = $winner;
                }
            }
        }

        return [$groups, $matches];
    }

    protected function selectFieldScopedMatch(string $docId, array $match, ElementQuery $query): ?array
    {
        if ($this->termMatchesQueryScope($docId, $match, $query)) {
            unset($match['alternatives']);

            return $match;
        }
        $winner = null;
        foreach ($match['alternatives'] ?? [] as $alternative) {
            if ($this->termMatchesQueryScope($docId, $alternative, $query)
                && ($winner === null || $this->shouldReplaceSearchTermMatch($docId, $winner, $alternative))) {
                $winner = $alternative;
            }
        }

        return $winner;
    }

    protected function resolveQueryScopedTermMatches(array $matches, ElementQuery $query): array
    {
        if ($query->customFields === null) {
            return $matches;
        }
        $this->warmDocumentSourceStates(array_keys($matches));
        $allowed = [];
        foreach ($matches as $docId => $match) {
            $selected = $this->selectFieldScopedMatch($docId, $match, $query);
            if ($selected !== null) {
                $allowed[$docId] = $selected;
            }
        }

        return $allowed;
    }

    protected function rememberTermMatch(array &$matches, string $docId, array $data, bool $replace, bool $collectAlternatives): void
    {
        $alternatives = $collectAlternatives ? ($matches[$docId]['alternatives'] ?? (isset($matches[$docId]) ? [$matches[$docId]] : [])) : [];
        if ($replace) {
            $matches[$docId] = $data;
        }
        if ($collectAlternatives) {
            $matches[$docId]['alternatives'] = [...$alternatives, $data];
        }
    }

    protected function collectDocumentIndexData(ElementInterface $element, ?array $fieldHandles): array
    {
        $state = $this->readDocumentSourceState($element->siteId, $element->id);
        $journal = $this->readIndexRecord("journal:$element->siteId:$element->id");
        $fields = [];
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field->searchable) {
                $fields[$field->handle] = ['id' => $field->id, 'uid' => $field->uid, 'type' => $field::class];
            }
        }
        ksort($fields);
        $fingerprint = hash('sha256', json_encode([
            'normalization' => 4,
            'language' => Craft::$app->getSites()->getSiteById($element->siteId)?->language ?? 'en',
            'stopWords' => $this->stopWords,
            'ngramSizes' => $this->ngramSizes,
            'attributes' => $this->getIndexableAttributes($element),
        ], JSON_THROW_ON_ERROR));
        $physical = $this->getDocumentTerms($element->siteId, $element->id);
        ksort($physical);
        $complete = $this->isCompleteSourceState($state)
            && $state['exists']
            && $this->documentExists($element->siteId, $element->id)
            && $state['fields'] === $fields
            && $state['fingerprint'] === $fingerprint
            && $this->sourceFrequencies($state['sources']) === array_map('intval', $physical)
            && $state['length'] === $this->getDocumentLength("$element->siteId:$element->id");
        $full = $fieldHandles === null || !$complete || $journal !== null;
        $selected = $full ? array_keys($fields) : array_values(array_intersect($fieldHandles, array_keys($fields)));
        $sources = [];
        if (!$full) {
            foreach ($state['sources'] as $term => $origins) {
                foreach ($origins as $origin) {
                    if (str_starts_with($origin, 'field:')) {
                        $handle = substr($origin, 6);
                        if (isset($fields[$handle]) && !in_array($handle, $selected, true)) {
                            $sources[$term][] = $origin;
                        }
                    }
                }
            }
        }

        foreach ($this->getIndexableAttributes($element) as $attribute) {
            $keywords = $this->normalizeIndexKeywords($element, $element->getSearchKeywords($attribute), $attribute);
            foreach ($this->tokenize($keywords) as $term) {
                $sources[$term][] = 'attr:' . $attribute;
            }
        }
        foreach ($selected as $handle) {
            $field = $element->getFieldLayout()->getFieldByHandle($handle);
            $keywords = $this->normalizeIndexKeywords($element, $field->getSearchKeywords($element->getFieldValue($handle), $element), null, (int)$field->id);
            foreach ($this->tokenize($keywords) as $term) {
                $sources[$term][] = 'field:' . $handle;
            }
        }
        $titleTokens = $this->tokenize($element->title ?? '');
        foreach ($sources as $term => $origins) {
            if (in_array((string)$term, $this->stopWords, true)) {
                if (in_array((string)$term, $titleTokens, true)) {
                    $sources[$term] = ['attr:title'];
                } else {
                    unset($sources[$term]);
                }
            }
        }
        foreach ($titleTokens as $term) {
            $sources[$term] ??= ['attr:title'];
        }
        ksort($sources);
        $frequencies = $this->sourceFrequencies($sources);

        return [
            'state' => [
                'version' => 2,
                'commitId' => bin2hex(random_bytes(16)),
                'exists' => true,
                'length' => array_sum($frequencies),
                'fields' => $fields,
                'fingerprint' => $fingerprint,
                'sources' => $sources,
            ],
            'terms' => $frequencies,
            'titles' => array_flip($titleTokens),
            'full' => $full,
        ];
    }

    protected function sourceFrequencies(array $sources): array
    {
        $frequencies = array_map('count', $sources);
        ksort($frequencies);

        return $frequencies;
    }

    protected function isCompleteSourceState(?array $state): bool
    {
        if ($state === null || ($state['version'] ?? null) !== 2 || !is_string($state['commitId'] ?? null)
            || !is_bool($state['exists'] ?? null) || !is_int($state['length'] ?? null) || $state['length'] < 0
            || !is_array($state['fields'] ?? null) || !is_string($state['fingerprint'] ?? null) || !is_array($state['sources'] ?? null)) {
            return false;
        }
        foreach ($state['sources'] as $origins) {
            if (!is_array($origins) || !array_is_list($origins) || $origins === []) {
                return false;
            }
            foreach ($origins as $origin) {
                if (!is_string($origin) || preg_match('/\A(?:attr|field):.+\z/D', $origin) !== 1) {
                    return false;
                }
            }
        }

        return ($state['exists'] || ($state['length'] === 0 && $state['sources'] === []))
            && array_sum($this->sourceFrequencies($state['sources'])) === $state['length'];
    }

    protected function documentIsEligible(ElementInterface $element): bool
    {
        return IndexableElements::isEligible($element);
    }

    protected function documentIsCatalogued(int $siteId, int $elementId): bool
    {
        return in_array("$siteId:$elementId", $this->getSiteDocuments($siteId), true);
    }

    protected function publishDocumentCatalogue(int $siteId, int $elementId, bool $exists, int $length): void
    {
        if ($exists) {
            $this->addDocumentToIndex($siteId, $elementId);
        } else {
            $this->removeDocumentFromIndex($siteId, $elementId);
        }
    }

    protected function mutateDocument(ElementInterface $element, ?array $fieldHandles): bool
    {
        $this->clearSearchMemo();
        if (!$element->id || !$element->siteId) {
            return true;
        }
        if ($this->readIndexRecord("journal:$element->siteId:$element->id") !== null) {
            $current = $element::find()->id($element->id)->siteId($element->siteId)->status(null)->drafts(null)->provisionalDrafts(null)->one();
            if ($current === null) {
                return $this->deleteElementFromIndex($element->id, $element->siteId);
            }
            $element = $current;
            $fieldHandles = null;
        }
        if (!$this->documentIsEligible($element)) {
            return $this->deleteElementFromIndex($element->id, $element->siteId);
        }

        $data = $this->collectDocumentIndexData($element, $fieldHandles);

        return $this->withPublicationGuard(fn(): bool => $this->runDocumentTransaction(
            fn(): bool => $this->publishDocument($element->siteId, $element->id, $data)
        ));
    }

    protected function runDocumentTransaction(callable $callback): bool
    {
        return $callback();
    }

    protected function publicationState(): array
    {
        return $this->readIndexRecord('publication') ?? ['dirty' => false, 'pending' => [], 'initialized' => false];
    }

    protected function ensureIndexMetadata(): void
    {
        if ($this->publicationDepth > 0) {
            return;
        }

        $publication = $this->publicationState();
        if (!$publication['dirty'] && ($publication['initialized'] ?? false) && $publication['pending'] === []) {
            return;
        }

        $this->withPublicationGuard(fn(): array => $this->repairIndexMetadata($this->publicationState()));
    }

    protected function repairIndexMetadata(array $publication, ?callable $progress = null): array
    {
        if (!$publication['dirty'] && ($publication['initialized'] ?? false) && $publication['pending'] === []) {
            return $publication;
        }
        $ids = array_fill_keys(array_keys($publication['pending']), true);
        foreach ($this->getIndexedDocumentIds() as $docId) {
            $ids[$docId] = true;
        }
        $count = 0;
        $length = 0;
        $processed = 0;
        $total = count($ids);
        if ($progress !== null) {
            $progress($processed, $total);
        }
        foreach (array_keys($ids) as $docId) {
            [$siteId, $elementId] = array_map('intval', explode(':', $docId, 2));
            $state = $this->readIndexRecord('term_sources:' . $docId);
            $journal = isset($publication['pending'][$docId]) ? $this->readIndexRecord('journal:' . $docId) : null;
            $marker = $publication['pending'][$docId] ?? null;
            if (is_array($marker) && ($marker['finalizedDeletion'] ?? false)) {
                if (($journal !== null && $journal['commitId'] !== $marker['commitId']) || ($state !== null && ($state['commitId'] ?? null) !== $marker['commitId'])) {
                    throw new \RuntimeException('Inconsistent finalized search deletion identity.');
                }
                $exists = false;
                $documentLength = 0;
                $this->deleteIndexRecord('term_sources:' . $docId);
                $this->deleteIndexRecord('journal:' . $docId);
                unset($publication['pending'][$docId]);
            } elseif ($journal !== null && (!$this->isCompleteSourceState($state) || ($state['commitId'] ?? null) !== $journal['commitId'])) {
                $exists = $journal['oldExists'];
                $documentLength = $journal['oldLength'];
            } elseif ($this->isCompleteSourceState($state)) {
                $exists = $state['exists'];
                $documentLength = $state['length'];
                if ($journal === null && is_array($marker) && $marker['commitId'] === $state['commitId']) {
                    unset($publication['pending'][$docId]);
                }
            } else {
                if ($marker !== null && $journal === null) {
                    throw new \RuntimeException('A pending search mutation has lost its logical state.');
                }
                $exists = true;
                $documentLength = $this->getDocumentLength($docId);
            }
            $this->publishDocumentCatalogue($siteId, $elementId, $exists, $documentLength);
            $count += (int)$exists;
            $length += $documentLength;
            if ($progress !== null) {
                $progress(++$processed, $total);
            }
        }
        $this->writeIndexTotals($count, $length);
        $publication['dirty'] = false;
        $publication['initialized'] = true;
        $this->writeIndexRecord('publication', $publication);

        return $publication;
    }

    protected function publishDocument(int $siteId, int $elementId, array $data): bool
    {
        $this->assertRebuildContext();
        $docId = "$siteId:$elementId";
        $publication = $this->repairIndexMetadata($this->publicationState());
        $previousCount = $this->getTotalDocCount();
        $previousLength = $this->getTotalLength();
        $state = $this->readIndexRecord('term_sources:' . $docId);
        $priorJournal = $this->readIndexRecord('journal:' . $docId);
        $oldTerms = $this->getDocumentTerms($siteId, $elementId);
        if ($priorJournal !== null && (!$this->isCompleteSourceState($state) || ($state['commitId'] ?? null) !== $priorJournal['commitId'])) {
            $oldExists = $priorJournal['oldExists'];
            $oldLength = $priorJournal['oldLength'];
        } elseif ($this->isCompleteSourceState($state)) {
            $oldExists = $state['exists'];
            $oldLength = $this->documentExists($siteId, $elementId) ? $this->getDocumentLength($docId) : $state['length'];
        } else {
            $oldExists = $this->documentIsCatalogued($siteId, $elementId);
            $oldLength = $this->getDocumentLength($docId);
        }
        $sourceTerms = ($state['version'] ?? null) === 2 ? array_keys(is_array($state['sources'] ?? null) ? $state['sources'] : []) : array_keys($state ?? []);
        $journal = [
            'commitId' => $data['state']['commitId'],
            'oldExists' => $oldExists,
            'oldLength' => $oldLength,
            'oldTerms' => array_values(array_unique([...array_keys($oldTerms), ...$sourceTerms, ...($priorJournal['oldTerms'] ?? []), ...($priorJournal['intendedTerms'] ?? [])])),
            'intendedTerms' => array_keys($data['terms']),
        ];
        $this->writeIndexRecord('journal:' . $docId, $journal);
        $publication['pending'][$docId] = ['commitId' => $journal['commitId']];
        $publication['dirty'] = true;
        $this->writeIndexRecord('publication', $publication);
        $this->mutationCheckpoint('journal', $siteId, $elementId);
        $full = $data['full'] || $priorJournal !== null;
        $removed = $full ? $journal['oldTerms'] : array_keys(array_diff_key($oldTerms, $data['terms']));
        $this->removePostingChanges($siteId, $elementId, array_map('strval', $removed));
        $changed = $full ? $data['terms'] : array_filter($data['terms'], static fn(int $frequency, string|int $term): bool => (int)($oldTerms[$term] ?? 0) !== $frequency, ARRAY_FILTER_USE_BOTH);
        $this->writePostingChanges($siteId, $elementId, $changed);
        $this->mutationCheckpoint('postings', $siteId, $elementId);
        $this->deleteDocument($siteId, $elementId);
        $this->deleteTitleTerms($siteId, $elementId);
        if ($data['state']['exists']) {
            $this->storeDocument($siteId, $elementId, $data['terms'], $data['state']['length']);
            $this->storeTitleTerms($siteId, $elementId, $data['titles']);
            $this->writeDocumentNgrams($siteId, array_keys($full ? $data['terms'] : $changed), $this->getNgramFingerprint());
        }
        $this->cleanupRemovedTermState($siteId, array_values(array_diff(array_map('strval', $journal['oldTerms']), array_map('strval', array_keys($data['terms'])))));
        $this->mutationCheckpoint('beforePrefix', $siteId, $elementId);
        $this->publishPrefixMembership($siteId, $elementId, array_map('strval', array_keys($data['terms'])), $data['state']['exists'], $oldExists);
        $this->mutationCheckpoint('physical', $siteId, $elementId);
        $count = $previousCount + (int)$data['state']['exists'] - (int)$oldExists;
        $length = $previousLength + $data['state']['length'] - $oldLength;
        $this->publishDocumentCommit($siteId, $elementId, $data['state'], $count, $length, $publication);
        $this->mutationCheckpoint('committed', $siteId, $elementId);
        if (!$data['state']['exists']) {
            $journal['finalizedDeletion'] = true;
            $this->writeIndexRecord('journal:' . $docId, $journal);
            $publication['pending'][$docId] = ['commitId' => $journal['commitId'], 'finalizedDeletion' => true];
            $publication['dirty'] = false;
            $this->writeIndexRecord('publication', $publication);
            $this->mutationCheckpoint('deletionMarked', $siteId, $elementId);
            $this->deleteIndexRecord('term_sources:' . $docId);
            $this->mutationCheckpoint('deletionSourceRemoved', $siteId, $elementId);
        }
        $this->deleteIndexRecord('journal:' . $docId);
        $this->mutationCheckpoint('journalRemoved', $siteId, $elementId);
        unset($publication['pending'][$docId]);
        $publication['dirty'] = false;
        $this->writeIndexRecord('publication', $publication);
        $this->mutationCheckpoint('pendingRemoved', $siteId, $elementId);
        $this->clearSearchMemo();

        return true;
    }

    protected function publishDocumentCommit(int $siteId, int $elementId, array $state, int $count, int $length, array $publication): void
    {
        $this->writeIndexRecord("term_sources:$siteId:$elementId", $state);
        $this->mutationCheckpoint('source', $siteId, $elementId);
        $this->publishDocumentCatalogue($siteId, $elementId, $state['exists'], $state['length']);
        $this->writeIndexTotals($count, $length);
        $publication['dirty'] = false;
        $this->writeIndexRecord('publication', $publication);
    }

    protected function writePostingChanges(int $siteId, int $elementId, array $frequencies): void
    {
        foreach ($frequencies as $term => $frequency) {
            $this->storeTermDocument((string)$term, $siteId, $elementId, $frequency);
        }
    }

    protected function publishPrefixMembership(int $siteId, int $elementId, array $terms, bool $exists, bool $oldExists): void
    {
        $this->publishLifecyclePrefixMembership($siteId, $elementId, $terms, $exists);
    }

    protected function removePostingChanges(int $siteId, int $elementId, array $terms): void
    {
        foreach ($terms as $term) {
            $this->removeTermDocument($term, $siteId, $elementId);
        }
    }

    protected function getNgramFingerprint(): string
    {
        return hash('sha256', json_encode(['normalization' => 2, 'generation' => 1, 'sizes' => $this->ngramSizes], JSON_THROW_ON_ERROR));
    }

    protected function writeDocumentNgrams(int $siteId, array $terms, string $fingerprint): void
    {
        $records = $this->readIndexRecordsBatch(array_map(static fn(string|int $term): string => "ngram:$siteId:$term", $terms));
        foreach ($terms as $term) {
            $term = (string)$term;
            if (($records["ngram:$siteId:$term"]['fingerprint'] ?? null) !== $fingerprint || !$this->termNgramsCurrent($term, $siteId)) {
                $this->storeTermNgrams($term, $this->generateNgrams($term), $siteId);
                $this->writeIndexRecord("ngram:$siteId:$term", ['fingerprint' => $fingerprint]);
            }
        }
    }

    protected function cleanupRemovedTermState(int $siteId, array $terms): void
    {
        foreach ($terms as $term) {
            $documents = $this->getTermDocuments($term);
            if ($this->filterDocumentsBySite($documents, $siteId) === []) {
                $this->removeTermNgrams($term, $siteId);
                $this->deleteIndexRecord("ngram:$siteId:$term");
            }
            if ($documents === []) {
                $this->removeTermFromIndex($term);
            }
        }
    }

    protected function mutationCheckpoint(string $step, int $siteId, int $elementId): void
    {
    }

    protected function synchronizeSearchMemo(): void
    {
    }

    protected function requireMetadataCounter(mixed $value): int
    {
        $counter = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($counter === false || (!is_int($value) && !is_string($value))) {
            throw new \RuntimeException('Invalid committed search metadata counter.');
        }

        return $counter;
    }

    protected function publishDocumentDeletion(int $siteId, int $elementId): bool
    {
        return $this->withPublicationGuard(fn(): bool => $this->runDocumentTransaction(fn(): bool => $this->publishDocument($siteId, $elementId, [
            'state' => ['version' => 2, 'commitId' => bin2hex(random_bytes(16)), 'exists' => false, 'length' => 0, 'fields' => [], 'fingerprint' => '', 'sources' => []],
            'terms' => [],
            'titles' => [],
            'full' => true,
        ])));
    }
}
