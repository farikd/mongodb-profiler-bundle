<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Profiler;

use Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

/**
 * Web-profiler surface over {@see ProfilerSubscriber}. Collects late (so every
 * command for the request is in), keeping two copies of each filter: a `cloneVar`
 * display copy for the panel, and a plain re-runnable raw copy for on-demand explain
 * (the explain controller reads it back from the stored profile).
 *
 * Do not override {@see AbstractDataCollector::getName()}. The profiler indexes collectors
 * by `getName()` (`Profiler::has()`, `Profile::hasCollector()`), while `TemplateManager`
 * looks each panel up by the `data_collector` tag's `id` attribute — which is why that tag
 * passes `id => MongoDataCollector::class`, matching what `AbstractDataCollector::getName()`
 * returns (`static::class`). Override `getName()` and the two stop agreeing: the collector
 * still collects, but the panel silently does not render and `?panel=…` 404s. The DI service
 * id plays no part in this — it only keys `data_collector.templates`, whose keys nothing
 * reads back.
 */
final class MongoDataCollector extends AbstractDataCollector implements LateDataCollectorInterface
{
    public function __construct(private readonly ProfilerSubscriber $subscriber)
    {
    }

    #[\Override]
    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        // Nothing here — all commands are gathered in lateCollect().
    }

    #[\Override]
    public function lateCollect(): void
    {
        $formatter = new FilterFormatter();

        $queries = [];
        foreach ($this->subscriber->getQueries() as $query) {
            $queries[] = [
                'commandName' => $query['commandName'],
                'collection' => $query['collection'],
                // Readable JSON, not cloneVar: the profiler's shared dumper cuts nested
                // filter/pipeline args server-side (see FilterFormatter).
                'filterJson' => null === $query['filter'] ? null : $formatter->toReadable($query['filter']),
                'rawFilter' => $query['filter'],
                'durationMs' => $query['durationMs'],
                'error' => $query['error'],
                'trace' => $query['trace'],
                'explainable' => ProfilerSubscriber::isExplainable($query['commandName']),
            ];
        }

        $duplicateGroups = [];
        foreach ($this->subscriber->getDuplicateGroups() as $group) {
            $duplicateGroups[] = [
                'commandName' => $group['commandName'],
                'collection' => $group['collection'],
                'filterJson' => $formatter->toReadable($group['filter']),
                'count' => $group['count'],
                'trace' => $group['trace'],
            ];
        }

        $this->data = [
            'queries' => $queries,
            'queryCount' => $this->subscriber->getQueryCount(),
            'storedCount' => $this->subscriber->getStoredCount(),
            'totalTimeMs' => $this->subscriber->getTotalTimeMs(),
            'duplicateGroups' => $duplicateGroups,
            'capped' => $this->subscriber->isCapped(),
            'maxQueries' => $this->subscriber->getMaxQueries(),
            'droppedCount' => $this->subscriber->getDroppedCount(),
            'firstDropReason' => $this->subscriber->getFirstDropReason(),
        ];
    }

    #[\Override]
    public static function getTemplate(): string
    {
        return '@MongodbProfiler/mongodb.html.twig';
    }

    public function getQueryCount(): int
    {
        $count = $this->data['queryCount'] ?? 0;

        return \is_int($count) ? $count : 0;
    }

    public function getStoredCount(): int
    {
        $count = $this->data['storedCount'] ?? 0;

        return \is_int($count) ? $count : 0;
    }

    public function getTotalTimeMs(): float
    {
        $total = $this->data['totalTimeMs'] ?? 0.0;

        return \is_float($total) || \is_int($total) ? (float) $total : 0.0;
    }

    /** @return list<array<string, mixed>> */
    public function getQueries(): array
    {
        $queries = $this->data['queries'] ?? [];
        if (!\is_array($queries)) {
            return [];
        }

        // Written exclusively by lateCollect() above, always in this shape.
        /** @var list<array<string, mixed>> $queries */
        $queries = $queries;

        return $queries;
    }

    /** @return list<array<string, mixed>> */
    public function getDuplicateGroups(): array
    {
        $groups = $this->data['duplicateGroups'] ?? [];
        if (!\is_array($groups)) {
            return [];
        }

        // Written exclusively by lateCollect() above, always in this shape.
        /** @var list<array<string, mixed>> $groups */
        $groups = $groups;

        return $groups;
    }

    public function getDuplicateGroupCount(): int
    {
        return \count($this->getDuplicateGroups());
    }

    public function isCapped(): bool
    {
        return ($this->data['capped'] ?? false) === true;
    }

    public function getMaxQueries(): int
    {
        $max = $this->data['maxQueries'] ?? 0;

        return \is_int($max) ? $max : 0;
    }

    public function getDroppedCount(): int
    {
        $count = $this->data['droppedCount'] ?? 0;

        return \is_int($count) ? $count : 0;
    }

    public function getFirstDropReason(): ?string
    {
        $reason = $this->data['firstDropReason'] ?? null;

        return \is_string($reason) ? $reason : null;
    }

    /**
     * The re-runnable command at $index, for the explain controller. Reads the
     * plain raw copy (never the cloneVar display Data), so explain never trusts a
     * client-submitted query body.
     *
     * @return array{commandName: string, collection: string, filter: mixed}|null
     */
    public function getRawQuery(int $index): ?array
    {
        $query = $this->getQueries()[$index] ?? null;
        if (!\is_array($query)) {
            return null;
        }

        $commandName = $query['commandName'] ?? null;
        $collection = $query['collection'] ?? null;
        if (!\is_string($commandName) || !\is_string($collection)) {
            return null;
        }

        return [
            'commandName' => $commandName,
            'collection' => $collection,
            'filter' => $query['rawFilter'] ?? null,
        ];
    }
}
