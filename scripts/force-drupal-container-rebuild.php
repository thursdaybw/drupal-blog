<?php

declare(strict_types=1);

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require __DIR__ . '/../vendor/autoload.php';
$appRoot = __DIR__ . '/../html';
chdir($appRoot);
$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod', TRUE, $appRoot);
$kernel->invalidateContainer();
$kernel->boot();

fwrite(STDOUT, "Drupal container rebuilt.\n");
