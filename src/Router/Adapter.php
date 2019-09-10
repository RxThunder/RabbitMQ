<?php

/*
 * This file is part of the Thunder micro CLI framework.
 * (c) Jérémy Marodon <marodon.jeremy@gmail.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RxThunder\RabbitMQ\Router;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Rx\Observable;
use Rxnet\RabbitMq\Message;
use RxThunder\Core\Router\DataModel;
use RxThunder\Core\Router\Payload;
use RxThunder\RabbitMQ\Router\Exception\AcceptableException;
use RxThunder\RabbitMQ\Router\Exception\RejectException;
use RxThunder\RabbitMQ\Router\Exception\RetryLaterException;

final class Adapter implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var int|null
     */
    private $timeout;
    /**
     * @var bool
     */
    private $rejectToBottom;
    /**
     * @var string|null
     */
    private $delayedExchangeName;

    public function __construct()
    {
        $this->timeout = null;
        $this->rejectToBottom = false;
        $this->delayedExchangeName = null;
    }

    public function setTimeout(int $timeout)
    {
        $this->timeout = $timeout;
    }

    public function setDelayedExchangeName(string $name)
    {
        $this->delayedExchangeName = $name;
    }

    public function rejectToBottomInsteadOfNacking()
    {
        $this->rejectToBottom = true;
    }

    public function __invoke(Message $message)
    {
        $metadata = [
            'uid' => $message->consumerTag,
            'stream_id' => $message->deliveryTag,
            'stream' => $message->redelivered,
            'date' => $message->exchange,
            'headers' => $message->headers,
        ];

        $payload = new Payload($message->getData());
        if (Constants::DATA_FORMAT_JSON === $message->getHeader(Headers::CONTENT_TYPE)) {
            if (\is_array($arrayData = json_decode($message->getData(), true))) {
                $payload = new Payload($arrayData);
            }
        }

        $dataModel = new DataModel(
            $message->getRoutingKey(),
            $payload,
            $metadata
        );
        $subject = new Subject($dataModel, $message);

        $subjectObs = $subject->skip(1)->share();

        if (null !== $this->timeout) {
            // Give only x ms to execute
            $subjectObs = $subjectObs->timeout($this->timeout);
        }

        $subjectObs
            ->subscribe(
                null,
                // Return exception from the code
                function (\Throwable $e) use ($message, $dataModel) {
                    if ($e instanceof AcceptableException) {
                        $this->handleAcceptableException($message, $dataModel, $e);

                        return;
                    }

                    if ($e instanceof RejectException) {
                        $this->handleRejectException($message, $e);

                        return;
                    }

                    if ($e instanceof RetryLaterException) {
                        $this->handleRetryLaterException($message, $e);

                        return;
                    }

                    $this->handleException($message, $e);
                },
                function () use ($message) {
                    $message->ack()->subscribe(
                        null,
                        null,
                        function () use ($message) {
                            $this->logger->debug($message->getRoutingKey().' > ack');
                        }
                    );
                }
            );

        return Observable::of($subject);
    }

    private function handleAcceptableException(Message $message, DataModel $dataModel, AcceptableException $e): void
    {
        $message->ack()->subscribe(
            null,
            null,
            function () use ($e, $dataModel, $message) {
                $this->logger->notice("ack but due to acceptable exception {$message->getRoutingKey()}", [
                    'exception' => $e->getPrevious(),
                    'payload' => $message->getData(),
                    'metadata' => $dataModel->getMetadata(),
                ]);
            }
        );
    }

    private function handleException(Message $message, \Throwable $e): void
    {
        $onCompleted = function () use ($e, $message) {
            $this->logger->error("Message {$message->getRoutingKey()} has been nack", [
                'exception' => $e,
            ]);
        };

        if ($this->rejectToBottom) {
            $message->rejectToBottom()->subscribe(null, null, $onCompleted);
        } else {
            $message->nack()->subscribe(null, null, $onCompleted);
        }
    }

    private function handleRejectException(Message $message, RejectException $e): void
    {
        $message->reject(false)->subscribe(
            null,
            null,
            function () use ($e, $message) {
                $this->logger->error("Message {$message->getRoutingKey()} has been rejected", [
                    'exception' => $e,
                ]);
            }
        );
    }

    /**
     * @throws \Exception
     */
    private function handleRetryLaterException(Message $message, RetryLaterException $e)
    {
        if (null === $this->delayedExchangeName) {
            throw new \Exception('You cannot use the RetryLaterException without a delayedExchange name');
        }

        $message->retryLater($e->getDelay(), $this->delayedExchangeName)->subscribe(
            null,
            null,
            function () use ($e, $message) {
                $this->logger->warning("Message {$message->getRoutingKey()} has been requeued for later", [
                    'exception' => $e,
                ]);
            }
        );
    }
}
