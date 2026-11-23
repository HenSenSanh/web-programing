<?php

namespace App\Services;

class RateLimiterService
{
    private static ?string $storageDir = null;

    public static function setStorageDir(?string $dir): void
    {
        self::$storageDir = $dir !== null ? rtrim($dir, '/\\') : null;
    }

    public static function getStorageDir(): string
    {
        if (self::$storageDir !== null) {
            return self::$storageDir;
        }
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'dineflow_ratelimit';
    }

    public static function isEnabled(): bool
    {
        $env = getenv('RATE_LIMIT_ENABLED');
        if ($env === false || $env === '') {
            return true;
        }
        return !in_array(strtolower(trim($env)), ['0', 'false', 'off', 'no'], true);
    }

    public static function resolveClientIp(): string
    {
        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return $remote !== '' ? $remote : '127.0.0.1';
    }

    /**
     * Sliding-window rate limit check.
     *
     * @return array{allowed:bool, remaining:int, retry_after:int, limit:int, count:int}
     */
    public static function check(
        string $bucket,
        string $key,
        int $maxRequests,
        int $windowSeconds,
        ?int $now = null
    ): array {
        $maxRequests = max(1, $maxRequests);
        $windowSeconds = max(1, $windowSeconds);
        $now = $now ?? time();

        if (!self::isEnabled()) {
            return [
                'allowed' => true,
                'remaining' => $maxRequests,
                'retry_after' => 0,
                'limit' => $maxRequests,
                'count' => 0,
            ];
        }

        $dir = self::getStorageDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $hash = hash('sha256', $bucket . '|' . $key);
        $file = $dir . DIRECTORY_SEPARATOR . $hash . '.json';

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            // Fail open if storage is temporarily unwritable so valid restaurant orders are never blocked.
            return [
                'allowed' => true,
                'remaining' => $maxRequests,
                'retry_after' => 0,
                'limit' => $maxRequests,
                'count' => 0,
            ];
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return [
                    'allowed' => true,
                    'remaining' => $maxRequests,
                    'retry_after' => 0,
                    'limit' => $maxRequests,
                    'count' => 0,
                ];
            }

            rewind($fp);
            $raw = stream_get_contents($fp);
            $decoded = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : [];
            $timestamps = is_array($decoded) ? $decoded : [];

            $cutoff = $now - $windowSeconds;
            $active = [];
            foreach ($timestamps as $ts) {
                $t = (int)$ts;
                if ($t > $cutoff && $t <= $now + 5) {
                    $active[] = $t;
                }
            }

            if (count($active) >= $maxRequests) {
                $oldest = min($active);
                $retryAfter = max(1, ($oldest + $windowSeconds) - $now);
                return [
                    'allowed' => false,
                    'remaining' => 0,
                    'retry_after' => $retryAfter,
                    'limit' => $maxRequests,
                    'count' => count($active),
                ];
            }

            $active[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($active));
            fflush($fp);

            return [
                'allowed' => true,
                'remaining' => max(0, $maxRequests - count($active)),
                'retry_after' => 0,
                'limit' => $maxRequests,
                'count' => count($active),
            ];
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    public static function enforce(string $bucket, string $key, int $maxRequests, int $windowSeconds): bool
    {
        $result = self::check($bucket, $key, $maxRequests, $windowSeconds);
        if (!headers_sent()) {
            header('X-RateLimit-Limit: ' . $result['limit']);
            header('X-RateLimit-Remaining: ' . $result['remaining']);
        }
        if (!$result['allowed']) {
            if (!headers_sent()) {
                header('Retry-After: ' . $result['retry_after']);
            }
            http_response_code(429);
            echo json_encode([
                'error' => 'Bạn thao tác quá nhanh, vui lòng thử lại sau ít giây.',
                'retry_after' => $result['retry_after'],
            ], JSON_UNESCAPED_UNICODE);
            return false;
        }
        return true;
    }

    public static function reset(?string $bucket = null, ?string $key = null): void
    {
        $dir = self::getStorageDir();
        if (!is_dir($dir)) {
            return;
        }
        if ($bucket !== null && $key !== null) {
            $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', $bucket . '|' . $key) . '.json';
            if (is_file($file)) {
                @unlink($file);
            }
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            @unlink($file);
        }
    }
}
