<?php

namespace MadeByBramble\BrambleSearch\models;

use craft\base\Model;

class Settings extends Model
{
  public bool $enabled = false;
  public string $storageDriver = 'craft';
  public string $redisHost = 'localhost';
  public int $redisPort = 6379;
  public string|null $redisPassword = null;

  public function rules(): array
  {
    return [
      ['enabled', 'boolean'],
      ['storageDriver', 'required'],
      ['storageDriver', 'in', 'range' => ['craft', 'redis']],
      [['redisHost', 'redisPort'], 'required', 'when' => function ($model) {
        return $model->storageDriver === 'redis';
      }],
      ['redisPort', 'integer', 'min' => 1, 'max' => 65535],
    ];
  }
}
