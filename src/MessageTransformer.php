<?php

declare(strict_types=1);

/*
 * This file is part of the Thunder micro CLI framework.
 * (c) Jérémy Marodon <marodon.jeremy@gmail.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RxThunder\RabbitMQ;

use Rx\Observable;
use Rxnet\RabbitMq\Message;
use RxThunder\Core\Model\DataModel;
use RxThunder\Core\Model\Payload;

final class MessageTransformer
{
    private ?int $timeout = null;

    public function defineTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    public function toDataModel(Message $message): Observable
    {
        $metadata = array_merge(
            $message->headers(),
            [
                'uid' => $message->consumerTag(),
                'stream_id' => $message->deliveryTag(),
                'stream' => $message->redelivered(),
                'date' => $message->exchange(),
            ]
        );

        $payload = new Payload($message->content());
        if (Constants::DATA_FORMAT_JSON === $message->header(Headers::CONTENT_TYPE)) {
            if (\is_array($array_data = json_decode($message->content(), true))) {
                $payload = new Payload($array_data);
            }
        }

        $data_model = new DataModel(
            $message->routingKey(),
            $payload,
            $metadata
        );

        $observable = Observable::of($data_model);

        if (null !== $this->timeout) {
            $observable = $observable->timeout($this->timeout);
        }

        return $observable;
    }
}
