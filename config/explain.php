<?php

declare(strict_types=1);

use Farikd\MongodbProfilerBundle\Explain\ExplainCommandBuilder;
use Farikd\MongodbProfilerBundle\Explain\ExplainPlanParser;
use Farikd\MongodbProfilerBundle\Explain\ExplainRunner;
use Farikd\MongodbProfilerBundle\Profiler\ExplainController;
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

    // container.service_subscriber makes RegisterServiceSubscribersPass bind
    // AbstractController's #[Required] setContainer(ContainerInterface $container) to a
    // locator scoped to ::getSubscribedServices() (twig, router, …) — but only for an
    // AUTOWIRED call: the pass rewrites bindings, which fill in an unresolved argument, not
    // an explicit one. So setContainer is deliberately left uncalled here (no manual
    // `->call('setContainer', …)`) and resolved by ->autowire() instead — passing an explicit
    // `service('service_container')` reference bypasses the binding and leaves ->has('twig')
    // false on the raw container, which is why renderView() 500'd claiming Twig is
    // unavailable. controller.service_arguments is what AbstractController gets
    // autoconfigured with in a consuming app; added explicitly here since this container
    // isn't autoconfigured.
    $services->set('mongodb_profiler.explain_controller', ExplainController::class)
        ->args([service('profiler'), service('mongodb_profiler.explain_runner')])
        ->autowire()
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->public();
};
