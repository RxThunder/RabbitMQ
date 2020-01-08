<?php

use RxThunder\RabbitMQ\MessageTransformer;

$asynchRabbitMQDefinition = $container->register(MessageTransformer::class)
    ->setPublic(false)
    ->setAutowired(false)
    ->setAutoconfigured(true);
