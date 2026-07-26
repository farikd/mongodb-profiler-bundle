<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Monitoring;

use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Driver-level Mongo command monitor. A `CommandSubscriber` on the client
 * is the ONLY hook that sees every command: direct `collection()->countDocuments()`
 * calls and health-check pings included (a repository wrapper would miss those),
 * and it reads the driver-measured duration for free.
 *
 * All profiler state lives here — the raw records, running totals, a duplicate-group
 * map for N+1 detection, and a bounded cap — and is read by the web profiler panel
 * (web) and the CLI summary listener. Driver monitoring events cannot be
 * constructed in PHP, so the pure, event-free methods (`extractFilter`, `parseTrace`,
 * `recordCommand` + the getters) are the unit-test seam; the event methods only adapt
 * driver events onto them.
 *
 * Gating is by the bundle extension (`mongodb_profiler.enabled` registers no services
 * when false). Within an enabled container the web path records unconditionally while
 * a CLI run records only when `mongodb_profiler.cli` is true.
 *
 * @phpstan-type Frame array{name: string, file: string, line: int}
 * @phpstan-type Trace array{repository: Frame, caller: Frame}
 * @phpstan-type QueryRecord array{commandName: string, collection: string, filter: mixed, durationMs: float, error: string|null, trace: Trace}
 * @phpstan-type DuplicateGroup array{commandName: string, collection: string, filter: mixed, count: int, trace: Trace}
 */
final class ProfilerSubscriber implements CommandSubscriber, ResetInterface
{
    /**
     * Connection-handshake / topology chatter we never want to surface. `ping` is
     * deliberately NOT here: an application-issued `ping` (e.g. a health check that
     * calls `getDatabase()->command(['ping' => 1])` straight past its repository layer)
     * is exactly the driver-level capture this profiler exists to prove, and the PHP
     * driver issues no background heartbeat pings of its own.
     */
    public const array DEFAULT_IGNORED_COMMANDS = [
        'isMaster',
        'ismaster',
        'hello',
        'saslStart',
        'saslContinue',
        'buildInfo',
        'getMore',
    ];

    /**
     * Read commands we re-run with `explain`. (An `aggregate` can technically write via
     * `$out`/`$merge`, but `explain` never materialises them).
     */
    public const array EXPLAINABLE_COMMANDS = ['find', 'aggregate', 'count'];

    /** @var Frame */
    private const array EMPTY_FRAME = ['name' => 'n/a', 'file' => '', 'line' => 0];

    private readonly bool $enabled;

    /** @var list<string> */
    private readonly array $ignoredCommands;

    /** @var list<string> */
    private readonly array $ignoredTracePrefixes;

    /** @var array<int|string, array{commandName: string, collection: string, filter: mixed, trace: Trace}> keyed by driver requestId */
    private array $pending = [];

    /** @var list<QueryRecord> individually-stored records, bounded by the max-queries cap */
    private array $queries = [];

    private int $queryCount = 0;

    private float $totalTimeMs = 0.0;

    private bool $capped = false;

    /** @var array<string, DuplicateGroup> signature => group; always updated, even past the cap */
    private array $duplicateGroups = [];

    private int $droppedCount = 0;

    private ?string $firstDropReason = null;

    /**
     * @param list<string> $ignoredCommands      replaces DEFAULT_IGNORED_COMMANDS wholesale
     * @param list<string> $ignoredTracePrefixes extra class-name prefixes to skip when
     *                                           picking the origin frame, on top of this
     *                                           package's own namespace and `MongoDB\`
     */
    public function __construct(
        bool $cliProfilingEnabled = false,
        private readonly int $maxQueries = 2000,
        array $ignoredCommands = self::DEFAULT_IGNORED_COMMANDS,
        array $ignoredTracePrefixes = [],
    ) {
        // The web (non-CLI) path records unconditionally; CLI opts in explicitly so
        // ordinary crons and consumers pay nothing.
        $this->enabled = \PHP_SAPI !== 'cli' || $cliProfilingEnabled;
        $this->ignoredCommands = $ignoredCommands;
        $this->ignoredTracePrefixes = $ignoredTracePrefixes;
    }

