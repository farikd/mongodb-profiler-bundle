<?php

declare(strict_types=1);

use Farikd\MongodbProfilerBundle\Explain\ExplainCommandBuilder;
use Farikd\MongodbProfilerBundle\Explain\ExplainPlanParser;
use Farikd\MongodbProfilerBundle\Explain\ExplainRunner;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('mongodb_profiler.explain_command_builder', ExplainCommandBuilder::class);
    $services->set('mongodb_profiler.explain_plan_parser', ExplainPlanParser::class);

    // Public: nothing in this container references it yet (the explain controller lands in
    // Task 8), so an unreferenced private service is stripped entirely by
    // RemoveUnusedDefinitionsPass — `has()` would report the feature absent even with both
    // preconditions configured. Public keeps it (and, by reference, the builder/parser below
    // it depends on) compiled in regardless of what else consumes it.
    //
    // @todo PROVISIONAL — remove this `->public()` once Task 8's `mongodb_profiler.explain_controller`
    // constructor-injects this service: a real reference keeps it compiled in without the
    // marker, and the (necessarily public, so a stable target) controller id should become
    // what container-registration tests assert against instead of this one.
    $services->set('mongodb_profiler.explain_runner', ExplainRunner::class)
        ->args([
            param('mongodb_profiler.explain_uri'),
            param('mongodb_profiler.explain_database'),
            service('mongodb_profiler.explain_command_builder'),
            service('mongodb_profiler.explain_plan_parser'),
        ])
        ->public();
};
