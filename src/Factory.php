<?php

declare(strict_types=1);

/*
 * This file is part of the Thunder micro CLI framework.
 * (c) Jérémy Marodon <marodon.jeremy@gmail.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
