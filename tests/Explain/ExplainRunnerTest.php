<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Explain;

use Farikd\MongodbProfilerBundle\Explain\ExplainCommandBuilder;
use Farikd\MongodbProfilerBundle\Explain\ExplainPlanParser;
use Farikd\MongodbProfilerBundle\Explain\ExplainResult;
use Farikd\MongodbProfilerBundle\Explain\ExplainRunner;
use Farikd\MongodbProfilerBundle\Tests\Fixtures\MongoTestCase;
use MongoDB\BSON\ObjectId;

/**
 * Exercises the explain pipeline against real Mongo: build the explain command, run it,
 * parse the plan. Validates the end-to-end index/collection-scan detection the panel's
 * [explain] link relies on.
 */
final class ExplainRunnerTest extends MongoTestCase
{
    private const string COLLECTION = 'profiler_explain_probe';

    protected function tearDown(): void
    {
        $this->dropCollections(self::COLLECTION);

        parent::tearDown();
    }

    public function testFindByIdUsesIndex(): void
    {
        $id = new ObjectId();
        $this->database()->selectCollection(self::COLLECTION)->insertOne(['_id' => $id, 'n' => 1]);

        $result = $this->runner()->explain('find', self::COLLECTION, ['_id' => $id]);

        self::assertTrue($result->usesIndex);
        self::assertSame(ExplainResult::PLAN_IXSCAN, $result->planType);
        self::assertSame(1, $result->nReturned);
    }

    public function testFindOnUnindexedFieldIsCollectionScan(): void
    {
        $this->database()->selectCollection(self::COLLECTION)->insertOne(['_id' => new ObjectId(), 'n' => 1]);

        $result = $this->runner()->explain('find', self::COLLECTION, ['probe_unindexed' => 'x']);

        self::assertFalse($result->usesIndex);
        self::assertSame(ExplainResult::PLAN_COLLSCAN, $result->planType);
        self::assertSame(0, $result->nReturned);
    }

    public function testAggregateMatchOnIdUsesIndex(): void
    {
        $id = new ObjectId();
        $this->database()->selectCollection(self::COLLECTION)->insertOne(['_id' => $id, 'n' => 1]);

        // Aggregate explain nests queryPlanner/executionStats under stages[].$cursor —
        // exercises both the builder's cursor wrapping and the parser's nested walk.
        $result = $this->runner()->explain('aggregate', self::COLLECTION, [['$match' => ['_id' => $id]]]);

        self::assertTrue($result->usesIndex);
        self::assertSame(ExplainResult::PLAN_IXSCAN, $result->planType);
    }

    public function testGroupAggregateCollectionScanIsDetected(): void
    {
        $this->database()->selectCollection(self::COLLECTION)->insertOne(['_id' => new ObjectId(), 'probe_unindexed' => 'x']);

        // A $group aggregate runs under the SBE engine, whose executionStages use lowercase
        // stage names (`scan`); the parser must still classify the collection scan.
        $result = $this->runner()->explain('aggregate', self::COLLECTION, [
            ['$match' => ['probe_unindexed' => 'x']],
            ['$group' => ['_id' => '$probe_unindexed']],
        ]);

        self::assertSame(ExplainResult::PLAN_COLLSCAN, $result->planType);
        self::assertFalse($result->usesIndex);
    }

    public function testCountExplainRunsAndParses(): void
    {
        $this->database()->selectCollection(self::COLLECTION)->insertOne(['_id' => new ObjectId(), 'n' => 1]);

        // The count shape uses the `query` key (not `filter`); assert the whole
        // builder→runner→parser path accepts it and yields a well-formed result.
        $result = $this->runner()->explain('count', self::COLLECTION, ['probe_unindexed' => 'x']);

        self::assertContains($result->planType, [
            ExplainResult::PLAN_IXSCAN,
            ExplainResult::PLAN_COLLSCAN,
            ExplainResult::PLAN_OTHER,
        ]);
        self::assertGreaterThanOrEqual(0, $result->docsExamined);
        self::assertGreaterThanOrEqual(0, $result->nReturned);
    }

    private function runner(): ExplainRunner
    {
        $uri = getenv('MONGODB_URI');
        self::assertIsString($uri);

        return new ExplainRunner(
            $uri,
            $this->database()->getDatabaseName(),
            new ExplainCommandBuilder(),
            new ExplainPlanParser(),
        );
    }
}
