<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Monitoring;

use Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber;
use PHPUnit\Framework\TestCase;

final class ProfilerSubscriberSeamsTest extends TestCase
{
    public function testIgnoredTracePrefixesPushTheReportedFrameOutwards(): void
    {
        $subscriber = new ProfilerSubscriber(
            cliProfilingEnabled: true,
            ignoredTracePrefixes: ['Acme\\Persistence\\'],
        );

        $trace = $subscriber->parseTrace([
            ['class' => 'MongoDB\\Collection', 'file' => '/vendor/Collection.php', 'line' => 10],
            ['class' => 'Acme\\Persistence\\BaseRepository', 'file' => '/app/BaseRepository.php', 'line' => 20],
            ['class' => 'Acme\\Domain\\VideoRepository', 'file' => '/app/VideoRepository.php', 'line' => 30],
            ['class' => 'Acme\\Domain\\ListVideos', 'file' => '/app/ListVideos.php', 'line' => 40],
        ]);

        self::assertSame('VideoRepository.php', $trace['repository']['name']);
        self::assertSame('ListVideos.php', $trace['caller']['name']);
    }

    public function testThePackagesOwnFramesAreAlwaysSkipped(): void
    {
        $subscriber = new ProfilerSubscriber(cliProfilingEnabled: true);

        $trace = $subscriber->parseTrace([
            ['class' => 'Farikd\\MongodbProfilerBundle\\Monitoring\\ProfilerSubscriber', 'file' => '/src/ProfilerSubscriber.php', 'line' => 1],
            ['class' => 'Farikd\\MongodbProfilerBundle\\Profiler\\MongoDataCollector', 'file' => '/src/MongoDataCollector.php', 'line' => 2],
            ['class' => 'Acme\\Domain\\VideoRepository', 'file' => '/app/VideoRepository.php', 'line' => 30],
            ['class' => 'Acme\\Domain\\ListVideos', 'file' => '/app/ListVideos.php', 'line' => 40],
        ]);

        self::assertSame('VideoRepository.php', $trace['repository']['name']);
    }

    public function testACaptureFailureIsDroppedAndCountedInsteadOfThrowing(): void
    {
        $subscriber = new ProfilerSubscriber(cliProfilingEnabled: true);

        // The N+1 signature serializes the filter; an object that refuses to serialize is
        // the cheapest stand-in for "something in capture threw".
        $subscriber->recordCommand(self::record('find', 'videos', new UnserializableFilter()));

        self::assertSame(0, $subscriber->getQueryCount(), 'the broken record is not counted');
        self::assertSame(1, $subscriber->getDroppedCount());
        self::assertStringContainsString('nope', (string) $subscriber->getFirstDropReason());
    }

    public function testResetClearsEveryAccumulator(): void
    {
        $subscriber = new ProfilerSubscriber(cliProfilingEnabled: true);
        $subscriber->recordCommand(self::record('find', 'videos'));
        $subscriber->recordCommand(self::record('find', 'videos'));

        $subscriber->reset();

        self::assertSame(0, $subscriber->getQueryCount());
        self::assertSame(0.0, $subscriber->getTotalTimeMs());
        self::assertSame([], $subscriber->getQueries());
        self::assertSame([], $subscriber->getDuplicateGroups());
        self::assertFalse($subscriber->isCapped());
        self::assertSame(0, $subscriber->getDroppedCount());
    }

    /** @return array<string, mixed> */
    private static function record(string $commandName, string $collection, mixed $filter = ['n' => 1]): array
    {
        $frame = ['name' => 'Repo.php', 'file' => '/app/Repo.php', 'line' => 1];

        return [
            'commandName' => $commandName,
            'collection' => $collection,
            'filter' => $filter,
            'durationMs' => 1.5,
            'error' => null,
            'trace' => ['repository' => $frame, 'caller' => $frame],
        ];
    }
}

final class UnserializableFilter
{
    public function __serialize(): array
    {
        throw new \RuntimeException('nope');
    }
}
