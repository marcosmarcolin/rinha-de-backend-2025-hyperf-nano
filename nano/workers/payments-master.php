<?php

declare(strict_types=1);

use MarcosMarcolin\Rinha\HttpRequest as HttpRequestAlias;
use Swoole\Coroutine;
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

            if (!$data || !isset($data[1])) {
                continue;
            }

            $payload = json_decode($data[1], true);

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

            $success = HttpRequestAlias::sendPayment('default', $body);

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

for ($i = 0; $i < $coroutines; $i++) {
    Coroutine::create(function () {
        $redis = new Redis();
        $redis->connect('redis');

        while (true) {
            $data = $redis->brPop('payment_jobs_fallback', 1);

            if (!$data || !isset($data[1])) {
                continue;
            }

            $payload = json_decode($data[1], true);

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

            $success = HttpRequestAlias::sendPayment('fallback', $body);

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

Swoole\Event::wait();
