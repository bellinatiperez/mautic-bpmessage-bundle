<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire(false)
        ->autoconfigure(false)
        ->public();

    // Exclude repositories from service container
    $services->exclude('../Entity/*Repository.php');
};
