<?php

// Register RabbitMQ consoles
use Symfony\Component\DependencyInjection\Definition;

$consoleDefinition = new Definition();
$consoleDefinition->setPublic(true);
$consoleDefinition->setAutowired(true);
$consoleDefinition->setAutoconfigured(true);

$this->registerClasses($consoleDefinition, 'RxThunder\\RabbitMQ\\Console\\', __DIR__ . '/../src/Console/*');
