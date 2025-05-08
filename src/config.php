<?php

return [
  /*
  * Whether to enable the replace Craft's search service
  * with Bramble Search. This affects both the
  * admin Control Panel and frontend templates.
  */
  'enabled' => false,

  /*
  * Storage driver: 'redis', 'file', 'mysql', 'mongodb', 'craft'
  * Redis is recommended for production sites with highest performance needs.
  * File storage is recommended for sites without external dependencies and with custom binary format.
  * MySQL is recommended for production sites with large content volumes.
  * MongoDB is recommended for sites with complex content structures and high scalability needs.
  * Craft cache is used by default and is good for testing but has limited persistence.
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
