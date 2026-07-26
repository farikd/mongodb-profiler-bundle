<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Explain;

use Farikd\MongodbProfilerBundle\Explain\ExplainResult;
use Farikd\MongodbProfilerBundle\Explain\ExplainPlanParser;
use PHPUnit\Framework\TestCase;

final class ExplainPlanParserTest extends TestCase
{
    public function testParsesFindIndexScan(): void
    {
        $explain = [
            'queryPlanner' => [
                'winningPlan' => [
                    'stage' => 'FETCH',
                    'inputStage' => ['stage' => 'IXSCAN', 'indexName' => 'status_1'],
                ],
            ],
            'executionStats' => [
                'nReturned' => 5,
                'totalDocsExamined' => 5,
                'executionStages' => [
                    'stage' => 'FETCH',
                    'nReturned' => 5,
                    'inputStage' => ['stage' => 'IXSCAN', 'indexName' => 'status_1', 'nReturned' => 5],
                ],
            ],
        ];

        $result = (new ExplainPlanParser())->parse($explain);

        self::assertSame(ExplainResult::PLAN_IXSCAN, $result->planType);
        self::assertTrue($result->usesIndex);
        self::assertSame('status_1', $result->indexName);
        self::assertSame(5, $result->docsExamined);
        self::assertSame(5, $result->nReturned);
    }

    public function testParsesCollectionScan(): void
    {
        $explain = [
            'queryPlanner' => ['winningPlan' => ['stage' => 'COLLSCAN']],
            'executionStats' => [
                'nReturned' => 2,
                'totalDocsExamined' => 100,
                'executionStages' => ['stage' => 'COLLSCAN', 'nReturned' => 2],
            ],
        ];

        $result = (new ExplainPlanParser())->parse($explain);

        self::assertSame(ExplainResult::PLAN_COLLSCAN, $result->planType);
        self::assertFalse($result->usesIndex);
        self::assertNull($result->indexName);
        self::assertSame(100, $result->docsExamined);
        self::assertSame(2, $result->nReturned);
    }

    /**
     * The multi-planner records index plans it threw out under queryPlanner.rejectedPlans[].
     * The parser must NOT let a rejected IXSCAN masquerade as the chosen plan — otherwise a
     * COLLSCAN query (common on small dev datasets, where the planner prefers COLLSCAN)
     * would be reported as "used an index", the exact inverse of the truth.
     */
    public function testRejectedIndexPlanDoesNotMasqueradeAsWinner(): void
    {
        $explain = [
            'queryPlanner' => [
                'winningPlan' => ['stage' => 'COLLSCAN'],
                'rejectedPlans' => [
                    ['stage' => 'FETCH', 'inputStage' => ['stage' => 'IXSCAN', 'indexName' => 'status_1']],
                ],
            ],
            'executionStats' => [
                'nReturned' => 1,
                'totalDocsExamined' => 500,
                'executionStages' => ['stage' => 'COLLSCAN', 'nReturned' => 1],
            ],
        ];

        $result = (new ExplainPlanParser())->parse($explain);

        self::assertSame(ExplainResult::PLAN_COLLSCAN, $result->planType);
        self::assertFalse($result->usesIndex);
        self::assertNull($result->indexName, 'the rejected plan index name must not leak through');
        self::assertSame(500, $result->docsExamined);
    }

    public function testFallsBackToWinningPlanWhenExecutionStatsAbsent(): void
    {
        // queryPlanner-only verbosity: no executionStats, but the winning plan is still
        // rejected-plan-free, so classification must still work off it.
        $explain = [
            'queryPlanner' => [
                'winningPlan' => [
                    'stage' => 'FETCH',
                    'inputStage' => ['stage' => 'IXSCAN', 'indexName' => 'publishedAt_-1'],
                ],
                'rejectedPlans' => [['stage' => 'COLLSCAN']],
            ],
        ];

        $result = (new ExplainPlanParser())->parse($explain);

        self::assertSame(ExplainResult::PLAN_IXSCAN, $result->planType);
        self::assertTrue($result->usesIndex);
        self::assertSame('publishedAt_-1', $result->indexName);
        self::assertSame(0, $result->docsExamined);
        self::assertSame(0, $result->nReturned);
    }

