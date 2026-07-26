<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Console;

/**
 * Pure aggregation for the CLI Mongo-profile summary: folds individual command records
 * into per-(op, collection) rows sorted by total time. Kept separate from the console
 * listener so the grouping/sorting is unit-testable off plain arrays.
 */
final class ProfileSummary
{
    /**
     * @param list<array{commandName: string, collection: string, durationMs: float, ...}> $queries
     * @return list<array{operation: string, collection: string, count: int, totalMs: float}>
     */
    public function group(array $queries): array
    {
        $rows = [];

        foreach ($queries as $query) {
            $key = $query['commandName'] . "\0" . $query['collection'];

            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'operation' => $query['commandName'],
                    'collection' => $query['collection'],
                    'count' => 0,
                    'totalMs' => 0.0,
                ];
            }

            ++$rows[$key]['count'];
            $rows[$key]['totalMs'] += $query['durationMs'];
        }

        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => $b['totalMs'] <=> $a['totalMs']);

        return $rows;
    }
}
