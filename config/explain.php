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

    $services->set('mongodb_profiler.explain_runner', ExplainRunner::class)
        ->args([
            param('mongodb_profiler.explain_uri'),
            param('mongodb_profiler.explain_database'),
            service('mongodb_profiler.explain_command_builder'),
            service('mongodb_profiler.explain_plan_parser'),
        ]);
};
