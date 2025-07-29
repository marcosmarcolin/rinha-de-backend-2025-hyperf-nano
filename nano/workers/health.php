<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Swoole\Event;
use Swoole\Runtime;

require_once __DIR__ . '/../vendor/autoload.php';

Runtime::enableCoroutine();

$redis = new \Redis();
$redis->connect('redis');

echo "[WorkerHealth] Iniciando monitoramento..." . PHP_EOL;

Coroutine::create(function () use ($redis) {
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

        if ($best) {
            $redis->setex('processor', 7, $best);
        }

        Coroutine::sleep(5);
    }
});

function checkProcessorHealth(string $host): ?array
{
    $client = new Client($host, 8080);
    $client->set(['timeout' => 2.5]);
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

function chooseProcessor(array $hosts): ?string
{
    $default = $hosts['default'] ?? null;
    $fallback = $hosts['fallback'] ?? null;

    if (is_array($default) && ($default['failing'] ?? true) === false) {
        return 'default';
    }
    if (is_array($fallback) && ($fallback['failing'] ?? true) === false) {
        return 'fallback';
    }

    return null;
}

Event::wait();