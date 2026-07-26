<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Console;

use Farikd\MongodbProfilerBundle\Console\ProfileSummary;
use PHPUnit\Framework\TestCase;

final class ProfileSummaryTest extends TestCase
{
    public function testGroupsByOperationAndCollectionSortedByTotalTime(): void
    {
        $summary = new ProfileSummary();

        $rows = $summary->group([
            $this->query('find', 'videos', 1.0),
            $this->query('find', 'videos', 3.0),
            $this->query('aggregate', 'chunks', 10.0),
            $this->query('find', 'persons', 2.0),
        ]);

        self::assertCount(3, $rows);

        // aggregate/chunks (10ms) is the slowest group, first.
        self::assertSame(['operation' => 'aggregate', 'collection' => 'chunks', 'count' => 1, 'totalMs' => 10.0], $rows[0]);
        // find/videos folded to 2 commands totalling 4ms.
        self::assertSame(['operation' => 'find', 'collection' => 'videos', 'count' => 2, 'totalMs' => 4.0], $rows[1]);
        self::assertSame(['operation' => 'find', 'collection' => 'persons', 'count' => 1, 'totalMs' => 2.0], $rows[2]);
    }

    public function testEmptyInputYieldsNoRows(): void
    {
        self::assertSame([], (new ProfileSummary())->group([]));
    }

    /**
     * @return array{commandName: string, collection: string, durationMs: float, error: string|null}
     */
    private function query(string $op, string $collection, float $durationMs): array
    {
        return ['commandName' => $op, 'collection' => $collection, 'durationMs' => $durationMs, 'error' => null];
    }
}
