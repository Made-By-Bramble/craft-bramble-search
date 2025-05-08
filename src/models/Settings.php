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
   * Storage driver for the search index (craft, redis, or mysql)
   */
  public string $storageDriver = 'craft';

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
   * Define validation rules for settings
   */
  public function rules(): array
  {
    return [
      ['enabled', 'boolean'],
      ['storageDriver', 'required'],
      ['storageDriver', 'in', 'range' => ['craft', 'redis', 'mysql']],
      [['redisHost', 'redisPort'], 'required', 'when' => function ($model) {
        return $model->storageDriver === 'redis';
      }],
      ['redisPort', 'integer', 'min' => 1, 'max' => 65535],
    ];
  }
}
