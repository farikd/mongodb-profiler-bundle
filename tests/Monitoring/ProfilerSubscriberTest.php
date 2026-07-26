<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Monitoring;

use Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure, event-free logic of the subscriber (grouping, totals, cap,
 * filter extraction, caller-trace parsing). Driver monitoring events cannot be
 * constructed in PHP; the end-to-end driver wiring is covered by the integration test.
 */
final class ProfilerSubscriberTest extends TestCase
{
    public function testExtractFilterPerCommandShape(): void
    {
        $subscriber = new ProfilerSubscriber(true);

        self::assertSame(
            ['status' => 'discovered'],
            $subscriber->extractFilter('find', ['find' => 'videos', 'filter' => ['status' => 'discovered']]),
        );
        self::assertSame(
            [['$match' => ['a' => 1]]],
            $subscriber->extractFilter('aggregate', ['aggregate' => 'videos', 'pipeline' => [['$match' => ['a' => 1]]]]),
        );
        self::assertSame(
            ['_id' => 'x'],
            $subscriber->extractFilter('count', ['count' => 'videos', 'query' => ['_id' => 'x']]),
        );
        self::assertSame(
            [['q' => ['_id' => 'x'], 'u' => ['$set' => ['a' => 1]]]],
            $subscriber->extractFilter('update', ['update' => 'videos', 'updates' => [['q' => ['_id' => 'x'], 'u' => ['$set' => ['a' => 1]]]]]),
        );
        self::assertSame(
            ['count' => 3],
            $subscriber->extractFilter('insert', ['insert' => 'videos', 'documents' => [[], [], []]]),
        );
        self::assertNull($subscriber->extractFilter('createIndexes', ['createIndexes' => 'videos']));
    }

    public function testParseTracePicksNearestRepositoryThenCaller(): void
    {
        $subscriber = new ProfilerSubscriber(true);

        $trace = $subscriber->parseTrace([
            ['class' => 'Farikd\\MongodbProfilerBundle\\Monitoring\\ProfilerSubscriber', 'file' => '/app/ProfilerSubscriber.php', 'line' => 90],
            ['class' => 'MongoDB\\Collection', 'file' => '/vendor/mongodb/Collection.php', 'line' => 10],
            ['function' => 'no_file_frame'],
            ['class' => 'App\\Infrastructure\\Persistence\\Video\\VideoRepository', 'file' => '/app/VideoRepository.php', 'line' => 116],
            ['class' => 'App\\Application\\Video\\CountVideos', 'file' => '/app/CountVideos.php', 'line' => 42],
        ]);

        self::assertSame('VideoRepository.php', $trace['repository']['name']);
        self::assertSame(116, $trace['repository']['line']);
        self::assertSame('CountVideos.php', $trace['caller']['name']);
        self::assertSame(42, $trace['caller']['line']);
    }

    public function testParseTraceFallsBackWhenNoAppFrames(): void
    {
        $subscriber = new ProfilerSubscriber(true);

        $trace = $subscriber->parseTrace([
            ['class' => 'MongoDB\\Collection', 'file' => '/vendor/mongodb/Collection.php', 'line' => 10],
        ]);

        self::assertSame('n/a', $trace['repository']['name']);
        self::assertSame('n/a', $trace['caller']['name']);
    }

    public function testRecordCommandAccumulatesTotalsAndGroupsDuplicates(): void
    {
        $subscriber = new ProfilerSubscriber(true);

        $subscriber->recordCommand($this->record('find', 'videos', ['a' => 1], 1.5));
        $subscriber->recordCommand($this->record('find', 'videos', ['a' => 1], 2.5));
        $subscriber->recordCommand($this->record('find', 'videos', ['a' => 2], 4.0));

        self::assertSame(3, $subscriber->getQueryCount());
        self::assertSame(3, $subscriber->getStoredCount());
        self::assertEqualsWithDelta(8.0, $subscriber->getTotalTimeMs(), 0.0001);
        self::assertFalse($subscriber->isCapped());

        $groups = $subscriber->getDuplicateGroups();
        self::assertCount(1, $groups);
        self::assertSame(2, $groups[0]['count']);
        self::assertSame('find', $groups[0]['commandName']);
        self::assertSame('videos', $groups[0]['collection']);
    }

    public function testDuplicateGroupsSortedByCountDescending(): void
    {
        $subscriber = new ProfilerSubscriber(true);

        $subscriber->recordCommand($this->record('find', 'a', ['x' => 1], 1.0));
        $subscriber->recordCommand($this->record('find', 'a', ['x' => 1], 1.0));
        $subscriber->recordCommand($this->record('find', 'b', ['y' => 2], 1.0));
        $subscriber->recordCommand($this->record('find', 'b', ['y' => 2], 1.0));
        $subscriber->recordCommand($this->record('find', 'b', ['y' => 2], 1.0));

        $groups = $subscriber->getDuplicateGroups();

        self::assertCount(2, $groups);
        self::assertSame(3, $groups[0]['count']);
        self::assertSame('b', $groups[0]['collection']);
        self::assertSame(2, $groups[1]['count']);
    }

    public function testCapStopsStoringRowsButKeepsExactTotalsAndDuplicateCounts(): void
    {
        $subscriber = new ProfilerSubscriber(true, 2);

        $subscriber->recordCommand($this->record('find', 'videos', ['a' => 1], 1.0));
        $subscriber->recordCommand($this->record('find', 'videos', ['a' => 1], 1.0));
        $subscriber->recordCommand($this->record('find', 'videos', ['a' => 1], 1.0));
        $subscriber->recordCommand($this->record('find', 'videos', ['a' => 2], 1.0));

        self::assertTrue($subscriber->isCapped());
        self::assertSame(4, $subscriber->getQueryCount(), 'total count stays exact past the cap');
        self::assertCount(2, $subscriber->getQueries(), 'only the first N rows are stored');
        self::assertSame(2, $subscriber->getStoredCount());
        self::assertEqualsWithDelta(4.0, $subscriber->getTotalTimeMs(), 0.0001);

        $groups = $subscriber->getDuplicateGroups();
        self::assertCount(1, $groups);
        self::assertSame(3, $groups[0]['count'], 'duplicate counts are exact even past the cap');
    }

    public function testIsExplainableOnlyForReads(): void
    {
        self::assertTrue(ProfilerSubscriber::isExplainable('find'));
        self::assertTrue(ProfilerSubscriber::isExplainable('aggregate'));
        self::assertTrue(ProfilerSubscriber::isExplainable('count'));
        self::assertFalse(ProfilerSubscriber::isExplainable('updateOne'));
        self::assertFalse(ProfilerSubscriber::isExplainable('insert'));
        self::assertFalse(ProfilerSubscriber::isExplainable('findAndModify'));
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
                'repository' => ['name' => 'VideoRepository.php', 'file' => '/app/VideoRepository.php', 'line' => 116],
                'caller' => ['name' => 'CountVideos.php', 'file' => '/app/CountVideos.php', 'line' => 42],
            ],
        ];
    }
}
