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

    public function testDisabledOutsideDebugWithoutAnyConfiguration(): void
    {
        // The production path, and the one an app never writes a line of config for:
        // `enabled` defaults to %kernel.debug%, so a non-debug build must come out with no
        // profiler services even though nothing set the key. Booted as a real non-debug
        // kernel rather than by passing `enabled: false` — that only proves the explicit
        // branch, and it is the *default* that every consumer actually relies on.
        self::bootKernel(['debug' => false]);

        self::assertFalse(self::getContainer()->has('mongodb_profiler.subscriber'));
        self::assertFalse(self::getContainer()->has(ProfilerSubscriber::class));
    }

    public function testExplicitlyEnabledOverridesTheDebugDefault(): void
    {
        // The documented escape hatch ("set it explicitly only to force it on/off"): the
        // default is a default, not a hard non-debug ban.
        self::bootKernel(['debug' => false, 'profiler' => ['enabled' => true]]);

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
        // guards the container, not only that its condition reads correctly.
        self::bootKernel();

        self::assertFalse(self::getContainer()->has('mongodb_profiler.explain_controller'));
    }

    public function testExplainServicesAreRegisteredWithExplainConfig(): void
    {
        // The other half of the pair, and the reason the assertion above is not a tautology:
        // both sides are read off the same test container, so this proves a `false` there
        // means "not imported" rather than "not visible from here".
        self::bootKernel(['profiler' => [
            'explain' => ['uri' => 'mongodb://localhost:27017', 'database' => 'anything'],
        ]]);

        self::assertTrue(self::getContainer()->has('mongodb_profiler.explain_controller'));
        self::assertTrue(self::getContainer()->has('mongodb_profiler.explain_runner'));
    }
}