    public static function isExplainable(string $commandName): bool
    {
        return \in_array($commandName, self::EXPLAINABLE_COMMANDS, true);
    }

    #[\Override]
    public function commandStarted(CommandStartedEvent $event): void
    {
        try {
            $this->doCommandStarted($event);
        } catch (\Throwable $exception) {
            $this->drop($exception);
        }
    }

    #[\Override]
    public function commandSucceeded(CommandSucceededEvent $event): void
    {
        $this->finish($event->getRequestId(), $event->getDurationMicros() / 1000, null);
    }

    #[\Override]
    public function commandFailed(CommandFailedEvent $event): void
    {
        $this->finish($event->getRequestId(), $event->getDurationMicros() / 1000, $event->getError()->getMessage());
    }

    /**
     * Accumulate one completed command. Public so the stateful logic (totals, N+1
     * grouping, cap) is unit-testable with plain arrays, without driver events.
     *
     * @param QueryRecord $record
     */
    public function recordCommand(array $record): void
    {
        try {
            $this->doRecordCommand($record);
        } catch (\Throwable $exception) {
            $this->drop($exception);
        }
    }

    /**
     * Pick the caller origin from a backtrace: the nearest frame outside the profiler
     * and the MongoDB driver is the repository, the next one out is its caller. Public
     * and frame-array-driven so the parsing is unit-testable without a live stack.
     *
     * @param list<array<string, mixed>> $frames
     *
     * @return Trace
     */
    public function parseTrace(array $frames): array
    {
        $repository = self::EMPTY_FRAME;
        $caller = self::EMPTY_FRAME;
        $found = 0;

        foreach ($frames as $frame) {
            $class = \is_string($frame['class'] ?? null) ? $frame['class'] : '';
            $file = $frame['file'] ?? null;

            if (!\is_string($file) || $this->isIgnoredFrame($class)) {
                continue;
            }

            $entry = [
                'name' => basename($file),
                'file' => $file,
                'line' => \is_int($frame['line'] ?? null) ? $frame['line'] : 0,
            ];

            if (0 === $found) {
                $repository = $entry;
                ++$found;
            } else {
                $caller = $entry;

                break;
            }
        }

        return ['repository' => $repository, 'caller' => $caller];
    }

    /**
     * Pull the re-runnable filter/pipeline out of the raw command document, keyed on the
     * driver's *wire* command name (`find`/`aggregate`/`count`/`update`/…, never the
     * Collection-method names). Public and pure so the per-op shapes are unit-testable.
     *
     * @param array<array-key, mixed> $command
     */
    public function extractFilter(string $commandName, array $command): mixed
    {
        return match ($commandName) {
            'find' => $command['filter'] ?? null,
            'count', 'distinct', 'findAndModify' => $command['query'] ?? null,
            'aggregate' => $command['pipeline'] ?? null,
            // update/delete carry their filters inside the per-op `updates`/`deletes`
            // array ({q, u}/{q, limit}); show it whole rather than digging (display only).
            'update' => $command['updates'] ?? null,
            'delete' => $command['deletes'] ?? null,
            'insert' => ['count' => \count((array) ($command['documents'] ?? []))],
            default => null,
        };
    }

    /** @return list<QueryRecord> */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /** Total commands seen — may exceed the number stored once the cap is hit. */
    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    public function getStoredCount(): int
    {
        return \count($this->queries);
    }

    public function getTotalTimeMs(): float
    {
        return $this->totalTimeMs;
    }

    /**
     * Duplicate/N+1 groups: byte-identical (op, collection, filter) seen more than once,
     * most-repeated first. Computed from the running map — no extra queries.
     *
     * @return list<DuplicateGroup>
     */
    public function getDuplicateGroups(): array
    {
        $groups = array_values(array_filter(
            $this->duplicateGroups,
            static fn (array $group): bool => $group['count'] > 1,
        ));

        usort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $groups;
    }

