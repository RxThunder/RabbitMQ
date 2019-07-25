<?php

declare(strict_types=1);

namespace RxThunder\RabbitMQ;

use EventLoop\EventLoop;
use Rxnet\RabbitMq\Client;

final class Factory
{
    public static function createWithVoryxEventLoop(
        string $host,
        string $port,
        string $vhost,
        string $user,
        string $password
    ): Client {
        return new Client(
            EventLoop::getLoop(),
            [
                'host' => $host,
                'port' => $port,
                'vhost' => $vhost,
                'user' => $user,
                'password' => $password,
            ]
        );
    }
}
