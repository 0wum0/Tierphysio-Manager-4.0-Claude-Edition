<?php

declare(strict_types=1);

define('SAAS_ROOT', dirname(__DIR__));

require SAAS_ROOT . '/vendor/autoload.php';

$app = new \Saas\Core\Application(SAAS_ROOT);
$app->run();
