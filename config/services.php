<?php

declare(strict_types=1);

use Farikd\MongodbProfilerBundle\Console\ProfileConsoleListener;
use Farikd\MongodbProfilerBundle\Console\ProfileSummary;
use Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber;
use Farikd\MongodbProfilerBundle\Profiler\FilterFormatter;
use Farikd\MongodbProfilerBundle\Profiler\MongoDataCollector;
use Farikd\MongodbProfilerBundle\Twig\ExplainUrlExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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

    $services->set('mongodb_profiler.filter_formatter', FilterFormatter::class);

    $services->set('mongodb_profiler.data_collector', MongoDataCollector::class)
        ->args([service('mongodb_profiler.subscriber')])
        ->tag('data_collector', [
            'template' => '@MongodbProfiler/mongodb.html.twig',
            'id' => MongoDataCollector::class,
        ]);

    // Public on purpose, though nothing in this package fetches it: it is the handle a
    // consuming app's functional test uses to assert on captured queries, and a private
    // alias is unreachable outside a test container.
    $services->alias(MongoDataCollector::class, 'mongodb_profiler.data_collector')->public();

    $services->set('mongodb_profiler.twig.explain_url', ExplainUrlExtension::class)
        ->args([service('router'), param('mongodb_profiler.explain_configured')])
        ->tag('twig.extension');

    $services->set('mongodb_profiler.console.summary', ProfileSummary::class);

    $services->set('mongodb_profiler.console.listener', ProfileConsoleListener::class)
        ->args([service('mongodb_profiler.subscriber'), service('mongodb_profiler.console.summary')])
        ->tag('kernel.event_listener', ['event' => 'console.terminate']);
};
