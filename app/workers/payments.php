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

            $client = new Client('payment-processor-' . $processor, 8080);
            $client->setHeaders(['Content-Type' => 'application/json']);

            while (true) {
                $data = $redis->brPop($queue, 1);
                if (!$data) {
                    continue;
                }

                $payload = json_decode($data[1]);
                $health = (int)($redis->get('processor') ?? 1);

                if ($shouldFallback && $health === 2) {
                    $redis->lPush('payment_jobs_fallback', $data[1]);
                    Coroutine::sleep(0.02);
                    continue;
                }

                if ($health === 0) {
                    $redis->lPush('payment_jobs', $data[1]);
                    Coroutine::sleep(0.02);
                    continue;
                }

                $payload->requestedAt = gmdate(
                        'Y-m-d\TH:i:s.',
                        (int)$now = microtime(true)) . sprintf('%03d',
                        ($now - floor($now)) * 1000
                    ) . 'Z';

                $client->post('/payments', json_encode($payload));
                $status = $client->getStatusCode();

                if ($status === 200) {
                    $redis->zAdd(
                        'payments:' . $processor,
                        $now,
                        $payload->correlationId . ':' . ((int)round($payload->amount * 100))
                    );
                    continue;
                }

                if ($status === 422) {
                    echo "[Worker:$processor] Pagamento duplicado" . PHP_EOL;
                    continue;
                }

                if ($status < 200 || $status >= 300) {
                    $payload->retry = ($payload->retry ?? 0) + 1;
                    if ($payload->retry < 2) {
                        $redis->lPush($queue, json_encode($payload));
                    }
                }
            }
        });
    }
}

$cpus = swoole_cpu_num();
startWorker('payment_jobs', 'default', min(20, $cpus * 10), true);
startWorker('payment_jobs_fallback', 'fallback', min(16, $cpus * 10), false);

Swoole\Event::wait();
