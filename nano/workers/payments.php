<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Swoole\Runtime;

Runtime::enableCoroutine();

$cpus = swoole_cpu_num();
$defaultCoroutines = min(20, $cpus * 10);
$fallbackCoroutines = min(16, $cpus * 10);
$poolSize = intval(($defaultCoroutines + $fallbackCoroutines) * 1.1);
$RedisPool = new Channel($poolSize);

Coroutine::create(function () use ($poolSize, $RedisPool, $defaultCoroutines, $fallbackCoroutines) {
    for ($i = 0; $i < $poolSize; $i++) {
        $Redis = new Redis();
        $Redis->connect('redis');
        $RedisPool->push($Redis);
    }

    echo "[RedisPool] {$poolSize} conexões Redis disponíveis para Coroutines" . PHP_EOL;

    echo "[WorkerDefault] Iniciando processamento com {$defaultCoroutines} Coroutines" . PHP_EOL;

    for ($i = 0; $i < $defaultCoroutines; $i++) {
        Coroutine::create(function () use ($RedisPool) {
            while (true) {
                $redis = $RedisPool->pop();

                try {
                    $data = $redis->brPop('payment_jobs', 1);
                    $payload = getPayload($data);
                    if (!$payload || !is_object($payload) || empty($payload->correlationId) || empty($payload->amount)) {
                        continue;
                    }

                    if ($redis->exists($payload->correlationId)) {
                        continue;
                    }

                    $processor = $redis->get('processor');
                    if ($processor === 'fallback') {
                        $redis->lPush('payment_jobs_fallback', json_encode($payload));
                        continue;
                    }

                    $now = microtime(true);
                    $datetime = DateTime::createFromFormat('U.u', sprintf('%.6f', $now));
                    $requestedAt = $datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');

                    $body = [
                        'correlationId' => $payload->correlationId,
                        'amount' => $payload->amount,
                        'requestedAt' => $requestedAt,
                    ];

                    $success = pay('default', $body);

                    if (!$success) {
                        $payload = addRetry($payload);
                        if (($payload->retry ?? 0) < 2) {
                            $redis->lPush('payment_jobs', json_encode($payload));
                        }
                        continue;
                    }

                    $redis->setex($payload->correlationId, 86400, 1);
                    $entry = "{$payload->correlationId}:" . ((int)round($payload->amount * 100));
                    $redis->zAdd("payments:default", $now, $entry);
                } finally {
                    $RedisPool->push($redis);
                }
            }
        });
    }

    echo "[WorkerFallback] Iniciando processamento com {$fallbackCoroutines} Coroutines" . PHP_EOL;

    for ($i = 0; $i < $fallbackCoroutines; $i++) {
        Coroutine::create(function () use ($RedisPool) {
            while (true) {
                $redis = $RedisPool->pop();

                try {
                    $data = $redis->brPop('payment_jobs_fallback', 1);
                    $payload = getPayload($data);
                    if (!$payload || !is_object($payload) || empty($payload->correlationId) || empty($payload->amount)) {
                        continue;
                    }

                    if ($redis->exists($payload->correlationId)) {
                        continue;
                    }

                    $now = microtime(true);
                    $datetime = DateTime::createFromFormat('U.u', sprintf('%.6f', $now));
                    $requestedAt = $datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');

                    $body = [
                        'correlationId' => $payload->correlationId,
                        'amount' => $payload->amount,
                        'requestedAt' => $requestedAt,
                    ];

                    $success = pay('fallback', $body);

                    if (!$success) {
                        $payload = addRetry($payload);
                        if (($payload->retry ?? 0) < 2) {
                            $redis->lPush('payment_jobs_fallback', json_encode($payload));
                        }
                        continue;
                    }

                    $redis->setex($payload->correlationId, 86400, 1);
                    $entry = "{$payload->correlationId}:" . ((int)round($payload->amount * 100));
                    $redis->zAdd("payments:fallback", $now, $entry);
                } finally {
                    $RedisPool->push($redis);
                }
            }
        });
    }
});

function pay(string $processor, array $data): bool
{
    $client = new Client("payment-processor-{$processor}", 8080);
    $client->setHeaders([
        'Content-Type' => 'application/json',
    ]);
    $client->set(['timeout' => 2.5]);
    $client->post('/payments', json_encode($data));
    $status = $client->getStatusCode();
    $client->close();

    return $status >= 200 && $status < 300;
}

function getPayload(?array $data): ?object
{
    return isset($data[1]) ? json_decode($data[1], false) : null;
}

function addRetry(object $payload): object
{
    $payload->retry = ($payload->retry ?? 0) + 1;
    return $payload;
}

Swoole\Event::wait();
