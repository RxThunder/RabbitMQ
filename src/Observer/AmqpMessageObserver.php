<?php

declare(strict_types=1);

/*
 * This file is part of the Thunder micro CLI framework.
 * (c) Jérémy Marodon <marodon.jeremy@gmail.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RxThunder\RabbitMQ\Observer;

use Psr\Log\LoggerInterface;
use Rx\Observer\AbstractObserver;
use Rxnet\RabbitMq\Message;
use RxThunder\RabbitMQ\Exception\AcceptableException;
use RxThunder\RabbitMQ\Exception\RejectException;
use RxThunder\RabbitMQ\Exception\RetryLaterException;

final class AmqpMessageObserver extends AbstractObserver
{
    private Message $message;
    private bool $reject_to_bottom;
    private ?string $delayed_exchange_name = null;
    private LoggerInterface $logger;

    public function __construct(Message $message, LoggerInterface $logger)
    {
        $this->message               = $message;
        $this->reject_to_bottom      = false;
        $this->delayed_exchange_name = null;
        $this->logger                = $logger;
    }

    public function defineDelayedExchangeName(string $name): void
    {
        $this->delayed_exchange_name = $name;
    }

    public function rejectToBottomInsteadOfNacking(): void
    {
        $this->reject_to_bottom = true;
    }

    /**
     * @param mixed $value
     */
    protected function next($value): void
    {
    }

    protected function error(\Throwable $throwable): void
    {
        if ($throwable instanceof AcceptableException) {
            $this->handleAcceptableException($throwable);

            return;
        }

        if ($throwable instanceof RejectException) {
            $this->handleRejectException($throwable);

            return;
        }

        if ($throwable instanceof RetryLaterException) {
            $this->handleRetryLaterException($throwable);

            return;
        }

        $this->handleException($throwable);
    }

    protected function completed(): void
    {
        $this->message->ack()->subscribe(
            null,
            null,
            function (): void {
                $this->logger->debug($this->message->routingKey() . ' > ack');
            }
        );
    }

    private function handleAcceptableException(AcceptableException $acceptable_exception): void
    {
        $this->message->ack()->subscribe(
            null,
            null,
            function () use ($acceptable_exception): void {
                $this->logger->notice("ack but due to acceptable exception {$this->message->routingKey()}", [
                    'exception' => $acceptable_exception->getPrevious(),
                    'content' => $this->message->content(),
                    'headers' => $this->message->headers(),
                ]);
            }
        );
    }

    private function handleException(\Throwable $throwable): void
    {
        $on_completed = function () use ($throwable): void {
            $this->logger->error("Message {$this->message->routingKey()} has been nack", ['exception' => $throwable]);
        };

        if ($this->reject_to_bottom) {
            $this->message->rejectToBottom()->subscribe(null, null, $on_completed);

            return;
        }

        $this->message->nack()->subscribe(null, null, $on_completed);
    }

    private function handleRejectException(RejectException $reject_exception): void
    {
        $this->message->reject(false)->subscribe(
            null,
            null,
            function () use ($reject_exception): void {
                $this->logger->error("Message {$this->message->routingKey()} has been rejected", ['exception' => $reject_exception]);
            }
        );
    }

    private function handleRetryLaterException(RetryLaterException $retry_later_exception): void
    {
        if (null === $this->delayed_exchange_name) {
            $this->handleRejectException(new RejectException(
                $retry_later_exception->dataModel(),
                $retry_later_exception->getPrevious(),
                $retry_later_exception->getMessage(),
                $retry_later_exception->getCode(),
            ));

            return;
        }

        $this->message->retryLater($retry_later_exception->getDelay(), $this->delayed_exchange_name)->subscribe(
            null,
            null,
            function () use ($retry_later_exception): void {
                $this->logger->warning("Message {$this->message->routingKey()} has been requeued for later", ['exception' => $retry_later_exception]);
            }
        );
    }
}
