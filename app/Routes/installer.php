<?php

declare(strict_types=1);

use App\Controllers\InstallerController;

/** @var \App\Core\Router $router */

$router->get('/', [InstallerController::class, 'index']);
$router->get('/install', [InstallerController::class, 'index']);
$router->get('/install/schritt/{step}', [InstallerController::class, 'index']);
$router->get('/install/fertig', [InstallerController::class, 'fertig']);
$router->post('/install/check-db', [InstallerController::class, 'checkDb']);
$router->post('/install/schritt/2', [InstallerController::class, 'runStep2']);
$router->post('/install/schritt/3', [InstallerController::class, 'runStep3']);
$router->post('/install/finalize', [InstallerController::class, 'finalizeInstall']);
