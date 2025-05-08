<?php

return [
  /*
  * Whether to enable the replace Craft's search service
  * with Bramble Search. This affects both the
  * admin Control Panel and frontend templates.
  */
  'enabled' => false,

  /*
  * Storage driver: 'craft', 'redis', 'mysql', 'mongodb'
  * Craft cache is used by default and is good for testing.
  * Redis is recommended for production sites with high performance needs.
  * MySQL is recommended for production sites with large content volumes.
  * MongoDB is recommended for sites with complex content structures and high scalability needs.
  */
  'storageDriver' => 'craft',

  /*
  * Redis connection settings
  */
  'redisHost' => 'localhost',
  'redisPort' => 6379,
  'redisPassword' => null,

  /*
  * MongoDB connection settings
  */
  'mongoDbUri' => 'mongodb://localhost:27017',
  'mongoDbDatabase' => 'bramble_search',
];
