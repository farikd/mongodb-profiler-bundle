<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Explain;

use Farikd\MongodbProfilerBundle\Explain\ExplainCommandBuilder;
use PHPUnit\Framework\TestCase;

final class ExplainCommandBuilderTest extends TestCase
{
    public function testBuildsFindExplain(): void
    {
        $command = (new ExplainCommandBuilder())->build('find', 'videos', ['status' => 'discovered']);

        self::assertSame('executionStats', $command['verbosity']);
        self::assertSame(['find' => 'videos', 'filter' => ['status' => 'discovered']], $command['explain']);
    }

    public function testBuildsCountExplainUsingQueryKey(): void
    {
        $command = (new ExplainCommandBuilder())->build('count', 'videos', ['a' => 1]);

        self::assertSame(['count' => 'videos', 'query' => ['a' => 1]], $command['explain']);
        self::assertSame('executionStats', $command['verbosity']);
    }

    public function testBuildsAggregateExplainWithPipelineAndCursor(): void
    {
        $pipeline = [['$match' => ['a' => 1]], ['$count' => 'n']];

        $command = (new ExplainCommandBuilder())->build('aggregate', 'videos', $pipeline);

        self::assertSame('videos', $command['explain']['aggregate']);
        self::assertSame($pipeline, $command['explain']['pipeline']);
        self::assertInstanceOf(\stdClass::class, $command['explain']['cursor']);
    }

    public function testEmptyOrNullFilterBecomesEmptyDocument(): void
    {
        $command = (new ExplainCommandBuilder())->build('find', 'videos', null);

        self::assertInstanceOf(\stdClass::class, $command['explain']['filter']);

        $emptyArray = (new ExplainCommandBuilder())->build('find', 'videos', []);
        self::assertInstanceOf(\stdClass::class, $emptyArray['explain']['filter']);
    }

    public function testRejectsNonExplainableCommand(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ExplainCommandBuilder())->build('update', 'videos', ['a' => 1]);
    }
}
