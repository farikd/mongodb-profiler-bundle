<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Imported by the consuming app under its own dev/test conditions — a bundle cannot register
 * routes itself, and that is deliberate: the route then exists only where the app wants it.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('_mongodb_profiler_explain', '/_mongodb-profiler/explain/{token}/{index}')
        ->controller('mongodb_profiler.explain_controller')
        ->methods(['POST'])
        ->requirements(['index' => '\d+']);
};
