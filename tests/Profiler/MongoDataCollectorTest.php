<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Profiler;

use Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber;
use Farikd\MongodbProfilerBundle\Profiler\MongoDataCollector;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests the collector's lateCollect mapping — the contract the panel template and
 * the explain controller read. cloneVar works standalone, so no kernel is needed.
 */
final class MongoDataCollectorTest extends TestCase
{
    public function testMapsSubscriberStateForThePanelAndExplain(): void
    {
        $subscriber = new ProfilerSubscriber(cliProfilingEnabled: true);
        $subscriber->recordCommand($this->record('find', 'videos', ['status' => 'discovered'], 1.5));
        $subscriber->recordCommand($this->record('find', 'videos', ['status' => 'discovered'], 2.5));
        $subscriber->recordCommand($this->record('update', 'videos', [['q' => ['_id' => 'x']]], 0.5));

        $collector = new MongoDataCollector($subscriber);
        $collector->lateCollect();

        self::assertSame(3, $collector->getQueryCount());
        self::assertSame(3, $collector->getStoredCount());
        self::assertEqualsWithDelta(4.5, $collector->getTotalTimeMs(), 0.0001);
        self::assertFalse($collector->isCapped());

        // Duplicate group: the two identical finds.
        self::assertSame(1, $collector->getDuplicateGroupCount());
        self::assertSame(2, $collector->getDuplicateGroups()[0]['count']);

        // Explainable flag drives the [explain] link: find yes, update no.
        $queries = $collector->getQueries();
        self::assertTrue($queries[0]['explainable']);
        self::assertFalse($queries[2]['explainable']);

        // The panel reads readable JSON (not cloneVar), so nested args stay visible.
        self::assertSame("{\n    \"status\": \"discovered\"\n}", $queries[0]['filterJson']);

        // getRawQuery hands the explain controller a re-runnable command from the raw
        // copy (never the cloneVar display Data).
        $raw = $collector->getRawQuery(0);
        self::assertSame(['commandName' => 'find', 'collection' => 'videos', 'filter' => ['status' => 'discovered']], $raw);
    }

    public function testExposesCapState(): void
    {
        $subscriber = new ProfilerSubscriber(cliProfilingEnabled: true, maxQueries: 1);
        $subscriber->recordCommand($this->record('find', 'videos', ['a' => 1], 1.0));
        $subscriber->recordCommand($this->record('find', 'videos', ['a' => 2], 1.0));

        $collector = new MongoDataCollector($subscriber);
        $collector->lateCollect();

        self::assertTrue($collector->isCapped());
        self::assertSame(2, $collector->getQueryCount());
        self::assertSame(1, $collector->getStoredCount());
        self::assertSame(1, $collector->getMaxQueries());
    }

    public function testGetRawQueryOutOfRangeIsNull(): void
    {
        $subscriber = new ProfilerSubscriber(cliProfilingEnabled: true);
        $subscriber->recordCommand($this->record('find', 'videos', ['a' => 1], 1.0));

        $collector = new MongoDataCollector($subscriber);
        $collector->lateCollect();

        self::assertNull($collector->getRawQuery(5));
    }

    /**
     * @return array{commandName: string, collection: string, filter: mixed, durationMs: float, error: string|null, trace: array{repository: array{name: string, file: string, line: int}, caller: array{name: string, file: string, line: int}}}
     */
    private function record(string $op, string $collection, mixed $filter, float $durationMs): array
    {
        return [
            'commandName' => $op,
            'collection' => $collection,
            'filter' => $filter,
            'durationMs' => $durationMs,
            'error' => null,
            'trace' => [
                'repository' => ['name' => 'n/a', 'file' => '', 'line' => 0],
                'caller' => ['name' => 'n/a', 'file' => '', 'line' => 0],
            ],
        ];
    }
}
