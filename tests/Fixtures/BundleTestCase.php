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
        $profilerConfig = $options['profiler'] ?? [];
        \assert(\is_array($profilerConfig));

        return new TestKernel($profilerConfig, $options['routes'] ?? true);
    }
}
