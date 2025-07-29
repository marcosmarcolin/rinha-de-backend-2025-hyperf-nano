<?php

declare(strict_types=1);

use Hyperf\Nano\Factory\AppFactory;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Redis\Redis;
use Hyperf\Redis\RedisFactory;
use MarcosMarcolin\Rinha\HttpRequest as HttpRequestAlias;

require_once __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();
$app->config(require __DIR__ . '/../config/app.php');
$container = $app->getContainer();

$app->post(HttpRequestAlias::URI_PAYMENTS, function () use ($container) {
    $request = $container->get(RequestInterface::class);
    $response = $container->get(ResponseInterface::class);

    $body = $request->getParsedBody();
    $correlationId = $body['correlationId'] ?? null;
    $amount = $body['amount'] ?? null;

    if (!$correlationId || !is_numeric($amount)) {
        return $response->withStatus(400)->json(['error' => 'Invalid payload']);
    }

    $job = [
        'correlationId' => $correlationId,
        'amount' => (float)$amount,
        'requestedAt' => gmdate('Y-m-d\TH:i:s.u\Z'),
    ];
    $redis = $container->get(RedisFactory::class)->get('default');
    $redis->lPush('payment_jobs', json_encode($job));

    return $response->withStatus(200)->json(['message' => 'Accepted']);
});

$app->get(HttpRequestAlias::URI_PAYMENTS_PURGE, function () use ($container) {
    $response = $container->get(ResponseInterface::class);
    $redis = $container->get(Redis::class);
    $redis->flushAll();
    return $response->withStatus(204);
});

$app->get(HttpRequestAlias::URI_PAYMENTS_SUMMARY, function () use ($container) {
    $request = $container->get(RequestInterface::class);
    $response = $container->get(ResponseInterface::class);
    $redis = $container->get(Redis::class);
    $data = $request->all();
    $from = toFloatTimestamp($data['from'] ?? null) ?? '-inf';
    $to = toFloatTimestamp($data['to'] ?? null) ?? '+inf';

    foreach (['default', 'fallback'] as $processor) {
        $key = "payments:$processor";
        $results = $redis->zRangeByScore($key, $from, $to) ?: [];
        $totalAmountInCents = 0;
        foreach ($results as $entry) {
            $parts = explode(':', $entry);
            $amountInCents = isset($parts[1]) ? (int)$parts[1] : (int)$parts[0];
            $totalAmountInCents += $amountInCents;
        }
        $summary[$processor]['totalRequests'] = count($results);
        $summary[$processor]['totalAmount'] = round($totalAmountInCents / 100, 2);
    }

    return $response->withStatus(200)->json($summary);
});

$app->run();

function toFloatTimestamp(?string $dateString): ?float
{
    if (!$dateString) {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $dateString)
        ?: DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $dateString);

    return $date ? (float)$date->format('U.u') : null;
}
