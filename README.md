# frankenphp-slim

FrankenPHP Worker runtime for Slim 4 framework。

## install

```bash
composer require  y7ut/frankenphp-slim
```

## usage

create `worker.php` like this.

```php
<?php

declare(strict_types=1);

use Y7ut\FrankenPhpSlim\WorkerRunner;

require __DIR__ . '/../vendor/autoload.php';

WorkerRunner::runFromBootstrap(__DIR__ . '/../config/bootstrap.php');
// or use run function with app instance
// $app = $container->get(App::class);
// $worker = new WorkerRunner()
// $worker->run($app)

```

`config/bootstrap.php` need return  `Slim\App` object instance.

