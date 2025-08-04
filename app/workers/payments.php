<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Runtime;

Runtime::enableCoroutine();

$cpus = swoole_cpu_num();
$coroutines = min(20, $cpus * 10);

Coroutine::create(function () use ($coroutines) {
    echo "[WorkerPayments] Iniciando com $coroutines Coroutines" . PHP_EOL;

    for ($i = 0; $i < $coroutines; $i++) {
        Coroutine::create(function () {
            $Redis = new Redis();
            $Redis->connect('redis');
            while (true) {
                try {
                    $data = $Redis->brPop('payment_jobs', 1);
                    $payload = getPayload($data);

                    if (
                        !$payload ||
                        !is_object($payload) ||
                        empty($payload->correlationId) ||
                        empty($payload->amount)
                    ) {
                        continue;
                    }

                    if ($Redis->exists($payload->correlationId)) {
                        continue;
                    }

                    $processor = $Redis->get('processor');
                    $processor = is_string($processor) && $processor !== '' ? $processor : 'default';

                    $now = microtime(true);
                    $datetime = DateTime::createFromFormat('U.u', sprintf('%.6f', $now));
                    $requestedAt = $datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');

                    $body = [
                        'correlationId' => $payload->correlationId,
                        'amount' => $payload->amount,
                        'requestedAt' => $requestedAt,
                    ];

                    $success = pay($processor, $body);

                    if (!$success) {
                        $payload = addRetry($payload);
                        if (($payload->retry ?? 0) < 2) {
                            $Redis->lPush('payment_jobs', json_encode($payload));
                        }
                        continue;
                    }

                    $Redis->setex($payload->correlationId, 86400, 1);
                    $entry = "$payload->correlationId:" . ((int)round($payload->amount * 100));
                    $Redis->zAdd("payments:$processor", $now, $entry);
                } finally {
                    // TODO
                }
            }
        });
    }
});

function pay(string $processor, array $data): bool
{
    $client = new Client("payment-processor-{$processor}", 8080);
    $client->setHeaders(['Content-Type' => 'application/json']);
    $client->set(['timeout' => 3]);
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
