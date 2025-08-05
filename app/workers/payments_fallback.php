<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Runtime;

Runtime::enableCoroutine();

function startQueueWorker(string $queue, string $processor, int $coroutines): void
{
    echo "[Worker:$processor] Iniciando com $coroutines coroutines na fila '$queue'" . PHP_EOL;

    for ($i = 0; $i < $coroutines; $i++) {
        Coroutine::create(function () use ($queue, $processor) {
            $Redis = new Redis();
            $Redis->connect('redis');

            while (true) {
                $data = $Redis->brPop($queue, 1);
                if (!$data) {
                    continue;
                }

                $payload = json_decode($data[1], false);
                if (json_last_error() !== JSON_ERROR_NONE || $Redis->exists($payload->correlationId)) {
                    continue;
                }

                $now = microtime(true);
                $requestedAt = DateTime::createFromFormat('U.u', sprintf('%.6f', $now))
                    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');

                $success = pay($processor, [
                    'correlationId' => $payload->correlationId,
                    'amount' => $payload->amount,
                    'requestedAt' => $requestedAt,
                ]);

                if (!$success) {
                    $payload = addRetry($payload);
                    if (($payload->retry ?? 0) < 2) {
                        $Redis->lPush($queue, json_encode($payload));
                        Coroutine::sleep(0.01);
                    }
                    continue;
                }

                $Redis->setex($payload->correlationId, 86400, 1);
                $entry = $payload->correlationId . ':' . ((int)round($payload->amount * 100));
                $Redis->zAdd("payments:$processor", $now, $entry);
            }
        });
    }
}

function pay(string $processor, array $data): bool
{
    $client = new Client("payment-processor-$processor", 8080);
    $client->setHeaders(['Content-Type' => 'application/json']);
    $client->set(['timeout' => 3]);
    $client->post('/payments', json_encode($data));
    $status = $client->getStatusCode();
    $client->close();

    return $status >= 200 && $status < 300;
}

function addRetry(object $payload): object
{
    $payload->retry = ($payload->retry ?? 0) + 1;
    return $payload;
}

$cpus = swoole_cpu_num();
$coroutines = min(10, $cpus * 8);

Coroutine::create(fn() => startQueueWorker('payment_jobs_fallback', 'fallback', $coroutines));

Swoole\Event::wait();
