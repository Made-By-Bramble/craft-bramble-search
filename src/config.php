<?php

return [
  /*
  * Whether to enable the replace Craft's search service
  * with Bramble Search. This affects both the
  * admin Control Panel and frontend templates.
  */
  'enabled' => false,

  /*
  * Storage driver: 'craft', 'redis'
  * Craft cache is used by default and is good for testing.
  * Redis is recommended for production sites.
  */
  'storageDriver' => 'craft',

  /*
  * Redis connection settings
  */
  'redisHost' => 'localhost',
  'redisPort' => 6379,
  'redisPassword' => null,
];
