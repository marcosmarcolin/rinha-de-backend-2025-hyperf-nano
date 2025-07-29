<?php

declare(strict_types=1);

use MarcosMarcolin\Rinha\HttpRequest as HttpRequestAlias;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Runtime;

require_once __DIR__ . '/../vendor/autoload.php';

Runtime::enableCoroutine();

$coroutines = swoole_cpu_num() * 2;
echo "[WorkerDefault] Iniciando processamento com {$coroutines} Coroutines" . PHP_EOL;

for ($i = 0; $i < $coroutines; $i++) {
    Coroutine::create(function () {
        $redis = new Redis();
        $redis->connect('redis');

        while (true) {
            $data = $redis->brPop('payment_jobs', 1);

            $payload = isset($data[1]) ? json_decode($data[1], true) : null;
            if (!$payload) {
                continue;
            }

            if (!is_array($payload) || empty($payload['correlationId']) || empty($payload['amount'])) {
                continue;
            }

            $correlationId = $payload['correlationId'];
            $amount = (float)$payload['amount'];

            if ($redis->exists($correlationId)) {
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
                'correlationId' => $correlationId,
                'amount' => $amount,
                'requestedAt' => $requestedAt,
            ];

            $success = pay('default', $body);

            if (!$success) {
                $redis->lPush('payment_jobs', json_encode($payload));
                continue;
            }

            $redis->setex($correlationId, 86400, 1);
            $entry = "{$correlationId}:" . ((int)round($amount * 100));
            $redis->zAdd("payments:default", $now, $entry);
        }
    });
}

echo "[WorkerFallback] Iniciando processamento com {$coroutines} Coroutines" . PHP_EOL;

function getPayload(?array $data): ?array
{
    return isset($data[1]) ? json_decode($data[1], true) : null;
}

for ($i = 0; $i < $coroutines; $i++) {
    Coroutine::create(function () {
        $redis = new Redis();
        $redis->connect('redis');

        while (true) {
            $data = $redis->brPop('payment_jobs_fallback', 1);

            $payload = getPayload($data);
            if (!$payload) {
                continue;
            }

            if (!is_array($payload) || empty($payload['correlationId']) || empty($payload['amount'])) {
                continue;
            }

            $correlationId = $payload['correlationId'];
            $amount = (float)$payload['amount'];

            if ($redis->exists($correlationId)) {
                continue;
            }

            $now = microtime(true);
            $datetime = DateTime::createFromFormat('U.u', sprintf('%.6f', $now));
            $requestedAt = $datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');

            $body = [
                'correlationId' => $correlationId,
                'amount' => $amount,
                'requestedAt' => $requestedAt,
            ];

            $success = pay('fallback', $body);

            if (!$success) {
                $redis->lPush('payment_jobs_fallback', json_encode($payload));
                continue;
            }

            $redis->setex($correlationId, 86400, 1);
            $entry = "{$correlationId}:" . ((int)round($amount * 100));
            $redis->zAdd("payments:fallback", $now, $entry);
        }
    });
}

function pay(string $processor, array $data): bool
{
    $client = new Client("payment-processor-{$processor}", 8080);
    $client->setHeaders([
        'Content-Type' => 'application/json',
    ]);
    $client->set(['timeout' => 2]);
    $client->post('/payments', json_encode($data));
    $status = $client->getStatusCode();
    $client->close();

    return $status >= 200 && $status < 300;
}

Swoole\Event::wait();
