<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Bundle;

use Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber;
use Farikd\MongodbProfilerBundle\Tests\Fixtures\BundleTestCase;

final class ConfigurationTest extends BundleTestCase
{
    public function testEnabledByDefaultUnderDebug(): void
    {
        self::bootKernel();

        self::assertTrue(self::getContainer()->has('mongodb_profiler.subscriber'));
    }

    public function testDisabledRegistersNoServicesAtAll(): void
    {
        // Not "an inert service" — no definition. This is the guarantee that a production
        // container is identical to one without the package installed, and a regression to a
        // runtime flag would pass every behavioural test while breaking it.
        self::bootKernel(['profiler' => ['enabled' => false]]);

        self::assertFalse(self::getContainer()->has('mongodb_profiler.subscriber'));
        self::assertFalse(self::getContainer()->has(ProfilerSubscriber::class));
    }

    public function testConfigurationReachesTheSubscriber(): void
    {
        self::bootKernel(['profiler' => [
            'max_queries' => 7,
            'ignored_commands' => ['hello'],
        ]]);

        $subscriber = self::getContainer()->get('mongodb_profiler.subscriber');
        self::assertInstanceOf(ProfilerSubscriber::class, $subscriber);
        self::assertSame(7, $subscriber->getMaxQueries());
    }

    public function testExplainServicesAreNotRegisteredWithoutExplainConfig(): void
    {
        // `explain.uri`/`explain.database` default to null, so `config/explain.php` must
        // never be imported — proving the conditional import in loadExtension() actually
        // guards the container, not only that its condition reads correctly. Asserted against
        // the (necessarily public) explain controller rather than the runner: the runner is
        // private and, once referenced only by the controller, would otherwise be a moving
        // target under compiler passes.
        self::bootKernel();

        self::assertFalse(self::getContainer()->has('mongodb_profiler.explain_controller'));
    }
}
