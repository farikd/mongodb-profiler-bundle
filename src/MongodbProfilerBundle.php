<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle;

use Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function MongoDB\Driver\Monitoring\addSubscriber;
use function MongoDB\Driver\Monitoring\removeSubscriber;

/**
 * Dev-only MongoDB query profiler.
 *
 * Capture attaches through the driver's PROCESS-WIDE subscriber registry rather than to a
 * particular client, so a consuming app wires nothing: every `Manager`/`Client` in the
 * process is seen, whoever built it and whenever. `shutdown()` pairs with `boot()` because
 * a test suite booting several kernels in one process would otherwise leave every previous
 * kernel's subscriber registered and collecting.
 *
 * `enabled` gates SERVICE REGISTRATION, not runtime: with it false the container holds no
 * profiler services at all, so a production build is identical to one without the package.
 */
final class MongodbProfilerBundle extends AbstractBundle
{
    protected string $extensionAlias = 'mongodb_profiler';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')
                    ->defaultNull()
                    ->info('Register the profiler services. Defaults to %kernel.debug%.')
                ->end()
                ->booleanNode('cli')
                    ->defaultFalse()
                    ->info('Record during CLI runs (console commands, messenger workers).')
                ->end()
                ->integerNode('max_queries')
                    ->defaultValue(2000)
                    ->min(1)
                    ->info('Per-request cap on individually stored commands. Totals and duplicate counts stay exact past it.')
                ->end()
                ->arrayNode('ignored_commands')
                    ->scalarPrototype()->end()
                    ->defaultValue(ProfilerSubscriber::DEFAULT_IGNORED_COMMANDS)
                    ->info('Replaces the default list wholesale; it is not merged with it.')
                ->end()
                ->arrayNode('ignored_trace_prefixes')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->info('Extra class-name prefixes to skip when picking the origin frame.')
                ->end()
                ->arrayNode('explain')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('uri')->defaultNull()->end()
                        ->scalarNode('database')->defaultNull()->end()
                    ->end()
                    ->info('Connection used to re-run a captured read with explain. Unset disables the feature.')
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $enabled = $config['enabled'] ?? (bool) $builder->getParameter('kernel.debug');
        if ($enabled !== true) {
            return;
        }

        $container->parameters()
            ->set('mongodb_profiler.cli', $config['cli'])
            ->set('mongodb_profiler.max_queries', $config['max_queries'])
            ->set('mongodb_profiler.ignored_commands', $config['ignored_commands'])
            ->set('mongodb_profiler.ignored_trace_prefixes', $config['ignored_trace_prefixes'])
            ->set('mongodb_profiler.explain_uri', $config['explain']['uri'])
            ->set('mongodb_profiler.explain_database', $config['explain']['database'])
            ->set('mongodb_profiler.explain_configured', \is_string($config['explain']['uri']) && \is_string($config['explain']['database']));

        $container->import('../config/services.php');

        if (\is_string($config['explain']['uri']) && \is_string($config['explain']['database'])) {
            $container->import('../config/explain.php');
        }
    }

    public function boot(): void
    {
        $subscriber = $this->subscriber();
        if ($subscriber !== null) {
            addSubscriber($subscriber);
        }
    }

    public function shutdown(): void
    {
        $subscriber = $this->subscriber();
        if ($subscriber !== null) {
            removeSubscriber($subscriber);
        }
    }

    private function subscriber(): ?ProfilerSubscriber
    {
        // Absent whenever `enabled` is false — that is the whole point of gating registration.
        if ($this->container === null || !$this->container->has('mongodb_profiler.subscriber')) {
            return null;
        }

        $subscriber = $this->container->get('mongodb_profiler.subscriber');

        return $subscriber instanceof ProfilerSubscriber ? $subscriber : null;
    }
}
