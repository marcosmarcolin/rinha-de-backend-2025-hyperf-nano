<?php

declare(strict_types=1);

namespace MarcosMarcolin\Rinha;

use Swoole\Coroutine\Http\Client;

class HttpRequest
{
    public const URI_PAYMENTS = '/payments';
    public const URI_PAYMENTS_SUMMARY = '/payments-summary';
    public const URI_PAYMENTS_PURGE = '/purge-payments';

    public static function sendPayment(string $processor, array $data): bool
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
}