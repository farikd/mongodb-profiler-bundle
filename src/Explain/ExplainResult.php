<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Explain;

/**
 * Parsed outcome of a MongoDB `explain` (`executionStats`) for one stored read query:
 * whether it hit an index and how selective it was.
 */
final readonly class ExplainResult
{
    public const string PLAN_IXSCAN = 'IXSCAN';

    public const string PLAN_COLLSCAN = 'COLLSCAN';

    public const string PLAN_OTHER = 'OTHER';

    public function __construct(
        public string $planType,
        public bool $usesIndex,
        public ?string $indexName,
        public int $docsExamined,
        public int $nReturned,
    ) {
    }
}
