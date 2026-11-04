<?php

namespace MadeByBramble\BrambleSearch\adapters;

use craft\helpers\App;
use MadeByBramble\BrambleSearch\Plugin;
use Redis;

/**
 * Redis Search Adapter
 *
 * Implements the search adapter using Redis as the storage backend.
 * Provides better performance and persistence than the Craft Cache adapter.
 * Recommended for production sites.
 */
class RedisSearchAdapter extends BaseSearchAdapter
{
    protected function hasPrefixDictionary(): bool
    {
        return true;
    }

    protected function validatePrefixLifecycleCursors(array $state): void
    {
        $validCleanup = static function(array $checkpoint): bool {
            if ($checkpoint === []) {
                return true;
            }
            return is_int($checkpoint['stage'] ?? null) && $checkpoint['stage'] >= 0 && $checkpoint['stage'] <= 3
                && (!isset($checkpoint['scan']) || (is_string($checkpoint['scan']) && ctype_digit($checkpoint['scan'])));
        };
        $cursor = $state['legacyCursor'];
        if (!$validCleanup($state['cleanup']) || ($cursor !== null && (!is_array($cursor)
            || !is_string($cursor['scan'] ?? null) || !ctype_digit($cursor['scan'])
            || !is_int($cursor['pass'] ?? null) || $cursor['pass'] < 0 || $cursor['pass'] > 2
            || !array_key_exists('epoch', $cursor) || ($cursor['epoch'] !== null && (!is_string($cursor['epoch']) || preg_match('/\A[a-f0-9]{32}\z/D', $cursor['epoch']) !== 1))
            || !is_array($cursor['cleanup'] ?? null) || !$validCleanup($cursor['cleanup'])))) {
            throw new \MadeByBramble\BrambleSearch\helpers\PrefixIntegrityException('Invalid Redis prefix cleanup checkpoint.');
        }
    }

    protected function prefixKeys(int $siteId, string $epoch): array
    {
        $base = $this->prefix . 'lex:v2:' . $siteId . ':' . $epoch;

        return [$base, $base . ':counts', $base . ':docs', $base . ':revision', $base . ':documents', $base . ':terms'];
    }

    protected function prefixCompanionKey(int $siteId, string $epoch, string $docId): string
    {
        return $this->prefixKeys($siteId, $epoch)[0] . ':doc:' . $docId;
    }

    protected function initializePrefixEpoch(int $siteId, string $epoch): void
    {
        $keys = $this->prefixKeys($siteId, $epoch);
        foreach (array_slice($keys, 3) as $key) {
            $this->requireRedisWrite($this->redis->rawCommand('SET', $key, '0', 'NX') !== false || $this->redis->exists($key));
        }
        $this->assertPrefixEpoch($siteId, $epoch);
    }

    protected function assertPrefixEpoch(int $siteId, string $epoch): void
    {
        $script = <<<'LUA'
local kinds = {'zset', 'hash', 'zset', 'string', 'string', 'string'}
for i, kind in ipairs(kinds) do
    local actual = redis.call('TYPE', KEYS[i]).ok
    if (i >= 4 and actual ~= kind) or (i < 4 and actual ~= 'none' and actual ~= kind) then return 0 end
end
local function counter(key)
    local raw = redis.call('GET', key)
    if not raw or (raw ~= '0' and not string.match(raw, '^[1-9]%d*$')) then return nil end
    local value = tonumber(raw)
    if not value or value >= 9007199254740990 then return nil end
    return value
end
local documents = counter(KEYS[5])
local terms = counter(KEYS[6])
if counter(KEYS[4]) == nil or documents ~= redis.call('ZCARD', KEYS[3])
    or terms ~= redis.call('ZCARD', KEYS[1]) or terms ~= redis.call('HLEN', KEYS[2]) then return 0 end
return 1
LUA;
        if ($this->readRedis(fn() => $this->redis->eval($script, $this->prefixKeys($siteId, $epoch), 6)) !== 1) {
            throw new \MadeByBramble\BrambleSearch\helpers\PrefixIntegrityException('Redis prefix epoch is missing or inconsistent.');
        }
    }

