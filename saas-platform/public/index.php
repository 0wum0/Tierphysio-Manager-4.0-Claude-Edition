<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

define('SAAS_ROOT', dirname(__DIR__));

require SAAS_ROOT . '/vendor/autoload.php';

$app = new \Saas\Core\Application(SAAS_ROOT);
$app->run();
