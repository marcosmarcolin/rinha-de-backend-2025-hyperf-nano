<?php

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

$redis = new Redis();
$redis->connect('redis');

$server = new Server("0.0.0.0", 9501);

$server->on("start", function () {
    echo "[Swoole] Server started on port 9501" . PHP_EOL;
});

$server->on("request", function (Request $request, Response $response) use ($redis) {
    $path = $request->server['request_uri'] ?? '/';
    $method = $request->server['request_method'] ?? 'GET';

    if ($method === 'POST' && $path === '/payments') {
        $response->end();
        $body = json_decode($request->rawContent() ?: '{}', false);
        $redis->lPush('payment_jobs', json_encode([
            'correlationId' => $body->correlationId,
            'amount' => $body->amount
        ]));
    }

    if ($method === 'GET' && $path === '/purge-payments') {
        $redis->flushAll();
        $response->status(204);
        return $response->end();
    }

    if ($method === 'GET' && $path === '/payments-summary') {
        $from = toFloatTimestamp($request->get['from'] ?? null) ?? '-inf';
        $to = toFloatTimestamp($request->get['to'] ?? null) ?? '+inf';
        $summary = [];

        foreach (['default', 'fallback'] as $processor) {
            $key = "payments:$processor";
            $results = $redis->zRangeByScore($key, $from, $to) ?: [];
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