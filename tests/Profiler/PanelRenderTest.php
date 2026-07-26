<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Profiler;

use Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber;
use Farikd\MongodbProfilerBundle\Profiler\MongoDataCollector;
use Farikd\MongodbProfilerBundle\Tests\Fixtures\BundleTestCase;
use Farikd\MongodbProfilerBundle\Twig\ExplainUrlExtension;
use Twig\Environment;

/**
 * The panel must render completely when explain is unavailable — an installer who skipped
 * the routes import gets a read-only panel, not a RouteNotFoundException that breaks the
 * whole profiler.
 */
final class PanelRenderTest extends BundleTestCase
{
    // The literal class name `mongo-explain-btn` also appears in the panel's static
    // click-handler script (`closest('.mongo-explain-btn')`), which renders unconditionally
    // whenever there is at least one query — so it is present in the HTML whether or not the
    // button itself is rendered. Assert on the actual `<button>` opening tag instead.
    private const string EXPLAIN_BUTTON_MARKER = '<button type="button" class="btn btn-sm mongo-explain-btn"';

    public function testPanelRendersWithoutTheExplainRouteImported(): void
    {
        self::bootKernel(['profiler' => [], 'routes' => false]);

        $html = $this->renderPanel();

        self::assertStringContainsString('MongoDB', $html);
        self::assertStringNotContainsString(self::EXPLAIN_BUTTON_MARKER, $html);
    }

    public function testPanelRendersTheExplainButtonWhenBothPreconditionsHold(): void
    {
        $uri = getenv('MONGODB_URI');
        self::assertIsString($uri);

        self::bootKernel(['profiler' => [
            'cli' => true,
            'explain' => ['uri' => $uri, 'database' => 'mongodb_profiler_test'],
        ], 'routes' => true]);

        self::assertTrue(self::getContainer()->has('mongodb_profiler.explain_controller'));

        $html = $this->renderPanel();

        self::assertStringContainsString(self::EXPLAIN_BUTTON_MARKER, $html);
    }

    /**
     * The one case {@see ExplainUrlExtension::explainUrl()}'s try/catch exists for: explain
     * IS configured (so `explainConfigured` is true and the short-circuit at the top of
     * `explainUrl()` is bypassed), but the routes import was skipped, so
     * `UrlGeneratorInterface::generate()` throws `RouteNotFoundException` and must be caught
     * rather than propagate through panel rendering.
     */
    public function testPanelRendersWithoutTheExplainButtonWhenRouteIsMissingButExplainIsConfigured(): void
    {
        $uri = getenv('MONGODB_URI');
        self::assertIsString($uri);

        self::bootKernel(['profiler' => [
            'cli' => true,
            'explain' => ['uri' => $uri, 'database' => 'mongodb_profiler_test'],
        ], 'routes' => false]);

        self::assertTrue(self::getContainer()->has('mongodb_profiler.explain_controller'));

        $html = $this->renderPanel();

        self::assertStringContainsString('MongoDB', $html);
        self::assertStringNotContainsString(self::EXPLAIN_BUTTON_MARKER, $html);
    }

    private function renderPanel(): string
    {
        $subscriber = self::getContainer()->get('mongodb_profiler.subscriber');
        self::assertInstanceOf(ProfilerSubscriber::class, $subscriber);

        $frame = ['name' => 'Repo.php', 'file' => '/app/Repo.php', 'line' => 1];
        $subscriber->recordCommand([
            'commandName' => 'find',
            'collection' => 'videos',
            'filter' => ['n' => 1],
            'durationMs' => 1.5,
            'error' => null,
            'trace' => ['repository' => $frame, 'caller' => $frame],
        ]);

        $collector = new MongoDataCollector($subscriber);
        $collector->lateCollect();

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->load('@MongodbProfiler/mongodb.html.twig')
            ->renderBlock('panel', ['collector' => $collector, 'token' => 'testtoken']);
    }
}
