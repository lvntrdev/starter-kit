<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Illuminate\Support\Facades\Redis;
use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Throwable;

/**
 * Redis bağlantısını test eder.
 * Cache driver redis değilse ya da REDIS_HOST tanımlı değilse uyarı döner.
 * Timeout: 2 saniye (plan gerekliliği).
 */
class RedisConnectionCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.redis_connection.name');
    }

    public function run(): DoctorReport
    {
        $cacheDriver = config('cache.default', 'file');
        $sessionDriver = config('session.driver', 'file');

        // Redis kullanılmıyorsa skip
        if ($cacheDriver !== 'redis' && $sessionDriver !== 'redis') {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.redis_connection.not_used', ['cache' => $cacheDriver, 'session' => $sessionDriver]),
                (string) __('sk-doctor.redis_connection.not_used_hint')
            );
        }

        try {
            // 2 saniye timeout: socket bağlantısını bloke etmemek için
            $redisHost = config('database.redis.default.host', '127.0.0.1');
            $redisPort = (int) config('database.redis.default.port', 6379);

            $socket = @stream_socket_client(
                "tcp://{$redisHost}:{$redisPort}",
                $errno,
                $errstr,
                2.0
            );

            if ($socket === false) {
                throw new \RuntimeException("TCP connection to Redis failed: {$errstr} ({$errno})");
            }

            fclose($socket);

            // Gerçek Redis ping
            Redis::ping();

            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.redis_connection.connected', ['host' => $redisHost, 'port' => $redisPort])
            );
        } catch (Throwable $e) {
            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.redis_connection.connection_failed', ['error' => $e->getMessage()]),
                (string) __('sk-doctor.redis_connection.connection_failed_hint')
            );
        }
    }
}
