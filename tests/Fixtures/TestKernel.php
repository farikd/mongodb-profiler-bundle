<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Fixtures;

use Farikd\MongodbProfilerBundle\MongodbProfilerBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Minimal kernel for the bundle's own tests. The cache directory is keyed by the config it
 * was booted with, so two tests using different `mongodb_profiler` settings cannot share a
 * stale compiled container.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $profilerConfig
     */
    public function __construct(
        private readonly array $profilerConfig = [],
        private readonly bool $importRoutes = true,
    ) {
        parent::__construct('test', true);
    }

    /** @return iterable<BundleInterface> */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new WebProfilerBundle();
        yield new MongodbProfilerBundle();
    }

    protected function configureContainer(ContainerConfigurator $c): void
    {
        $c->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'router' => ['utf8' => true],
            'profiler' => ['only_exceptions' => false, 'collect' => true],
        ]);
        $c->extension('twig', ['strict_variables' => true]);
        $c->extension('web_profiler', ['toolbar' => false]);
        $c->extension('mongodb_profiler', $this->profilerConfig);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // A probe endpoint that issues real Mongo commands, so a functional test has
        // something to profile; Task 8's explain test drives the explain endpoint off its
        // token, targeting the find at index 0 (explainable) or the insert at index 1 (not).
        $routes->add('_test_probe', '/_probe')->controller([self::class, 'probe']);

        if ($this->importRoutes) {
            $routes->import('@MongodbProfilerBundle/config/routes.php');
        }
    }

    public function probe(): Response
    {
        $uri = (string) getenv('MONGODB_URI');
        $database = (string) (getenv('MONGODB_DATABASE') ?: 'mongodb_profiler_test');

        $collection = (new \MongoDB\Client($uri))
            ->selectDatabase($database)
            ->selectCollection('profiler_probe');

        $collection->find(['probe' => 1])->toArray();
        $collection->insertOne(['probe' => 1]);

        return new Response('ok');
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/mongodb-profiler-bundle/' . $this->fingerprint();
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir() . '/log';
    }

    private function fingerprint(): string
    {
        return md5(serialize([$this->profilerConfig, $this->importRoutes]));
    }
}
