<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Explain;

/**
 * Rebuilds a stored read command as a MongoDB `explain` command with `executionStats`
 * verbosity, so the profiler can re-run it on demand to inspect index usage. Only the
 * read shapes ({@see \Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber::EXPLAINABLE_COMMANDS}) are supported —
 * writes are never re-run. Pure/stateless so the per-op shapes are unit-testable.
 */
final class ExplainCommandBuilder
{
    /**
     * @return array{explain: array<string, mixed>, verbosity: string}
     */
    public function build(string $commandName, string $collection, mixed $filter): array
    {
        $inner = match ($commandName) {
            'find' => ['find' => $collection, 'filter' => $this->asDocument($filter)],
            'count' => ['count' => $collection, 'query' => $this->asDocument($filter)],
            // The aggregate command requires a `cursor` document even under explain.
            'aggregate' => ['aggregate' => $collection, 'pipeline' => $this->asPipeline($filter), 'cursor' => new \stdClass()],
            default => throw new \InvalidArgumentException(\sprintf('Command "%s" is not explainable.', $commandName)),
        };

        return ['explain' => $inner, 'verbosity' => 'executionStats'];
    }

    /** A find/count filter must be a document; an empty or absent filter is an empty doc. */
    private function asDocument(mixed $filter): mixed
    {
        if (\is_array($filter) && [] !== $filter) {
            return $filter;
        }

        if (\is_object($filter)) {
            return $filter;
        }

        return new \stdClass();
    }

    /**
     * A pipeline is a list of stages; a missing pipeline is the empty pipeline.
     *
     * @return list<mixed>
     */
    private function asPipeline(mixed $pipeline): array
    {
        return \is_array($pipeline) ? array_values($pipeline) : [];
    }
}