    protected function publishPrefixEpochMembership(int $siteId, string $epoch, int $elementId, array $terms, bool $exists): void
    {
        $script = <<<'LUA'
local function invalid(message)
    return redis.error_reply('BS_PREFIX_INTEGRITY ' .. message)
end
local kinds = {'zset', 'hash', 'zset', 'string', 'string', 'string', 'string'}
for i, kind in ipairs(kinds) do
    local actual = redis.call('TYPE', KEYS[i]).ok
    if actual ~= 'none' and actual ~= kind then
        return invalid('Invalid prefix membership key type')
    end
end
local function integer(value, positive)
    if not value then
        if positive then return nil end
        return 0
    end
    if value ~= '0' and not string.match(value, '^[1-9]%d*$') then return nil end
    local number = tonumber(value)
    if not number or number >= 9007199254740990 or (positive and number < 1) then return nil end
    return number
end
local revision = integer(redis.call('GET', KEYS[4]), true)
if redis.call('GET', KEYS[4]) == '0' then revision = 0 end
local documents = integer(redis.call('GET', KEYS[5]), false)
local termCount = integer(redis.call('GET', KEYS[6]), false)
if not redis.call('GET', KEYS[5]) or not redis.call('GET', KEYS[6])
    or documents == nil or termCount == nil
    or documents ~= redis.call('ZCARD', KEYS[3])
    or termCount ~= redis.call('ZCARD', KEYS[1]) or termCount ~= redis.call('HLEN', KEYS[2]) then
    return invalid('Invalid prefix epoch counters')
end
if revision == nil then return invalid('Invalid prefix revision') end
local site, element = string.match(ARGV[1], '^([1-9]%d*):([1-9]%d*)$')
if not site or not element or (ARGV[3] ~= '1' and ARGV[3] ~= '0') then
    return invalid('Invalid prefix document identity')
end
local function termSet(value)
    if type(value) ~= 'table' then return nil end
    local result = {}
    local count = 0
    for index, term in pairs(value) do
        if type(index) ~= 'number' or index < 1 or index ~= math.floor(index)
            or type(term) ~= 'string' or term == '' or result[term] then return nil end
        result[term] = true
        count = count + 1
    end
    if count ~= #value then return nil end
    return result
end
local decoded, input = pcall(cjson.decode, ARGV[2])
if not decoded then return invalid('Invalid intended prefix terms') end
local newTerms = termSet(input)
if not newTerms then return invalid('Invalid intended prefix term list') end
local newExists = ARGV[3] == '1'
if not newExists and next(newTerms) then return invalid('Deleted prefix document has terms') end
local oldTerms = {}
local oldExists = false
local previous = redis.call('GET', KEYS[7])
if previous then
    local valid, companion = pcall(cjson.decode, previous)
    if not valid or type(companion) ~= 'table' or companion.version ~= 2 or companion.epoch ~= ARGV[4]
        or companion.doc ~= ARGV[1] or companion.exists ~= true then
        return invalid('Invalid prefix companion')
    end
    oldTerms = termSet(companion.terms)
    if not oldTerms then return invalid('Invalid previous prefix term list') end
    oldExists = true
end
local tracked = redis.call('ZSCORE', KEYS[3], ARGV[1]) ~= false
if tracked ~= oldExists then return invalid('Prefix companion tracking is inconsistent') end
local removed = {}
local added = {}
for term in pairs(oldTerms) do
    if not newTerms[term] then removed[#removed + 1] = term end
end
for term in pairs(newTerms) do
    if not oldTerms[term] then added[#added + 1] = term end
end
local counts = {}
local affected = {}
for term in pairs(oldTerms) do affected[term] = true end
for term in pairs(newTerms) do affected[term] = true end
for term in pairs(affected) do
    local stored = redis.call('HGET', KEYS[2], term)
    local count = integer(stored, true)
    if not stored then count = 0 end
    local score = redis.call('ZSCORE', KEYS[1], term)
    if count == nil or (count == 0 and score) or (count > 0 and (not score or tonumber(score) ~= 0))
        or (oldTerms[term] and count < 1) then
        return invalid('Prefix reference count is inconsistent')
    end
    counts[term] = count
end
if #removed == 0 and #added == 0 and oldExists == newExists then return revision end
for _, term in ipairs(removed) do
    local count = counts[term] - 1
    if count == 0 then
        redis.call('HDEL', KEYS[2], term)
        redis.call('ZREM', KEYS[1], term)
        termCount = termCount - 1
    else
        redis.call('HSET', KEYS[2], term, string.format('%.0f', count))
    end
end
for _, term in ipairs(added) do
    local count = counts[term] + 1
    redis.call('HSET', KEYS[2], term, string.format('%.0f', count))
    if count == 1 then
        redis.call('ZADD', KEYS[1], 0, term)
        termCount = termCount + 1
    end
end
if newExists then
    local companion = '{"version":2,"epoch":' .. cjson.encode(ARGV[4]) .. ',"doc":' .. cjson.encode(ARGV[1]) .. ',"exists":true,"terms":' .. ARGV[2] .. '}'
    redis.call('SET', KEYS[7], companion)
    redis.call('ZADD', KEYS[3], 0, ARGV[1])
else
    redis.call('DEL', KEYS[7])
    redis.call('ZREM', KEYS[3], ARGV[1])
end
redis.call('SET', KEYS[5], string.format('%.0f', documents + (newExists and 1 or 0) - (oldExists and 1 or 0)))
redis.call('SET', KEYS[6], string.format('%.0f', termCount))
redis.call('INCR', KEYS[4])
return revision + 1
LUA;
        $docId = "$siteId:$elementId";
        $this->redis->clearLastError();
        try {
            $result = $this->redis->eval($script, [
                ...$this->prefixKeys($siteId, $epoch), $this->prefixCompanionKey($siteId, $epoch, $docId),
                $docId, json_encode($terms, JSON_THROW_ON_ERROR), $exists ? '1' : '0', $epoch,
            ], 7);
        } catch (\RedisException $error) {
            if (str_contains($error->getMessage(), 'BS_PREFIX_INTEGRITY')) {
                throw new \MadeByBramble\BrambleSearch\helpers\PrefixIntegrityException($error->getMessage(), 0, $error);
            }
            throw $error;
        }
        if (!is_int($result) || $result < 0) {
            $error = $this->redis->getLastError();
            if (is_string($error) && str_contains($error, 'BS_PREFIX_INTEGRITY')) {
                throw new \MadeByBramble\BrambleSearch\helpers\PrefixIntegrityException($error);
            }
            throw new \RuntimeException('Unable to publish Redis prefix membership.');
        }
    }

    protected function getTermsByPrefix(string $prefix, int $siteId, int $limit = 100): array
    {
        if ($prefix === '' || $limit <= 0) {
            return [];
        }
        return $this->prefixTermsFromActiveEpoch($prefix, $siteId, $limit) ?? parent::getTermsByPrefix($prefix, $siteId, $limit);
    }

    protected function queryPrefixEpoch(int $siteId, string $epoch, string $prefix, int $limit): array
    {
        $successor = $this->prefixByteSuccessor($prefix);
        $terms = $this->readRedis(fn() => $this->redis->rawCommand('ZRANGEBYLEX', $this->prefixKeys($siteId, $epoch)[0], '[' . $prefix, $successor === null ? '+' : '(' . $successor, 'LIMIT', '0', (string)$limit));
        $matches = [];
        foreach ($terms as $term) {
            $matches[$term] = $this->calculateTypeaheadConfidence($prefix, $term);
        }
        arsort($matches);

        return $matches;
    }

    protected function prefixDocumentPage(int $siteId, ?string $epoch, mixed $cursor, int $limit): array
    {
        if ($epoch !== null) {
            $documents = $this->readRedis(fn() => $this->redis->rawCommand('ZRANGEBYLEX', $this->prefixKeys($siteId, $epoch)[2], $cursor === null ? '-' : '(' . $cursor, '+', 'LIMIT', '0', (string)$limit));

            return ['documents' => $documents, 'cursor' => $documents === [] ? $cursor : end($documents), 'done' => count($documents) < $limit];
        }
        $cursor ??= ['scan' => '0', 'remaining' => [], 'finished' => false];
        if ($cursor['remaining'] === [] && !$cursor['finished']) {
            $page = $this->readRedis(fn() => $this->redis->rawCommand('SSCAN', $this->prefix . 'meta:docs', $cursor['scan'], 'COUNT', '100'));
            $cursor = ['scan' => (string)$page[0], 'remaining' => array_values(array_filter($page[1], static fn(string $doc): bool => $siteId === 0 || str_starts_with($doc, "$siteId:"))), 'finished' => (string)$page[0] === '0'];
        }
        $documents = array_splice($cursor['remaining'], 0, $limit);

        return ['documents' => $documents, 'cursor' => $cursor, 'done' => $cursor['remaining'] === [] && $cursor['finished']];
    }

    protected function deletePrefixKeyPage(string $key): bool
    {
        $type = $this->redis->type($key);
        if ($type === Redis::REDIS_ZSET || $type === Redis::REDIS_SET) {
            $members = $type === Redis::REDIS_ZSET
                ? $this->readRedis(fn() => $this->redis->zRange($key, 0, 499))
                : $this->readRedis(fn() => $this->redis->sRandMember($key, 500));
            if ($members !== []) {
                $result = $type === Redis::REDIS_ZSET ? $this->redis->zRem($key, ...$members) : $this->redis->sRem($key, ...$members);
                $this->requireRedisWrite($result);
                return false;
            }
        } elseif ($type === Redis::REDIS_HASH) {
            $page = $this->readRedis(fn() => $this->redis->rawCommand('HSCAN', $key, '0', 'COUNT', '500'));
            $members = [];
            for ($i = 0; $i < count($page[1]); $i += 2) {
                $members[] = $page[1][$i];
            }
            if ($members !== []) {
                $this->requireRedisWrite($this->redis->hDel($key, ...array_slice($members, 0, 500)));
                return false;
            }
        }
        $this->requireRedisWrite($this->redis->del($key));

        return true;
    }

    protected function prefixScanPattern(string $literal): string
    {
        return strtr($literal, ['\\' => '\\\\', '*' => '\\*', '?' => '\\?', '[' => '\\[', ']' => '\\]']) . '*';
    }

    protected function cleanupPrefixEpochStep(int $siteId, string $epoch, array $checkpoint, int $limit): array
    {
        $base = $this->prefixKeys($siteId, $epoch)[0];
        $stage = $checkpoint['stage'] ?? 0;
        $key = [$base, $base . ':counts', $base . ':docs'][$stage] ?? null;
        if ($key !== null) {
            if ($this->deletePrefixKeyPage($key)) {
                $stage++;
            }
            return ['done' => false, 'checkpoint' => ['stage' => $stage]];
        }
        $page = $this->readRedis(fn() => $this->redis->rawCommand('SCAN', (string)($checkpoint['scan'] ?? '0'), 'MATCH', $this->prefixScanPattern($base . ':'), 'COUNT', '5000'));
        foreach ($page[1] as $key) {
            if (!$this->deletePrefixKeyPage($key)) {
                return ['done' => false, 'checkpoint' => ['stage' => 3, 'scan' => (string)($checkpoint['scan'] ?? '0')]];
            }
        }

        return ['done' => (string)$page[0] === '0', 'checkpoint' => ['stage' => 3, 'scan' => (string)$page[0]]];
    }

    protected function cleanupLegacyPrefixStep(int $siteId, mixed $cursor): array
    {
        $cursor ??= ['scan' => '0', 'epoch' => null, 'cleanup' => [], 'pass' => 0];
        if ($cursor['epoch'] !== null) {
            $result = $this->cleanupPrefixEpochStep($siteId, $cursor['epoch'], $cursor['cleanup'], 20);
            $cursor['cleanup'] = $result['checkpoint'];
            if ($result['done']) {
                $cursor['epoch'] = null;
                $cursor['cleanup'] = [];
            }
            return ['done' => false, 'cursor' => $cursor];
        }
        $pattern = $cursor['pass'] === 0 ? $this->prefixScanPattern($this->prefix . 'lex:v1:') : $this->prefixScanPattern($this->prefix . 'lex:v2:' . $siteId . ':');
        $page = $this->readRedis(fn() => $this->redis->rawCommand('SCAN', $cursor['scan'], 'MATCH', $pattern, 'COUNT', '5000'));
        $cursor['scan'] = (string)$page[0];
        foreach ($page[1] as $key) {
            if ($cursor['pass'] === 0) {
                if (preg_match('/\Alex:v1:(?:' . $siteId . '(?::|$)|doc:' . $siteId . ':)/D', substr($key, strlen($this->prefix))) === 1) {
                    if (!$this->deletePrefixKeyPage($key)) {
                        $cursor['scan'] = '0';
                        return ['done' => false, 'cursor' => $cursor];
                    }
                }
            } elseif (preg_match('/\Alex:v2:' . $siteId . ':([a-f0-9]{32})(?::|$)/D', substr($key, strlen($this->prefix)), $match) === 1) {
                $state = $this->prefixLifecycleState($siteId);
                if (!in_array($match[1], [$state['active'], $state['building']], true)) {
                    $cursor['epoch'] = $match[1];
                    $cursor['scan'] = '0';
                    return ['done' => false, 'cursor' => $cursor];
                }
            }
        }
        if ($cursor['scan'] === '0') {
            $cursor['pass']++;
        }

        return ['done' => $cursor['pass'] > 1, 'cursor' => $cursor];
    }

    protected function readRedis(callable $read, bool $allowsMissing = false): mixed
    {
        $this->redis->clearLastError();
        $result = $read();
        if ($this->redis->getLastError() !== null || ($result === false && !$allowsMissing)) {
            throw new \RuntimeException('A Redis search read command failed.');
        }

        return $result;
    }

    protected function documentIsCatalogued(int $siteId, int $elementId): bool
    {
        return $this->readRedis(fn() => $this->redis->sIsMember($this->prefix . 'meta:docs', "$siteId:$elementId"), true);
    }

    protected function requireRedisWrite(mixed $result): void
    {
        if ($result === false || (is_array($result) && in_array(false, $result, true))) {
            throw new \RuntimeException('A Redis search persistence command failed.');
        }
    }

    protected function removePostingChanges(int $siteId, int $elementId, array $terms): void
    {
        if ($terms === []) {
            return;
        }
        $pipeline = $this->redis->multi(Redis::PIPELINE);
        foreach ($terms as $term) {
            $pipeline->zRem($this->prefix . 'term:' . $term, "$siteId:$elementId");
        }
        $this->requireRedisWrite($pipeline->exec());
    }
    protected function getIndexedDocumentIds(): array
    {
        $ids = $this->readRedis(fn() => $this->redis->sMembers($this->prefix . 'meta:docs'));
        if ($ids === false) {
            throw new \RuntimeException('Unable to enumerate the search document catalogue.');
        }

        return $ids;
    }
    protected function documentExists(int $siteId, int $elementId): bool
    {
        return $this->readRedis(fn() => $this->redis->hExists($this->prefix . "doc:$siteId:$elementId", '_length'), true);
    }
    protected function getStorageNamespace(): array
    {
        return ['driver' => 'redis', 'host' => $this->redis->getHost(), 'port' => $this->redis->getPort(), 'database' => $this->redis->getDBNum()];
    }

    protected function readIndexRecord(string $key): ?array
    {
        $value = $this->readRedis(fn() => $this->redis->get($this->prefix . 'state:' . $key), true);

        $record = $value === false ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if ($record !== null && !is_array($record) && str_starts_with($key, 'prefix_lifecycle:')) {
            throw new \MadeByBramble\BrambleSearch\helpers\PrefixIntegrityException('Invalid Redis prefix lifecycle record.');
        }

        return $record;
    }

    protected function readIndexRecordsBatch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $values = $this->readRedis(fn() => $this->redis->mGet(array_map(fn(string $key): string => $this->prefix . 'state:' . $key, $keys)));
        if ($values === false) {
            throw new \RuntimeException('Unable to batch-read search source states.');
        }
        $records = [];
        foreach ($keys as $index => $key) {
            $records[$key] = $values[$index] === false ? null : json_decode($values[$index], true, 512, JSON_THROW_ON_ERROR);
        }

        return $records;
    }

    protected function writeIndexRecord(string $key, array $value): void
    {
        if (!$this->redis->set($this->prefix . 'state:' . $key, json_encode($value, JSON_THROW_ON_ERROR))) {
            throw new \RuntimeException('Unable to persist search source state.');
        }
    }

    protected function deleteIndexRecord(string $key): void
    {
        if ($this->redis->del($this->prefix . 'state:' . $key) === false) {
            throw new \RuntimeException('Unable to remove search source state.');
        }
    }

    protected function writeIndexTotals(int $documents, int $length): void
    {
        if (!$this->redis->mSet([$this->prefix . 'meta:totalDocs' => $documents, $this->prefix . 'meta:totalLength' => $length])) {
            throw new \RuntimeException('Unable to publish search metadata totals.');
        }
    }

    protected function writePostingChanges(int $siteId, int $elementId, array $frequencies): void
    {
        if ($frequencies === []) {
            return;
        }
        $pipeline = $this->redis->multi(Redis::PIPELINE);
        foreach ($frequencies as $term => $frequency) {
            $pipeline->zAdd($this->prefix . 'term:' . $term, $frequency, "$siteId:$elementId");
        }
        $results = $pipeline->exec();
        if ($results === false || in_array(false, $results, true)) {
            throw new \RuntimeException('Unable to publish search Redis postings.');
        }
    }

    protected function publishDocumentCommit(int $siteId, int $elementId, array $state, int $count, int $length, array $publication): void
    {
        $publication['dirty'] = false;
        $script = <<<'LUA'
local expected = {'string', 'set', 'string', 'string', 'string'}
for i, kind in ipairs(expected) do
    local actual = redis.call('TYPE', KEYS[i]).ok
    if actual ~= 'none' and actual ~= kind then
        return redis.error_reply('Invalid search publication key type')
    end
end
for i = 3, 4 do
    local value = redis.call('GET', KEYS[i])
    if value and (not string.match(value, '^%d+$') or not tonumber(value)) then
        return redis.error_reply('Invalid search metadata counter')
    end
end
if not string.match(ARGV[3], '^%d+$') or not string.match(ARGV[4], '^%d+$') then
    return redis.error_reply('Invalid intended search metadata counter')
end
local source = cjson.decode(ARGV[1])
local publication = cjson.decode(ARGV[5])
if source.version ~= 2 or type(source.exists) ~= 'boolean' or publication.dirty ~= false then
    return redis.error_reply('Invalid search publication envelope')
end
redis.call('SET', KEYS[1], ARGV[1])
if source.exists then
    redis.call('SADD', KEYS[2], ARGV[2])
else
    redis.call('SREM', KEYS[2], ARGV[2])
end
redis.call('SET', KEYS[3], ARGV[3])
redis.call('SET', KEYS[4], ARGV[4])
redis.call('SET', KEYS[5], ARGV[5])
return 1
LUA;
        $result = $this->redis->eval($script, [
            $this->prefix . "state:term_sources:$siteId:$elementId",
            $this->prefix . 'meta:docs',
            $this->prefix . 'meta:totalDocs',
            $this->prefix . 'meta:totalLength',
            $this->prefix . 'state:publication',
            json_encode($state, JSON_THROW_ON_ERROR),
            "$siteId:$elementId",
            (string)$count,
            (string)$length,
            json_encode($publication, JSON_THROW_ON_ERROR),
        ], 5);
        if ($result !== 1) {
            throw new \RuntimeException('Search document publication failed.');
        }
    }
    // =========================================================================
    // PROPERTIES
    // =========================================================================

    /**
     * Redis connection instance
     */
    protected Redis $redis;

    // =========================================================================
    // INITIALIZATION METHODS
    // =========================================================================

    /**
     * Initialize the Redis connection
     */
    public function init(): void
    {
        parent::init();
        /** @var \MadeByBramble\BrambleSearch\models\Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $redisHost = App::parseEnv('$BRAMBLE_SEARCH_REDIS_HOST') ?: $settings->redisHost;
        $redisPort = (int)(App::parseEnv('$BRAMBLE_SEARCH_REDIS_PORT') ?: $settings->redisPort);
        $redisPassword = App::parseEnv('$BRAMBLE_SEARCH_REDIS_PASSWORD') ?: $settings->redisPassword;
        $redisKeyPrefix = App::parseEnv('$BRAMBLE_SEARCH_REDIS_KEY_PREFIX') ?: $settings->redisKeyPrefix;
        if ($redisKeyPrefix !== '') {
            $this->prefix = $redisKeyPrefix;
        }

        $this->redis = new Redis();
        $this->redis->connect($redisHost, $redisPort);
        if ($redisPassword) {
            $this->redis->auth($redisPassword);
        }
    }

    // =========================================================================
    // DOCUMENT OPERATIONS
    // =========================================================================

    /**
     * Get all terms for a document from Redis
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @return array The terms and their frequencies
     */
    protected function getDocumentTerms(int $siteId, int $elementId): array
    {
        $docKey = $this->prefix . "doc:{$siteId}:{$elementId}";
        $terms = $this->readRedis(fn() => $this->redis->hGetAll($docKey));

        // Handle false return
        if ($terms === false || !is_array($terms)) {
            return [];
        }

        // Remove the _length key which isn't a term
        if (isset($terms['_length'])) {
            unset($terms['_length']);
        }

        return $terms;
    }



    /**
     * Delete a document from Redis
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function deleteDocument(int $siteId, int $elementId): void
    {
        $docKey = $this->prefix . "doc:{$siteId}:{$elementId}";
        $this->requireRedisWrite($this->redis->del($docKey));
    }

    /**
     * Store a document in Redis using hash
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param array $termFreqs The terms and their frequencies
     * @param int $docLen The document length
     */
    protected function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLen): void
    {
        $docKey = $this->prefix . "doc:{$siteId}:{$elementId}";

        foreach ($termFreqs as $term => $freq) {
            $this->requireRedisWrite($this->redis->hSet($docKey, $term, $freq));
        }

        $this->requireRedisWrite($this->redis->hSet($docKey, '_length', $docLen));
    }

    /**
     * Get the length of a document in tokens from Redis
     *
     * @param string $docId The document ID (siteId:elementId)
     * @return int The document length
     */
    protected function getDocumentLength(string $docId): int
    {
        $docKey = $this->prefix . "doc:$docId";
        $result = $this->readRedis(fn() => $this->redis->hGet($docKey, '_length'), true);

        // Handle false return
        if ($result === false) {
            return 0;
        }

        return $this->requireMetadataCounter($result);
    }

    /**
     * Get document lengths for multiple documents in a single batch operation
     *
     * @param array $docIds Array of document IDs
     * @return array Associative array with docId => length
     */
    protected function getDocumentLengthsBatch(array $docIds): array
    {
        if (empty($docIds)) {
            return [];
        }

        $pipe = $this->redis->multi(\Redis::PIPELINE);
        
        // Queue all the hGet commands
        foreach ($docIds as $docId) {
            $docKey = $this->prefix . "doc:$docId";
            $pipe->hGet($docKey, '_length');
        }
        
        $results = $this->readRedis(fn() => $pipe->exec());
        $lengths = [];
        
        // Process results
        foreach ($docIds as $index => $docId) {
            $result = $results[$index] ?? false;
            $lengths[$docId] = $result === false ? 0 : $this->requireMetadataCounter($result);
        }
        
        return $lengths;
    }

    // =========================================================================
    // METADATA OPERATIONS
    // =========================================================================

    /**
     * Add a document to the index metadata
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function addDocumentToIndex(int $siteId, int $elementId): void
    {
        $metaKey = $this->prefix . "meta";
        $this->requireRedisWrite($this->redis->sAdd($metaKey . ':docs', "{$siteId}:{$elementId}"));
    }

    /**
     * Update the total document count in Redis
     */
    protected function updateTotalDocCount(): void
    {
        $metaKey = $this->prefix . "meta";
        $docsKey = $metaKey . ':docs';
        $totalDocs = $this->readRedis(fn() => $this->redis->sCard($docsKey));
        $this->requireRedisWrite($this->redis->set($metaKey . ':totalDocs', $totalDocs));
    }

    /**
     * Update the total token length in Redis
     *
     * @param int $docLen The document length to add
     */
    protected function updateTotalLength(int $docLen): void
    {
        $metaKey = $this->prefix . "meta";
        $this->requireRedisWrite($this->redis->incrBy($metaKey . ':totalLength', $docLen));
    }

    /**
     * Get the total document count from Redis
     *
     * @return int The total document count
     */
    protected function getTotalDocCount(): int
    {
        $this->ensureIndexMetadata();
        $value = $this->readRedis(fn() => $this->redis->get($this->prefix . 'meta:totalDocs'));

        return $this->requireMetadataCounter($value);
    }

    /**
     * Get the total token length from Redis
     *
     * @return int The total token length
     */
    protected function getTotalLength(): int
    {
        $this->ensureIndexMetadata();
        $value = $this->readRedis(fn() => $this->redis->get($this->prefix . 'meta:totalLength'));

        return $this->requireMetadataCounter($value);
    }

    // =========================================================================
    // TERM OPERATIONS
    // =========================================================================

    /**
     * Store a term-document association in Redis using sorted set
     *
     * @param string $term The term
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param int $freq The term frequency
     */
    protected function storeTermDocument(string $term, int $siteId, int $elementId, int $freq): void
    {
        $termKey = $this->prefix . "term:$term";
        $this->requireRedisWrite($this->redis->zAdd($termKey, $freq, "{$siteId}:{$elementId}"));
    }

    /**
     * Remove a term-document association from Redis
     *
     * @param string $term The term
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function removeTermDocument(string $term, int $siteId, int $elementId): void
    {
        $termKey = $this->prefix . "term:$term";
        $this->requireRedisWrite($this->redis->zRem($termKey, "{$siteId}:{$elementId}"));
    }

    /**
     * Get all documents containing a specific term from Redis
     *
     * @param string $term The term to look up
     * @return array The documents containing the term and their frequencies
     */
    protected function getTermDocuments(string $term): array
    {
        $termKey = $this->prefix . "term:$term";
        $result = $this->readRedis(fn() => $this->redis->zRange($termKey, 0, -1, true));

        // Ensure we always return an array
        if ($result === false || !is_array($result)) {
            return [];
        }

        return $result;
    }



    /**
     * Fetch documents for multiple terms in a single pipeline
     *
     * @param array<string> $terms
     * @return array<string, array> term => documents
     */
    protected function getTermDocumentsBatch(array $terms): array
    {
        if (empty($terms)) {
            return [];
        }

        $terms = array_values($terms);
        $pipe = $this->redis->multi(\Redis::PIPELINE);
        foreach ($terms as $term) {
            $pipe->zRange($this->prefix . "term:$term", 0, -1, true);
        }
        $results = $this->readRedis(fn() => $pipe->exec());

        $docs = [];
        foreach ($terms as $index => $term) {
            $result = $results[$index] ?? false;
            $this->requireRedisWrite($result);
            $docs[$term] = ($result === false || !is_array($result)) ? [] : $result;
        }

        return $docs;
    }

    /**
     * Fetch title terms for multiple documents in a single pipeline
     *
     * @param array<string> $docIds Document IDs (siteId:elementId)
     * @return array<string, array> docId => title terms keyed to true
     */
    protected function getTitleTermsBatch(array $docIds): array
    {
        if (empty($docIds)) {
            return [];
        }

        $docIds = array_values($docIds);
        $pipe = $this->redis->multi(\Redis::PIPELINE);
        foreach ($docIds as $docId) {
            $pipe->sMembers($this->prefix . "title:$docId");
        }
        $results = $this->readRedis(fn() => $pipe->exec());

        $titleTerms = [];
        foreach ($docIds as $index => $docId) {
            $result = $results[$index] ?? false;
            $this->requireRedisWrite($result);
            $titleTerms[$docId] = ($result === false || !is_array($result)) ? [] : array_flip($result);
        }

        return $titleTerms;
    }

    /**
     * Fetch terms for multiple documents in a single pipeline
     *
     * @param array<string> $docIds Document IDs (siteId:elementId)
     * @return array<string, array> docId => terms and their frequencies
     */
    protected function getDocumentTermsBatch(array $docIds): array
    {
        if (empty($docIds)) {
            return [];
        }

        $docIds = array_values($docIds);
        $pipe = $this->redis->multi(\Redis::PIPELINE);
        foreach ($docIds as $docId) {
            $pipe->hGetAll($this->prefix . "doc:$docId");
        }
        $results = $this->readRedis(fn() => $pipe->exec());

        $terms = [];
        foreach ($docIds as $index => $docId) {
            $result = $results[$index] ?? false;
            $this->requireRedisWrite($result);
            if ($result === false || !is_array($result)) {
                $terms[$docId] = [];
                continue;
            }
            unset($result['_length']);
            $terms[$docId] = $result;
        }

        return $terms;
    }

    /**
     * Get all terms in the index from Redis
     *
     * @return array All terms in the index
     */
    protected function getAllTerms(): array
    {
        $allKeys = $this->readRedis(fn() => $this->redis->keys($this->prefix . 'term:*'));

        // Handle false return
        if ($allKeys === false || !is_array($allKeys)) {
            return [];
        }

        $terms = [];

        foreach ($allKeys as $key) {
            $terms[] = substr($key, strlen($this->prefix . 'term:'));
        }

        return $terms;
    }

    /**
     * Remove a term from the index in Redis
     *
     * @param string $term The term to remove
     */
    protected function removeTermFromIndex(string $term): void
    {
        $termKey = $this->prefix . "term:$term";
        $this->requireRedisWrite($this->redis->del($termKey));
    }

    // =========================================================================
    // TITLE OPERATIONS
    // =========================================================================

    /**
     * Store title terms for a document in Redis
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     * @param array $titleTerms The title terms
     */
    protected function storeTitleTerms(int $siteId, int $elementId, array $titleTerms): void
    {
        $titleKey = $this->prefix . "title:{$siteId}:{$elementId}";

        // Delete existing title terms
        $this->requireRedisWrite($this->redis->del($titleKey));

        // Store new title terms
        if (!empty($titleTerms)) {
            foreach (array_keys($titleTerms) as $term) {
                $this->requireRedisWrite($this->redis->sAdd($titleKey, $term));
            }
        }
    }

    /**
     * Get title terms for a document from Redis
     *
     * @param string $docId The document ID (siteId:elementId)
     * @return array The title terms
     */
    protected function getTitleTerms(string $docId): array
    {
        $titleKey = $this->prefix . "title:$docId";
        $terms = $this->readRedis(fn() => $this->redis->sMembers($titleKey));

        if ($terms === false || !is_array($terms)) {
            return [];
        }

        return array_flip($terms); // Convert to associative array for faster lookups
    }

    /**
     * Delete title terms for a document from Redis
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function deleteTitleTerms(int $siteId, int $elementId): void
    {
        $titleKey = $this->prefix . "title:{$siteId}:{$elementId}";
        $this->requireRedisWrite($this->redis->del($titleKey));
    }

    // =========================================================================
    // SITE OPERATIONS
    // =========================================================================

    /**
     * Get all documents for a specific site from Redis
     *
     * @param int $siteId The site ID
     * @return array The document IDs
     */
    protected function getSiteDocuments(int $siteId): array
    {
        $metaKey = $this->prefix . "meta";
        $docsKey = $metaKey . ':docs';
        $allDocs = $this->readRedis(fn() => $this->redis->sMembers($docsKey));

        // Handle false return
        if ($allDocs === false || !is_array($allDocs)) {
            return [];
        }

        // Filter documents by site ID
        $sitePrefix = "$siteId:";
        $siteDocs = [];

        foreach ($allDocs as $docId) {
            if (strpos($docId, $sitePrefix) === 0) {
                $siteDocs[] = $docId;
            }
        }

        return $siteDocs;
    }

    /**
     * Remove a document from the index metadata in Redis
     *
     * @param int $siteId The site ID
     * @param int $elementId The element ID
     */
    protected function removeDocumentFromIndex(int $siteId, int $elementId): void
    {
        $metaKey = $this->prefix . "meta";
        $docsKey = $metaKey . ':docs';
        $this->requireRedisWrite($this->redis->sRem($docsKey, "{$siteId}:{$elementId}"));
    }

    /**
     * Reset the total length counter to zero in Redis
     */
    protected function resetTotalLength(): void
    {
        $metaKey = $this->prefix . "meta";
        $totalLengthKey = $metaKey . ':totalLength';
        $this->requireRedisWrite($this->redis->set($totalLengthKey, 0));
    }

    // =========================================================================
    // N-GRAM OPERATIONS
    // =========================================================================

    /**
     * Store n-grams for a term in Redis
     *
     * @param string $term The term to store n-grams for
     * @param array $ngrams Array of n-grams for the term
     * @param int $siteId The site ID
     * @return void
     */
    protected function storeTermNgrams(string $term, array $ngrams, int $siteId): void
    {
        // First, remove any existing n-grams for this term
        $this->removeTermNgrams($term, $siteId);

        if (empty($ngrams)) {
            return;
        }

        $pipe = $this->redis->multi(\Redis::PIPELINE);

        // Store each n-gram → term mapping
        foreach ($ngrams as $ngram) {
            $ngramKey = $this->prefix . "ngram:{$siteId}:{$ngram}";
            $pipe->sAdd($ngramKey, $term);
        }

        // Store term → n-gram count mapping
        $countKey = $this->prefix . "ngram_count:{$siteId}";
        $pipe->hSet($countKey, $term, count($ngrams));

        // Store term → n-grams mapping for easier cleanup
        $termNgramsKey = $this->prefix . "term_ngrams:{$siteId}:{$term}";
        foreach ($ngrams as $ngram) {
            $pipe->sAdd($termNgramsKey, $ngram);
        }

        $this->requireRedisWrite($pipe->exec());
    }

    /**
     * Get terms that have similar n-grams to the search term
     *
     * @param array $ngrams N-grams of the search term
     * @param int $siteId The site ID
     * @param float $threshold Minimum similarity threshold (0.0 - 1.0)
     * @return array Array of [term => similarity_score]
     */
    protected function getTermsByNgramSimilarity(array $ngrams, int $siteId, float $threshold): array
    {
        if (empty($ngrams)) {
            return [];
        }

        $termScores = [];
        $searchCount = count($ngrams);

        // Get all terms for each n-gram and count matches, in a single pipeline
        $ngrams = array_values($ngrams);
        $pipe = $this->redis->multi(\Redis::PIPELINE);
        foreach ($ngrams as $ngram) {
            $pipe->sMembers($this->prefix . "ngram:{$siteId}:{$ngram}");
        }
        foreach ($this->readRedis(fn() => $pipe->exec()) as $terms) {
            $this->requireRedisWrite($terms);
            if (!is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                $termScores[$term] = ($termScores[$term] ?? 0) + 1;
            }
        }

        if (empty($termScores)) {
            return [];
        }

        // Calculate Jaccard similarity for each candidate term, fetching all counts at once
        $results = [];
        $countKey = $this->prefix . "ngram_count:{$siteId}";
        $ngramCounts = $this->readRedis(fn() => $this->redis->hMGet($countKey, array_map('strval', array_keys($termScores))));

        foreach ($termScores as $term => $matchCount) {
            $termNgramCount = (int)($ngramCounts[$term] ?? 0);

            if ($termNgramCount > 0) {
                // Jaccard similarity: |intersection| / |union|
                $jaccard = $matchCount / ($termNgramCount + $searchCount - $matchCount);

                if ($jaccard >= $threshold) {
                    $results[$term] = $jaccard;
                }
            }
        }

        // Sort by similarity score (descending)
        arsort($results);

        return array_slice($results, 0, max(100, $this->fuzzySearchMaxCandidates * 5), true);
    }

    /**
     * Check if a term already has n-grams stored
     *
     * @param string $term The term to check
     * @param int $siteId The site ID
     * @return bool Whether the term has n-grams
     */
    protected function termHasNgrams(string $term, int $siteId): bool
    {
        $countKey = $this->prefix . "ngram_count:{$siteId}";
        return $this->readRedis(fn() => $this->redis->hExists($countKey, $term), true);
    }

    /**
     * Check whether a term's stored n-gram count matches the currently configured ngramSizes.
     *
     * The ngram_count hash already stores the count from when the term was indexed, so this
     * is a single cheap hGet rather than regenerating and diffing the full n-gram set.
     *
     * @param string $term The term to check
     * @param int $siteId The site ID
     * @return bool Whether the term's stored n-gram count matches current-size generation
     */
    protected function termNgramsCurrent(string $term, int $siteId): bool
    {
        $countKey = $this->prefix . "ngram_count:{$siteId}";
        $stored = $this->readRedis(fn() => $this->redis->hGet($countKey, $term), true);

        return $stored !== false && (int)$stored === count($this->generateNgrams($term));
    }

    /**
     * Clear all n-grams for a site
     *
     * @param int $siteId The site ID
     * @return void
     */
    protected function clearNgrams(int $siteId): void
    {
        // Find all n-gram keys for this site
        $ngramPattern = $this->prefix . "ngram:{$siteId}:*";
        $termNgramsPattern = $this->prefix . "term_ngrams:{$siteId}:*";
        $countKey = $this->prefix . "ngram_count:{$siteId}";

        $ngramKeys = $this->readRedis(fn() => $this->redis->keys($ngramPattern));
        $termNgramKeys = $this->readRedis(fn() => $this->redis->keys($termNgramsPattern));

        $allKeys = array_merge($ngramKeys ?: [], $termNgramKeys ?: [], [$countKey]);

        // Filter out empty values and only delete if we have keys
        $keysToDelete = array_filter($allKeys);
        if (!empty($keysToDelete)) {
            $this->requireRedisWrite($this->redis->del($keysToDelete));
        }
    }

    /**
     * Remove n-grams for a specific term
     *
     * @param string $term The term to remove n-grams for
     * @param int $siteId The site ID
     * @return void
     */
    protected function removeTermNgrams(string $term, int $siteId): void
    {
        // Get all n-grams for this term
        $termNgramsKey = $this->prefix . "term_ngrams:{$siteId}:{$term}";
        $ngrams = $this->readRedis(fn() => $this->redis->sMembers($termNgramsKey));

        if (!empty($ngrams)) {
            $pipe = $this->redis->multi(\Redis::PIPELINE);

            // Remove term from each n-gram set
            foreach ($ngrams as $ngram) {
                $ngramKey = $this->prefix . "ngram:{$siteId}:{$ngram}";
                $pipe->sRem($ngramKey, $term);
            }

            // Remove the term's n-gram count
            $countKey = $this->prefix . "ngram_count:{$siteId}";
            $pipe->hDel($countKey, $term);

            // Remove the term's n-gram list
            $pipe->del($termNgramsKey);

            $this->requireRedisWrite($pipe->exec());
        }
    }
}