    public function testParsesAggregateNestedUnderCursor(): void
    {
        $explain = [
            'stages' => [
                [
                    '$cursor' => [
                        'queryPlanner' => [
                            'winningPlan' => [
                                'stage' => 'FETCH',
                                'inputStage' => ['stage' => 'IXSCAN', 'indexName' => 'publishedAt_-1'],
                            ],
                        ],
                        'executionStats' => [
                            'nReturned' => 3,
                            'totalDocsExamined' => 3,
                            'executionStages' => [
                                'stage' => 'FETCH',
                                'inputStage' => ['stage' => 'IXSCAN', 'indexName' => 'publishedAt_-1'],
                            ],
                        ],
                    ],
                ],
                ['$group' => ['_id' => null]],
            ],
        ];

        $result = (new ExplainPlanParser())->parse($explain);

        self::assertSame(ExplainResult::PLAN_IXSCAN, $result->planType);
        self::assertTrue($result->usesIndex);
        self::assertSame('publishedAt_-1', $result->indexName);
        self::assertSame(3, $result->nReturned);
        self::assertSame(3, $result->docsExamined);
    }

    /**
     * A $group aggregate runs under the SBE engine. Its winningPlan still carries the
     * classic tree under `queryPlan` (uppercase COLLSCAN), while `slotBasedPlan` is a
     * string (never walked). The parser must read the classic tree, not the SBE names.
     */
    public function testParsesSbeGroupAggregateCollectionScan(): void
    {
        $explain = [
            'queryPlanner' => [
                'winningPlan' => [
                    'isCached' => false,
                    'queryPlan' => [
                        'stage' => 'GROUP',
                        'inputStage' => [
                            'stage' => 'COLLSCAN',
                            'filter' => ['title' => ['$eq' => 'zzz']],
                            'direction' => 'forward',
                        ],
                    ],
                    'slotBasedPlan' => [
                        'slots' => '$$RESULT=s6 env: {  }',
                        'stages' => '[3] project [s6] \n[1] scan generic [s1 = record] ',
                    ],
                ],
                'rejectedPlans' => [],
            ],
            'executionStats' => [
                'nReturned' => 0,
                'totalDocsExamined' => 308,
                'executionStages' => ['stage' => 'group', 'inputStage' => ['stage' => 'scan']],
            ],
        ];

        $result = (new ExplainPlanParser())->parse($explain);

        self::assertSame(ExplainResult::PLAN_COLLSCAN, $result->planType);
        self::assertFalse($result->usesIndex);
        self::assertSame(308, $result->docsExamined);
    }

    /**
     * Fallback path: no queryPlanner (rare), only SBE executionStages with lowercase
     * stage names — the parser must still recognise `scan` as a collection scan and
     * `ixscan` as an index scan.
     */
    public function testParsesLowercaseSbeExecutionStagesFallback(): void
    {
        $collscan = (new ExplainPlanParser())->parse([
            'executionStats' => [
                'nReturned' => 0,
                'totalDocsExamined' => 42,
                'executionStages' => ['stage' => 'group', 'inputStage' => ['stage' => 'scan']],
            ],
        ]);
        self::assertSame(ExplainResult::PLAN_COLLSCAN, $collscan->planType);
        self::assertFalse($collscan->usesIndex);

        $ixscan = (new ExplainPlanParser())->parse([
            'executionStats' => [
                'nReturned' => 1,
                'totalDocsExamined' => 1,
                'executionStages' => ['stage' => 'group', 'inputStage' => ['stage' => 'ixscan']],
            ],
        ]);
        self::assertSame(ExplainResult::PLAN_IXSCAN, $ixscan->planType);
        self::assertTrue($ixscan->usesIndex);
    }

    public function testTreatsExpressAndIdHackStagesAsIndexUse(): void
    {
        foreach (['EXPRESS_IXSCAN', 'EXPRESS_CLUSTERED_IXSCAN', 'IDHACK'] as $stage) {
            $result = (new ExplainPlanParser())->parse([
                'queryPlanner' => ['winningPlan' => ['stage' => $stage]],
                'executionStats' => [
                    'nReturned' => 1,
                    'totalDocsExamined' => 1,
                    'executionStages' => ['stage' => $stage],
                ],
            ]);

            self::assertTrue($result->usesIndex, sprintf('%s should count as index use', $stage));
            self::assertSame(ExplainResult::PLAN_IXSCAN, $result->planType);
        }
    }

    public function testUnknownPlanIsOther(): void
    {
        $result = (new ExplainPlanParser())->parse([
            'queryPlanner' => ['winningPlan' => ['stage' => 'EOF']],
            'executionStats' => ['nReturned' => 0, 'totalDocsExamined' => 0, 'executionStages' => ['stage' => 'EOF']],
        ]);

        self::assertSame(ExplainResult::PLAN_OTHER, $result->planType);
        self::assertFalse($result->usesIndex);
    }

    public function testEmptyExplainDegradesGracefully(): void
    {
        $result = (new ExplainPlanParser())->parse([]);

        self::assertSame(ExplainResult::PLAN_OTHER, $result->planType);
        self::assertFalse($result->usesIndex);
        self::assertNull($result->indexName);
        self::assertSame(0, $result->docsExamined);
        self::assertSame(0, $result->nReturned);
    }
}
