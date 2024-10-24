# StarCache: A Flexible Caching Solution for PHP

## Introduction

StarCache is a versatile PHP/WordPress&reg; caching library designed to streamline your application's performance by leveraging various caching mechanisms including Redis&reg;, Memcache&reg;, and WinCache&reg;. It offers a unified interface for managing cache operations, regardless of your chosen caching backend.

## Features

- **Dependency Injection:**  The library utilizes dependency injection for cache adapters like Memcache and Redis, allowing you to seamlessly switch between different backends without modifying your core code.
- **Multiple Cache Adapters:**  StarCache provides support for Memcache&reg;, Redis&reg;, and WordPress's internal cache, offering flexibility in choosing the optimal solution for your needs.
- **Unified API:**  The library offers a consistent API for managing cached data, including getting, setting, deleting, and flushing cache entries.
- **Clear Documentation:**  Comprehensive documentation and docblocks ensure clear understanding of the library's usage and functionality.

## Installation

You can install StarCache using Composer:

composer require maximilliangroupinc/starcache

*Use code with caution.*

## Usage

### 1. Configure Cache Adapter


```php
<?php

use StarCache;
use Memcache;
use Redis;

// Create a Memcache instance
$memcache = new Memcache();
$memcache->connect('localhost', 11211); // Connect to your Memcache server

// Create a Redis instance
$redis = new Redis();
$redis->connect('localhost', 6379); // Connect to your Redis server

// Choose your preferred cache adapter
$cacheAdapter = $memcache; // Or $redis
$cacheAdapterType = 'memcache'; // Or 'redis'

// Instantiate StarCache
$starCache = new StarCache($cacheAdapter, $cacheAdapterType);
```

### 2. Set Cache Data

```php
// Cache some data
$data = ['key' => 'value', 'another_key' => 'another_value'];
$starCache->setCachedData($data, 'my_table'); // Set data for 'my_table'
```

### 3. Get Cache Data

```php
// Retrieve cached data
$cachedData = $starCache->getCachedData('my_table');
var_dump($cachedData); // Output: array(2) { ["key"]=> string(4) "value" ["another_key"]=> string(14) "another_value" }
```

### 4. Delete Cache Data

```php
// Delete a specific cache entry
$starCache->deleteCache('my_table');
```

### 5. Flush and Reload Cache Data

```php
// Clear all cached data for a specific table
$starCache->flushReloadCachedData('my_table');
```

### 6. Close Connections

```php
// Close the cache adapter connection
$starCache->closeConnections();

Example: Using Redis
<?php

use StarCache;
use Redis;

// ... (Configure Redis connection) ...

// Create a StarCache instance using Redis
$starCache = new StarCache($redis, 'redis');

// Cache data
$starCache->setCachedData(['name' => 'John Doe'], 'user_profile');

// Retrieve data
$user = $starCache->getCachedData('user_profile');

// ... (Rest of your code) ...
```

## Use Code with Caution!


## License

StarCache is released under the Apache 2.0 License.


## Contributing

Contributions are welcome! Please see our CONTRIBUTING.md for details on how to contribute.


## Support

If you encounter any issues or have questions, please feel free to open an issue on our GitHub repository.


