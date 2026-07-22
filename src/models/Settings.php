<?php

namespace MadeByBramble\BrambleSearch\models;

use craft\base\Model;

/**
 * Bramble Search Settings Model
 *
 * Defines the configurable settings for the Bramble Search plugin
 */
class Settings extends Model
{
    /**
     * Whether the search plugin is enabled
     */
    public bool $enabled = false;

    /**
     * Storage driver for the search index (craft, redis, mysql, mongodb, or file)
     */
    public string $storageDriver = 'mysql';

    /**
     * Redis server hostname or IP address
     */
    public string $redisHost = 'localhost';

    /**
     * Redis server port
     */
    public int $redisPort = 6379;

    /**
     * Redis server password (if required)
     */
    public string|null $redisPassword = null;

    /**
     * Redis key prefix
     */
    public string $redisKeyPrefix = 'bramble_search:';

    /**
     * MongoDB connection URI
     */
    public string $mongoDbUri = 'mongodb://localhost:27017';

    /**
     * MongoDB database name
     */
    public string $mongoDbDatabase = 'craft_search';

    /**
     * BM25 k1 parameter - controls term saturation (default: 1.5)
     */
    public float $bm25K1 = 1.5;

    /**
     * BM25 b parameter - controls document length normalization (default: 0.75)
     */
    public float $bm25B = 0.75;

    /**
     * Title boost factor - multiplier for terms found in title fields (default: 5.0)
     */
    public float $titleBoostFactor = 5.0;

    /**
     * Exact match boost factor - multiplier for exact phrase matches (default: 3.0)
     */
    public float $exactMatchBoostFactor = 3.0;

    /**
     * N-gram sizes to generate for fuzzy matching (default: [1, 2, 3])
     */
    public array $ngramSizes = [1, 2, 3];

    /**
     * Minimum n-gram similarity threshold for fuzzy search candidates (default: 0.25)
     */
    public float $ngramSimilarityThreshold = 0.25;

    /**
     * Maximum number of candidates to process for fuzzy matching (default: 100)
     */
    public int $fuzzySearchMaxCandidates = 100;

    /**
     * Match-ratio threshold above which a demotable AND group is treated as optional rather
     * than a hard requirement, so a common term can't single-handedly gate out otherwise
     * relevant results. Values <= 0 disable proactive demotion (default: 0.5)
     */
    public float $commonTermDemotionThreshold = 0.5;

    /**
     * Whether front-end site searches treat the final query token as an in-progress
     * prefix (search-as-you-type). The control panel element search always does.
     */
    public bool $siteSearchAsYouType = false;

    /**
     * Additional stop words merged into the bundled English list.
     *
     * @var string[]
     */
    public array $extraStopWords = [];

    /**
     * Stop words removed from the bundled English list (e.g. `back` for health queries).
     *
     * @var string[]
     */
    public array $removeStopWords = [];

    /**
     * @param array<int, string>|string $value
     */
    public function setExtraStopWords(array|string $value): void
    {
        $this->extraStopWords = self::normalizeStopWordList($value);
    }

    /**
     * @param array<int, string>|string $value
     */
    public function setRemoveStopWords(array|string $value): void
    {
        $this->removeStopWords = self::normalizeStopWordList($value);
    }

    /**
     * @param array<int, string>|string $value
     * @return string[]
     */
    public static function normalizeStopWordList(array|string $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $normalized = [];
        foreach ($value as $word) {
            $word = strtolower(trim((string)$word));
            if ($word !== '') {
                $normalized[] = $word;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param string[] $bundled
     * @param string[] $extra
     * @param string[] $remove
     * @return string[]
     */
    public static function mergeStopWordLists(array $bundled, array $extra, array $remove): array
    {
        return array_values(array_unique(array_diff(array_merge($bundled, $extra), $remove)));
    }

    /**
     * Define validation rules for settings
     */
    public function rules(): array
    {
        return [
      ['enabled', 'boolean'],
      ['storageDriver', 'required'],
      ['storageDriver', 'in', 'range' => ['redis', 'file', 'mysql', 'mongodb', 'craft']],
      [['redisHost', 'redisPort'], 'required', 'when' => function($model) {
          return $model->storageDriver === 'redis';
      }],
      ['redisKeyPrefix', 'string'],
      ['redisPort', 'integer', 'min' => 1, 'max' => 65535],
      [['mongoDbUri', 'mongoDbDatabase'], 'required', 'when' => function($model) {
          return $model->storageDriver === 'mongodb';
      }],
      // BM25 parameter validation
      ['bm25K1', 'number', 'min' => 0.1, 'max' => 5.0],
      ['bm25B', 'number', 'min' => 0.0, 'max' => 1.0],
      ['titleBoostFactor', 'number', 'min' => 1.0, 'max' => 20.0],
      ['exactMatchBoostFactor', 'number', 'min' => 1.0, 'max' => 20.0],
      // N-gram parameter validation
      ['ngramSizes', 'each', 'rule' => ['integer', 'min' => 1, 'max' => 5]],
      ['ngramSimilarityThreshold', 'number', 'min' => 0.0, 'max' => 1.0],
      ['fuzzySearchMaxCandidates', 'integer', 'min' => 10, 'max' => 1000],
      ['commonTermDemotionThreshold', 'number', 'min' => 0.0, 'max' => 1.0],
      ['siteSearchAsYouType', 'boolean'],
      [['extraStopWords', 'removeStopWords'], 'each', 'rule' => ['string']],
    ];
    }
}
