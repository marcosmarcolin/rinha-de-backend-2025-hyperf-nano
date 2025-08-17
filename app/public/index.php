<?php

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Runtime;

Runtime::enableCoroutine();

function makeRedis(): Redis
{
    $Redis = new Redis();
    $Redis->connect('redis', 6379, 0.2);
    $Redis->setOption(Redis::OPT_READ_TIMEOUT, 0.2);
    return $Redis;
}

function redisPoolChannel(?Channel $set = null): ?Channel
{
    static $ch = null;
    if ($set instanceof Channel) {
        $ch = $set;
    }
    return $ch;
}

function redisPoolInit(Server $server): void
{
    $server->on('WorkerStart', function () {
        Coroutine::create(function () {
            $ch = new Channel(20);
            for ($i = 0; $i < 20; $i++) {
                $ch->push(makeRedis());
            }
            redisPoolChannel($ch);
            echo '[Swoole Server] Redis pool ready with 20 conns' . PHP_EOL;
        });
    });
}

function redisBorrow(): array
{
    $ch = redisPoolChannel();
    if (!$ch instanceof Channel) {
        $conn = makeRedis();
        return [$conn, false];
    }
    $conn = $ch->pop(0.05);
    $fromPool = $conn instanceof Redis;
    if (!$fromPool) {
        $conn = makeRedis();
    } else {
        try {
            $conn->ping();
        } catch (Throwable) {
            $conn = makeRedis();
        }
    }
    return [$conn, $fromPool];
}

function redisRelease(Redis $conn, bool $fromPool): void
{
    $ch = redisPoolChannel();
    if ($fromPool && $ch instanceof Channel) {
        $ch->push($conn);
    } else {
        try {
            $conn->close();
        } catch (Throwable) {
        }
    }
}

$server = new Server("0.0.0.0", 9501);
$server->set(['worker_num' => 1]);

redisPoolInit($server);

$server->on("start", function () {
    echo '[Swoole Server] Started on port 9501' . PHP_EOL;
});

$server->on("request", function (Request $request, Response $response) {
    $path = $request->server['request_uri'] ?? '/';
    $method = $request->server['request_method'] ?? 'GET';

    if ($method === 'POST' && $path === '/payments') {
        $response->end();
        /** @var Redis $Redis */
        [$Redis, $fromPool] = redisBorrow();
        try {
            $Redis->lPush('payment_jobs', $request->rawContent() ?: '{}');
        } finally {
            redisRelease($Redis, $fromPool);
        }
    }

    if ($method === 'GET' && $path === '/purge-payments') {
        [$Redis, $fromPool] = redisBorrow();
        try {
            $Redis->flushAll();
        } finally {
            redisRelease($Redis, $fromPool);
        }
        $response->status(204);
        return $response->end();
    }

    if ($method === 'GET' && $path === '/payments-summary') {
        $from = toFloatTimestamp($request->get['from'] ?? null) ?? '-inf';
        $to = toFloatTimestamp($request->get['to'] ?? null) ?? '+inf';
        $summary = [];

        [$Redis, $fromPool] = redisBorrow();
        try {
            foreach (['default', 'fallback'] as $processor) {
                $key = "payments:$processor";
                $results = $Redis->zRangeByScore($key, $from, $to) ?: [];
                $totalAmountInCents = 0;
                foreach ($results as $entry) {
                    $parts = explode(':', $entry);
                    $amountInCents = isset($parts[1]) ? (int)$parts[1] : (int)$parts[0];
                    $totalAmountInCents += $amountInCents;
                }
                $summary[$processor] = [
                    'totalRequests' => count($results),
                    'totalAmount' => round($totalAmountInCents / 100, 2),
                ];
            }
        } finally {
            redisRelease($Redis, $fromPool);
        }

        $response->header('Content-Type', 'application/json');
        return $response->end(json_encode($summary));
    }

    $response->status(404);
    $response->header('Content-Type', 'application/json');
    return $response->end();
});

function toFloatTimestamp(?string $dateString): ?float
{
    if (!$dateString) {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $dateString)
        ?: DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $dateString);

    return $date ? (float)$date->format('U.u') : null;
}

$server->start();