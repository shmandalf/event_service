<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class RateLimitEvents
{
    public function handle(Request $request, Closure $next)
    {
        $key = 'rate_limit:' . $request->ip() . ':' . ($request->input('user_id') ?? 'anonymous');

        $current = Redis::get($key);

        if ($current && $current >= 100) { // 100 запросов в минуту
            return response()->json([
                'error' => 'Too many requests',
                'retry_after' => Redis::ttl($key),
            ], 429);
        }

        Redis::multi()
            ->incr($key)
            ->expire($key, 60)
            ->exec();

        return $next($request);
    }
}
