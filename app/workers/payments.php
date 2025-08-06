<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Runtime;

Runtime::enableCoroutine();

function startWorker(string $queue, string $processor, int $coroutines, bool $shouldFallback): void
{
    echo "[Worker:$processor] Iniciando processamento com {$coroutines} Coroutines" . PHP_EOL;

    for ($i = 0; $i < $coroutines; $i++) {
        Coroutine::create(function () use ($queue, $processor, $shouldFallback) {
            $redis = new Redis();
            $redis->connect('redis');

            while (true) {
                $data = $redis->brPop($queue, 1);
                if (!$data) {
                    continue;
                }

                $payload = json_decode($data[1]);

                $health = (int)($redis->get('processor') ?? 1);

                if ($shouldFallback && $health === 2) {
                    $redis->lPush('payment_jobs_fallback', json_encode($payload));
                    Coroutine::sleep(0.01);
                    continue;
                }

                if ($health === 0) {
                    $redis->lPush($queue, json_encode($payload));
                    Coroutine::sleep(0.01);
                    continue;
                }

                $now = microtime(true);
                $requestedAt = DateTime::createFromFormat('U.u', sprintf('%.6f', $now))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s.v\Z');

                $status = sendPayment($processor, [
                    'correlationId' => $payload->correlationId,
                    'amount' => $payload->amount,
                    'requestedAt' => $requestedAt,
                ]);

                if ($status === 422) {
                    echo "[Worker:$processor] Pagamento duplicado" . PHP_EOL;
                    continue;
                }

                if ($status < 200 || $status >= 300) {
                    $payload->retry = ($payload->retry ?? 0) + 1;
                    if ($payload->retry < 2) {
                        $redis->lPush($queue, json_encode($payload));
                    }
                    continue;
                }

                $entry = $payload->correlationId . ':' . ((int)round($payload->amount * 100));
                $redis->zAdd('payments:' . $processor, $now, $entry);
            }
        });
    }
}

function sendPayment(string $processor, array $data): int
{
    $client = new Client('payment-processor-' . $processor, 8080);
    $client->setHeaders(['Content-Type' => 'application/json']);
    $client->post('/payments', json_encode($data));
    $status = $client->getStatusCode();
    $client->close();

    return $status;
}

$cpus = swoole_cpu_num();
startWorker('payment_jobs', 'default', min(20, $cpus * 10), true);
startWorker('payment_jobs_fallback', 'fallback', min(16, $cpus * 10), false);

Swoole\Event::wait();
