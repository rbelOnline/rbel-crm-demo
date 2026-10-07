<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Version-stamped cache for dashboard/analytics queries.
 *
 * Any write to clients/policies/beneficiaries bumps the version, which
 * invalidates every cached analytics result at once without needing tags
 * (the database cache store does not support tags).
 */
class AnalyticsCache
{
    private const VERSION_KEY = 'analytics:version';

    private const TTL_SECONDS = 600;

    public static function remember(string $key, array $params, Closure $callback): mixed
    {
        ksort($params);
        $version = Cache::get(self::VERSION_KEY, 1);
        $cacheKey = sprintf('analytics:v%s:%s:%s', $version, $key, md5(json_encode($params)));

        return Cache::remember($cacheKey, self::TTL_SECONDS, $callback);
    }

    public static function flush(): void
    {
        if (! Cache::has(self::VERSION_KEY)) {
            Cache::forever(self::VERSION_KEY, 1);
        }

        Cache::increment(self::VERSION_KEY);
    }
}
