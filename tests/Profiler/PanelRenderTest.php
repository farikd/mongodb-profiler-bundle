<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Profiler;

use Farikd\MongodbProfilerBundle\Profiler\MongoDataCollector;
use Farikd\MongodbProfilerBundle\Tests\Fixtures\BundleTestCase;
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

        self::assertTrue(self::getContainer()->has('mongodb_profiler.explain_runner'));

        $html = $this->renderPanel();

        self::assertStringContainsString(self::EXPLAIN_BUTTON_MARKER, $html);
    }

    private function renderPanel(): string
    {
        $subscriber = self::getContainer()->get('mongodb_profiler.subscriber');
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
