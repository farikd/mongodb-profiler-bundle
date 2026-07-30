<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Fixtures;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class BundleTestCase extends KernelTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        /** @var array<string, mixed> $profilerConfig */
        $profilerConfig = $options['profiler'] ?? [];

        $importRoutes = $options['routes'] ?? true;
        \assert(\is_bool($importRoutes));

        $debug = $options['debug'] ?? true;
        \assert(\is_bool($debug));

        return new TestKernel($profilerConfig, $importRoutes, $debug);
    }
}