    public function isCapped(): bool
    {
        return $this->capped;
    }

    public function getMaxQueries(): int
    {
        return $this->maxQueries;
    }

    public function getDroppedCount(): int
    {
        return $this->droppedCount;
    }

    public function getFirstDropReason(): ?string
    {
        return $this->firstDropReason;
    }

    /**
     * Clears every accumulator. Required because the subscriber is process-wide and now
     * outlives a request: without this a worker-mode runtime (FrankenPHP/RoadRunner) would
     * show request N's panel containing requests 1..N.
     */
    #[\Override]
    public function reset(): void
    {
        $this->pending = [];
        $this->queries = [];
        $this->duplicateGroups = [];
        $this->queryCount = 0;
        $this->totalTimeMs = 0.0;
        $this->capped = false;
        $this->droppedCount = 0;
        $this->firstDropReason = null;
    }

    private function doCommandStarted(CommandStartedEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $commandName = $event->getCommandName();
        if (\in_array($commandName, $this->ignoredCommands, true)) {
            return;
        }

        $command = (array) $event->getCommand();

        $this->pending[$event->getRequestId()] = [
            'commandName' => $commandName,
            'collection' => \is_string($command[$commandName] ?? null) ? $command[$commandName] : '',
            'filter' => $this->extractFilter($commandName, $command),
            'trace' => $this->parseTrace(debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 20)),
        ];
    }

    /**
     * @param QueryRecord $record
     */
    private function doRecordCommand(array $record): void
    {
        $signature = $this->signature($record['commandName'], $record['collection'], $record['filter']);

        ++$this->queryCount;
        $this->totalTimeMs += $record['durationMs'];

        if (isset($this->duplicateGroups[$signature])) {
            ++$this->duplicateGroups[$signature]['count'];
        } else {
            $this->duplicateGroups[$signature] = [
                'commandName' => $record['commandName'],
                'collection' => $record['collection'],
                'filter' => $record['filter'],
                'count' => 1,
                'trace' => $record['trace'],
            ];
        }

        // Past the cap keep exact totals + duplicate counts (both maintained above)
        // but stop storing per-row records/backtraces, so a big reindex can't blow memory.
        if (\count($this->queries) >= $this->maxQueries) {
            $this->capped = true;

            return;
        }

        $this->queries[] = $record;
    }

    /**
     * Class-name prefixes never reported as the caller: this package's own frames and the
     * driver's. `$ignoredTracePrefixes` appends to these, so an app can push the reported
     * origin past its own repository plumbing.
     */
    private function isIgnoredFrame(string $class): bool
    {
        if ('' === $class) {
            return false;
        }

        $prefixes = array_merge(
            ['Farikd\\MongodbProfilerBundle\\', 'MongoDB\\'],
            $this->ignoredTracePrefixes,
        );

        foreach ($prefixes as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A capture failure must never surface at the caller's `find()` — the driver dispatches
     * these events inside the application's own query. So a throw is swallowed here, but
     * never silently: the count is shown in the panel and the CLI summary, because a panel
     * reporting 40 queries when 42 ran is the worse failure.
     */
    private function drop(\Throwable $exception): void
    {
        ++$this->droppedCount;
        $this->firstDropReason ??= $exception::class.': '.$exception->getMessage();
    }

    private function finish(int|string $requestId, float $durationMs, ?string $error): void
    {
        if (!isset($this->pending[$requestId])) {
            return;
        }

        $pending = $this->pending[$requestId];
        unset($this->pending[$requestId]);

        $this->recordCommand([
            'commandName' => $pending['commandName'],
            'collection' => $pending['collection'],
            'filter' => $pending['filter'],
            'durationMs' => $durationMs,
            'error' => $error,
            'trace' => $pending['trace'],
        ]);
    }

    private function signature(string $commandName, string $collection, mixed $filter): string
    {
        return md5($commandName.'|'.$collection.'|'.serialize($filter));
    }
}
