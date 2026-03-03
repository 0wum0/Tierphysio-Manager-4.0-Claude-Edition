<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('TEMPLATES_PATH', ROOT_PATH . '/templates');
define('PLUGINS_PATH', ROOT_PATH . '/plugins');
define('MIGRATIONS_PATH', ROOT_PATH . '/migrations');

require_once ROOT_PATH . '/vendor/autoload.php';

use App\Core\Application;

$app = new Application(ROOT_PATH);
$app->run();
