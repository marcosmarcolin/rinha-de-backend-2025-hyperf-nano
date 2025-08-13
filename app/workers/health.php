<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Swoole\Event;
use Swoole\Runtime;

Runtime::enableCoroutine();

$Redis = new \Redis();
$Redis->connect('redis');

echo "[WorkerHealth] Starting monitoring..." . PHP_EOL;

Coroutine::create(function () use ($Redis) {
    while (true) {
        $channel = new Channel(2);

        Coroutine::create(fn() => $channel->push(['default' => checkProcessorHealth('payment-processor-default')]));
        Coroutine::create(fn() => $channel->push(['fallback' => checkProcessorHealth('payment-processor-fallback')]));

        $results = [];
        for ($i = 0; $i < 2; $i++) {
            $data = $channel->pop();
            if (is_array($data)) {
                $results = array_merge($results, $data);
            }
        }

        $channel->close();
        $best = chooseProcessor($results);

        $Redis->setex('processor', 7, $best);
        echo "[WorkerHealth] [" . date('Y-m-d H:i:s') . "] Current processor: " . match ($best) {
                1 => 'default',
                2 => 'fallback',
                0 => 'off',
                default => 'unknown',
            } . PHP_EOL;

        Coroutine::sleep(5);
    }
});

function checkProcessorHealth(string $host): ?array
{
    $client = new Client($host, 8080);
    $client->set(['timeout' => 1]);
    $client->setHeaders([
        'Host' => $host,
        'Accept' => 'application/json',
    ]);

    $client->get('/payments/service-health');

    $statusCode = $client->getStatusCode();
    $body = $client->getBody();
    $client->close();

    if ($statusCode === 200 && $body !== false) {
        return json_decode($body, true);
    }

    return null;
}

function chooseProcessor(array $hosts): int
{
    $default = $hosts['default'] ?? null;
    $fallback = $hosts['fallback'] ?? null;

    $defaultHealthy = is_array($default) && ($default['failing'] ?? true) === false;
    $fallbackHealthy = is_array($fallback) && ($fallback['failing'] ?? true) === false;

    if ($defaultHealthy && $fallbackHealthy) {
        $defaultTime = (int)($default['minResponseTime'] ?? PHP_INT_MAX);
        $fallbackTime = (int)($fallback['minResponseTime'] ?? PHP_INT_MAX);

        return $defaultTime <= $fallbackTime ? 1 : 2;
    }

    if ($defaultHealthy) {
        return 1;
    }
    if ($fallbackHealthy) {
        return 2;
    }

    return 0;
}

Event::wait();