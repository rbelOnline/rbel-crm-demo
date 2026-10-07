<?php

namespace App\Models\Concerns;

use App\Support\AnalyticsCache;

trait FlushesAnalyticsCache
{
    public static function bootFlushesAnalyticsCache(): void
    {
        static::saved(fn () => AnalyticsCache::flush());
        static::deleted(fn () => AnalyticsCache::flush());
    }
}
