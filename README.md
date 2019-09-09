<p align="center"><img src="./resources/thunder-logo.svg"></p>

<p align="center">
<a href="https://packagist.org/packages/rxthunder/rabbitmq"><img src="https://poser.pugx.org/rxthunder/rabbitmq/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/rxthunder/rabbitmq"><img src="https://poser.pugx.org/rxthunder/rabbitmq/d/total.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/rxthunder/rabbitmq"><img src="https://poser.pugx.org/rxthunder/rabbitmq/license.svg" alt="License"></a>
</p>
<p align="center">
<a href="https://travis-ci.org/RxThunder/Rabbitmq"><img src="https://travis-ci.org/RxThunder/Rabbitmq.svg?branch=master" alt="Build"></a>
<p align="center">


## Installation 

```
composer install rxthunder/rabbitmq
```

## Setup

First you must add new secrets in your .env files

```
# .env
RABBIT_HOST=
RABBIT_PORT=
RABBIT_VHOST=
RABBIT_USER=
RABBIT_PASSWORD=
```

Then configure new parameters to be injected in the container

```php
# config/parameters.php

$container->setParameter('rabbit.host', getenv('RABBIT_HOST'));
$container->setParameter('rabbit.port', getenv('RABBIT_PORT'));
$container->setParameter('rabbit.vhost', getenv('RABBIT_VHOST'));
$container->setParameter('rabbit.user', getenv('RABBIT_USER'));
$container->setParameter('rabbit.password', getenv('RABBIT_PASSWORD'));
```

Finally you must register an instance of [RxNet/RabbitMq](https://github.com/Rxnet/rabbitmq) 
client in the container.

You can do your own factory but a default one is embed in the plugin 
using the [voryx/event-loop](https://github.com/voryx/event-loop) static getter.

```php
# config/services.php

use Rxnet\RabbitMq\Client;
use RxThunder\RabbitMQ\Factory;

$asynchRabbitMQDefinition = $container->register(Client::class)
    ->setFactory([Factory::class, 'createWithVoryxEventLoop'])
    ->addArgument('%rabbit.host%')
    ->addArgument('%rabbit.port%')
    ->addArgument('%rabbit.vhost%')
    ->addArgument('%rabbit.user%')
    ->addArgument('%rabbit.password%')
    ->setPublic(false)
    ->setAutowired(false)
    ->setAutoconfigured(true);
```

```php
# config/services.php

require_once __DIR__ . '/../vendor/rxthunder/rabbitmq/config/services.php';

// Register RabbitMQ consoles
$consoleDefinition = new Definition();
$consoleDefinition->setPublic(true);
$consoleDefinition->setAutowired(true);
$consoleDefinition->setAutoconfigured(true);

$this->registerClasses($consoleDefinition, 'RxThunder\\RabbitMQ\\Console\\', '../vendor/rxthunder/rabbitmq/src/Console/*');
```

Or if you prefer you can include

```php
# config/services.php

require_once __DIR__ . '/../vendor/rxthunder/rabbitmq/config/services.php';
require_once __DIR__ . '/../vendor/rxthunder/rabbitmq/config/consoles.php';
```

## Profit

You got new consoles !

```
php vendor/bin/thunder
```



