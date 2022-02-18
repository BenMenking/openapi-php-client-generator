#!/usr/bin/env php
<?php

include $_composer_autoload_path ?? 'vendor/autoload.php';

try {
    $install_path = \Composer\InstalledVersions::getInstallPath('bmenking/openapi-php-client-generator');
    require($install_path . '/include/OpenApiParser.php');
    require($install_path . '/include/ApiHandler.php');
    require($install_path . '/include/ModelHandler.php');
}
catch(OutOfBoundsException $ex) {
    require('include/OpenApiParser.php');
    require('include/ApiHandler.php');
    require('include/ModelHandler.php');
}

$cli = new Garden\Cli\Cli();

$cli->description('Convert OpenApi file into PHP apis and models')
    ->opt('file:f', 'OpenApi file', true)
    ->opt('namespace:n', 'Namespace to use, defaults to Client\\Library', false)
    ->opt('skip-composer:s', 'Skip updating composer.json', false, 'boolean');

$args = $cli->parse($argv, true);

$namespace = $args->getOpt('namespace') ?? "Client\\Library\\";

$openapi = new OpenApiParser($args->getOpt('file'), $namespace);

$openapi->parse();

exit;
