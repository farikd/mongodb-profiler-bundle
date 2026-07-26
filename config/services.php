<?php

declare(strict_types=1);

use Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Public: Bundle::boot() fetches it straight from the container to register it with
    // the driver's process-wide subscriber registry.
    $services->set('mongodb_profiler.subscriber', ProfilerSubscriber::class)
        ->args([
            param('mongodb_profiler.cli'),
            param('mongodb_profiler.max_queries'),
            param('mongodb_profiler.ignored_commands'),
            param('mongodb_profiler.ignored_trace_prefixes'),
        ])
        ->tag('kernel.reset', ['method' => 'reset'])
        ->public();

    $services->alias(ProfilerSubscriber::class, 'mongodb_profiler.subscriber')->public();
};
