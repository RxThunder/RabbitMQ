<?php

declare(strict_types=1);

/*
 * This file is part of the Thunder micro CLI framework.
 * (c) Jérémy Marodon <marodon.jeremy@gmail.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RxThunder\RabbitMQ\Console;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Rx\Observable;
use Rx\Scheduler;
use Rxnet\RabbitMq\Client;
use Rxnet\RabbitMq\Message;
use RxThunder\Core\Console;
use RxThunder\Core\Model\DataModel;
use RxThunder\Core\Router\Router;
use RxThunder\RabbitMQ\MessageTransformer;
use RxThunder\RabbitMQ\Observer\AmqpMessageObserver;
use RxThunder\ReactPHP\EventLoop;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class RabbitMqConsole extends Console implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public static string $expression  = 'rabbit:listen:broker queue [--timeout=] [--max_retry=] [--retry_routing_key=] [--retry_exchange=] [--delayed_exchange_name=]';
    public static string $description = 'RabbitMq consumer to send command to saga process manager';

    /** @var array<string, string> */
    public static array $arguments_and_options = [
        'queue' => 'Name of the queue to connect to',
        '--timeout' => 'If the timeout is reached, the message will be nacked (use -1 for no timeout)',
        '--max_retry' => 'The max retried number of times (-1 for no max retry)',
        '--retry_routing_key' => 'If the max-retry option is activated, the name of the routing key for the failed message',
        '--retry_exchange' => 'If the max-retry option is activated, the retry exchange name where to put the failed message',
        '--delayed_exchange_name' => 'The delayed exchangeʼs name. This is used when you want to retry a message after a given delay. To use this you need to have an exchange with the type "x-delayed-message". See https://github.com/rabbitmq/rabbitmq-delayed-message-exchange for more information',
    ];

    /** @var array<string, bool|float|int|string> */
    public static array $defaults = [
        'timeout' => 10000,
        'max_retry' => -1,
        'retry_routing_key' => '/failed-message',
        'retry_exchange' => 'amq.direct',
        'delayed_exchange_name' => 'direct.delayed',
    ];

    private Client $rabbit;
    private ParameterBagInterface $parameter_bag;
    private Router $router;

    public function __construct(
        Client $rabbit,
        ParameterBagInterface $parameter_bag,
        Router $router
    ) {
        $this->rabbit        = $rabbit;
        $this->parameter_bag = $parameter_bag;
        $this->router        = $router;
    }

    public function __invoke(
        string $queue,
        int $timeout,
        int $max_retry,
        string $retry_routing_key,
        string $retry_exchange,
        string $delayed_exchange_name
    ): void {
        // You only need to set the default scheduler once
         Scheduler::setDefaultFactory(
             static function () {
                // The getLoop method auto start loop
                return new Scheduler\EventLoopScheduler(EventLoop::loop());
             }
         );

        $this->rabbit
            ->consume($queue, 1)
            ->do(function (Message $message) use ($delayed_exchange_name, $timeout, $max_retry, $retry_routing_key, $retry_exchange): void {
                // Handle the number of times the message has been tried if the option is active
                if (-1 !== $max_retry) {
                    $tried = (int) $message->header(Message::HEADER_TRIED, 0);

                    // If the max-retry is reached, send the message to a new queue and ack this
                    if ($tried >= $max_retry) {
                        $message->addHeader('Failed-message-routing-ke', $message->routingKey());

                        $this->rabbit
                            ->produce($message->content(), $retry_routing_key, $retry_exchange, $message->headers())
                            ->flatMap(
                                function () use ($message, $retry_routing_key): Observable {
                                    $this->logger->debug("Message {$message->routingKey()} was send to {$retry_routing_key}");

                                    return $message->ack();
                                }
                            )
                            ->subscribe();

                        return;
                    }
                }

                $message_transformer = new MessageTransformer();

                if (-1 !== $timeout) {
                    $message_transformer->defineTimeout($timeout);
                }

                $observer = new AmqpMessageObserver($message, $this->logger);

                if (-1 !== $max_retry) {
                    $observer->rejectToBottomInsteadOfNacking();
                }

                $observer->defineDelayedExchangeName($delayed_exchange_name);

                $message_transformer
                    ->toDataModel($message)
                    ->flatMap(function (DataModel $data_model): Observable {
                        return $this->router->match($data_model->type())($data_model);
                    })
                    ->subscribe($observer);
            })->subscribe(
                null,
                function (\Throwable $throwable): void {
                    $this->logger->critical(
                        $throwable->getMessage(),
                        ['exception' => $throwable]
                    );
                    EventLoop::loop()->stop();
                }
            );

        EventLoop::loop()->run();
    }
}
