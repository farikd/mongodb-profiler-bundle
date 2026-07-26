<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Explain;

/**
 * Reduces a MongoDB `explain` result (with `executionStats`) to the few facts the
 * profiler shows: index-vs-collection scan, the winning index name, and the
 * docs-examined / docs-returned ratio. Walks the plan tree recursively so it copes
 * with both `find` (top-level `queryPlanner`/`executionStats`) and `aggregate`
 * (nested under `stages[].$cursor`) shapes. Pure so it is unit-testable off fixtures.
 */
final class ExplainPlanParser
{
    /**
     * @param array<array-key, mixed> $explain
     */
    public function parse(array $explain): ExplainResult
    {
        $execStats = $this->firstValue($explain, 'executionStats');
        $execStats = \is_array($execStats) ? $execStats : [];

        // Classify from queryPlanner.winningPlan — the planner's CHOSEN plan. Two reasons
        // it, not the whole document:
        //  * it EXCLUDES queryPlanner.rejectedPlans[] (a sibling): the multi-planner
        //    readily rejects an IXSCAN in favour of a COLLSCAN on the small collections
        //    this dev tool runs against, and walking the root would report that rejected
        //    IXSCAN as "used an index" — the exact inverse of the truth.
        //  * it is in classic uppercase form across BOTH engines: the SBE engine (used for
        //    $group aggregates) writes lowercase `scan`/`ixscan` in executionStages, but
        //    still nests the classic tree under winningPlan.queryPlan; its lowercase
        //    slotBasedPlan is a string we never walk.
        // Fall back to executionStats.executionStages only when no winningPlan is present.
        $winningPlan = $this->firstValue($explain, 'winningPlan') ?? $execStats['executionStages'] ?? [];
        $winningPlan = \is_array($winningPlan) ? $winningPlan : [];

        $usesIndex = false;
        $hasCollscan = false;

        foreach ($this->collect($winningPlan, 'stage') as $stage) {
            if (!\is_string($stage)) {
                continue;
            }

            $upper = strtoupper($stage);

            // Index-scan family across engines/versions: IXSCAN, the 8.0 express executor's
            // EXPRESS_IXSCAN / EXPRESS_CLUSTERED_IXSCAN, the _id fast path IDHACK, the
            // covered COUNT_SCAN / DISTINCT_SCAN, and the SBE lowercase `ixscan`.
            if (str_contains($upper, 'IXSCAN') || \in_array($upper, ['IDHACK', 'COUNT_SCAN', 'DISTINCT_SCAN'], true)) {
                $usesIndex = true;
            }

            // Collection scan: classic COLLSCAN, or the SBE engine's lowercase `scan`.
            if ($upper === 'COLLSCAN' || $upper === 'SCAN') {
                $hasCollscan = true;
            }
        }

        if ($usesIndex) {
            $planType = ExplainResult::PLAN_IXSCAN;
        } elseif ($hasCollscan) {
            $planType = ExplainResult::PLAN_COLLSCAN;
        } else {
            $planType = ExplainResult::PLAN_OTHER;
        }

        $indexName = $this->firstValue($winningPlan, 'indexName');

        return new ExplainResult(
            planType: $planType,
            usesIndex: $usesIndex,
            indexName: \is_string($indexName) ? $indexName : null,
            docsExamined: $this->toInt($execStats['totalDocsExamined'] ?? null),
            nReturned: $this->toInt($execStats['nReturned'] ?? null),
        );
    }

    /**
     * Every value found at $key anywhere in the tree (depth-first).
     *
     * @param array<array-key, mixed> $node
     * @return list<mixed>
     */
    private function collect(array $node, string $key): array
    {
        $found = [];

        foreach ($node as $k => $value) {
            if ($k === $key) {
                $found[] = $value;
            }

            if (\is_array($value)) {
                foreach ($this->collect($value, $key) as $nested) {
                    $found[] = $nested;
                }
            }
        }

        return $found;
    }

    /**
     * First value found at $key (depth-first), or null.
     *
     * @param array<array-key, mixed> $node
     */
    private function firstValue(array $node, string $key): mixed
    {
        foreach ($node as $k => $value) {
            if ($k === $key) {
                return $value;
            }
        }

        foreach ($node as $value) {
            if (\is_array($value)) {
                $nested = $this->firstValue($value, $key);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function toInt(mixed $value): int
    {
        return \is_int($value) || \is_float($value) || \is_string($value) ? (int) $value : 0;
    }
}
