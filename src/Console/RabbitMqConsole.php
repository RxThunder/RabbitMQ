<?php

/*
 * This file is part of the Thunder micro CLI framework.
 * (c) Jérémy Marodon <marodon.jeremy@gmail.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RxThunder\RabbitMQ\Console;

use EventLoop\EventLoop;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Rx\Scheduler;
use Rxnet\RabbitMq\Client;
use Rxnet\RabbitMq\Message;
use RxThunder\Core\Console\AbstractConsole;
use RxThunder\Core\Router\Router;
use RxThunder\RabbitMQ\Router\Adapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class RabbitMqConsole extends AbstractConsole implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public static $expression = 'rabbit:listen:broker queue [--middlewares=]* [--timeout=] [--max-retry=] [--retry-routing-key=] [--retry-exchange=] [--delayed-exchange-name=]';
    public static $description = 'RabbitMq consumer to send command to saga process manager';
    public static $argumentsAndOptions = [
        'queue' => 'Name of the queue to connect to',
        '--timeout' => 'If the timeout is reached, the message will be nacked (use -1 for no timeout)',
        '--max-retry' => 'The max retried number of times (-1 for no max retry)',
        '--retry-routing-key' => 'If the max-retry option is activated, the name of the routing key for the failed message',
        '--retry-exchange' => 'If the max-retry option is activated, the retry exchange name where to put the failed message',
        '--delayed-exchange-name' => 'The delayed exchange\'s name. This is used when you want to retry a message after a given delay. To use this you need to have an exchange with the type "x-delayed-message". See https://github.com/rabbitmq/rabbitmq-delayed-message-exchange for more information',
    ];

    public static $defaults = [
        'timeout' => 10000,
        'max-retry' => -1,
        'retry-routing-key' => '/failed-message',
        'retry-exchange' => 'amq.direct',
        'delayed-exchange-name' => 'direct.delayed',
    ];

    private $rabbit;
    private $parameterBag;
    private $router;
    private $adapter;

    public function __construct(
        Client $rabbit,
        ParameterBagInterface $parameterBag,
        Router $router,
        Adapter $adapter
    ) {
        $this->rabbit = $rabbit;
        $this->parameterBag = $parameterBag;
        $this->router = $router;
        $this->adapter = $adapter;
    }

    public function __invoke(
        string $queue,
        array $middlewares,
        int $timeout,
        int $maxRetry,
        string $retryRoutingKey,
        string $retryExchange,
        string $delayedExchangeName
    ) {
        $this->setup($timeout, $maxRetry, $delayedExchangeName);

        // You only need to set the default scheduler once
        Scheduler::setDefaultFactory(
            function () {
                // The getLoop method auto start loop
                return new Scheduler\EventLoopScheduler(EventLoop::getLoop());
            }
        );

        $this->rabbit
            ->consume($queue, 1)
            ->flatMap(function (Message $message) use ($maxRetry, $retryRoutingKey, $retryExchange) {
                // Handle the number of times the message has been tried if the option is active
                if (-1 !== $maxRetry) {
                    $tried = (int) $message->getHeader(Message::HEADER_TRIED, 0);

                    // If the max-retry is reached, send the message to a new queue and ack this
                    if ($tried >= $maxRetry) {
                        $message->headers = array_merge($message->headers, ['Failed-message-routing-key' => $message->getRoutingKey()]);

                        return $this->rabbit
                            ->produce($message->getData(), $retryRoutingKey, $retryExchange, $message->headers)
                            ->doOnCompleted(
                                function () use ($message, $retryRoutingKey) {
                                    $this->logger->debug("Message {$message->getRoutingKey()} was send to {$retryRoutingKey}");

                                    $message->ack();
                                }
                            );
                    }
                }

                return ($this->adapter)($message)
                    ->do(function ($subject) {
                        ($this->router)($subject);
                    });
            })->subscribe(
                null,
                function (\Throwable $e) {
                    $this->logger->critical(
                        $e->getMessage(),
                        ['exception' => $e]
                    );
                    EventLoop::getLoop()->stop();
                }
            );
    }

    /**
     * @param int    $timeout
     * @param int    $maxRetry
     * @param string $delayedExchangeName
     */
    private function setup(int $timeout, int $maxRetry, string $delayedExchangeName): void
    {
        if (-1 !== $timeout) {
            $this->adapter->setTimeout($timeout);
        }

        if (-1 !== $maxRetry) {
            $this->adapter->rejectToBottomInsteadOfNacking();
        }

        $this->adapter->setDelayedExchangeName($delayedExchangeName);
    }
}
