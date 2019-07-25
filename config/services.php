<?php

use RxThunder\RabbitMQ\Router\Adapter;

$asynchRabbitMQDefinition = $container->register(Adapter::class)
    ->setPublic(false)
    ->setAutowired(false)
    ->setAutoconfigured(true);
