<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Twig;

use Farikd\MongodbProfilerBundle\Tests\Double\StubUrlGenerator;
use Farikd\MongodbProfilerBundle\Tests\Double\ThrowingUrlGenerator;
use Farikd\MongodbProfilerBundle\Twig\ExplainUrlExtension;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free unit coverage of the class's whole contract, in addition to (not instead of)
 * {@see \Farikd\MongodbProfilerBundle\Tests\Profiler\PanelRenderTest}'s integration
 * coverage through a real compiled router.
 */
final class ExplainUrlExtensionTest extends TestCase
{
    public function testReturnsNullWithoutConsultingTheRouterWhenExplainIsNotConfigured(): void
    {
        // A throwing generator here would fail loudly if `explainConfigured` did not
        // short-circuit before ever calling generate().
        $extension = new ExplainUrlExtension(new ThrowingUrlGenerator(), explainConfigured: false);

        self::assertNull($extension->explainUrl('token', 0));
    }

    public function testReturnsNullWhenTheRouteIsMissingEvenThoughExplainIsConfigured(): void
    {
        // The one behaviour this class exists for: explain IS configured, but the routes
        // import was skipped, so the router throws RouteNotFoundException — it must be
        // caught, not left to propagate into the panel render.
        $extension = new ExplainUrlExtension(new ThrowingUrlGenerator(), explainConfigured: true);

        self::assertNull($extension->explainUrl('token', 0));
    }

    public function testReturnsTheGeneratedUrlWhenBothPreconditionsHold(): void
    {
        $generator = new StubUrlGenerator('https://example.test/_mongodb-profiler/explain/tok/2');
        $extension = new ExplainUrlExtension($generator, explainConfigured: true);

        $url = $extension->explainUrl('tok', 2);

        self::assertSame('https://example.test/_mongodb-profiler/explain/tok/2', $url);
        self::assertSame(
            ['name' => ExplainUrlExtension::ROUTE, 'parameters' => ['token' => 'tok', 'index' => 2]],
            $generator->lastCall,
        );
    }

    public function testRegistersTheTwigFunction(): void
    {
        $extension = new ExplainUrlExtension(new StubUrlGenerator(), explainConfigured: true);

        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('mongodb_profiler_explain_url', $functions[0]->getName());
    }
}
