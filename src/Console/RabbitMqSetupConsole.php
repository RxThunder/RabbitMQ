<?php

declare(strict_types=1);

/*
 * This file is part of the Thunder micro CLI framework.
 * (c) Jérémy Marodon <marodon.jeremy@gmail.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RxThunder\RabbitMQ\Console;

use Bunny\Channel;
use Bunny\Protocol\MethodQueueDeclareOkFrame;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Rx\Observable;
use Rx\Scheduler;
use Rxnet\RabbitMq\Client;
use Rxnet\RabbitMq\Exchange;
use Rxnet\RabbitMq\Queue;
use RxThunder\Core\Console;
use RxThunder\ReactPHP\EventLoop;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

final class RabbitMqSetupConsole extends Console implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public static string $expression  = 'rabbit:setup [path]';
    public static string $description = 'Create queue and binding';

    /** @var array<string, string> */
    public static array $arguments_and_options = ['path' => 'Path where binding file are stored'];

    /** @var array<string, bool|float|int|string> */
    public static array $defaults = ['path' => '/external/rabbitmq'];

    protected string $path;

    private Client $rabbit;
    private ParameterBagInterface $parameter_bag;

    public function __construct(
        Client $rabbit,
        ParameterBagInterface $parameter_bag
    ) {
        $this->rabbit        = $rabbit;
        $this->parameter_bag = $parameter_bag;
    }

    public function __invoke(string $path): void
    {
        // You only need to set the default scheduler once
        Scheduler::setDefaultFactory(
            static function () {
                // The getLoop method auto start loop
                return new Scheduler\EventLoopScheduler(EventLoop::loop());
            }
        );

        $this->path = $path;

        $finder = new Finder();
        $finder->files()->in(
            sprintf(
                '%s%s/queues',
                $this->parameter_bag->get('thunder.project_dir'),
                $this->path
            )
        );

        $queues = Observable::fromArray(
            array_map(
                static function (SplFileInfo $file) {
                    return [
                        $file->getBasename('.' . $file->getExtension()),
                        json_decode($file->getContents(), true),
                    ];
                },
                iterator_to_array($finder)
            ) ?? []
        );

        $this->rabbit->channel()
            ->combineLatest([$queues])
            ->flatMap(function ($channel_queue) {
                [$channel, $queue_data] = $channel_queue;

                if (!$channel instanceof Channel) {
                    throw new \InvalidArgumentException();
                }

                if (!is_array($queue_data)) {
                    throw new \InvalidArgumentException();
                }

                [$queue_name, $bindings] = $queue_data;

                if (!is_string($queue_name)) {
                    throw new \InvalidArgumentException();
                }

                if (!is_array($bindings)) {
                    throw new \InvalidArgumentException();
                }

                $binds = [];
                foreach ($bindings as $routing_key => $exchanges) {
                    foreach ($exchanges as $exchange) {
                        $binds[] = [$routing_key, $exchange];
                    }
                }

                $binds = Observable::fromArray($binds);

                return (new Queue($queue_name, $channel))
                    ->create()
                    ->do(function (MethodQueueDeclareOkFrame $frame): void {
                        $this->logger->debug('Queue ' . $frame->queue . ' created');
                    })
                    ->combineLatest([$binds], static function (MethodQueueDeclareOkFrame $frame, $bind) use ($channel) {
                        return [new Queue($frame->queue, $channel), $bind, $channel];
                    })
                    ->flatMap(function ($queue_with_binding) {
                        [$queue, $binding, $channel] = $queue_with_binding;

                        if (!$queue instanceof Queue) {
                            throw new \InvalidArgumentException();
                        }

                        if (!$channel instanceof Channel) {
                            throw new \InvalidArgumentException();
                        }

                        [$routing_key, $exchange] = $binding;

                        if (!is_string($routing_key)) {
                            throw new \InvalidArgumentException();
                        }

                        if (!is_string($exchange)) {
                            throw new \InvalidArgumentException();
                        }

                        return (new Exchange($exchange, $channel))
                            ->create(Exchange::TYPE_DIRECT, [Exchange::DURABLE])
                            ->flatMap(function () use ($exchange, $routing_key, $queue) {
                                return $queue
                                    ->bind($routing_key, $exchange)
                                    ->do(
                                        function () use ($queue, $exchange, $routing_key): void {
                                            $this->logger->debug('Queue ' . $queue->name() . ' was bind to exchange ' . $exchange . ' with routing_key ' . $routing_key);
                                        }
                                    );
                            });
                    })
                    ->takeLast(1);
            })
            ->subscribe(
                null,
                function (\Throwable $throwable): void {
                    $this->logger->error($throwable->getMessage(), ['exception' => $throwable]);
                    EventLoop::loop()->stop();
                },
                function (): void {
                    $this->logger->info('All queues and binding done');
                    EventLoop::loop()->stop();
                }
            );

        EventLoop::loop()->run();
    }
}
