<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Console;

use Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the Mongo-profile summary at the end of a console command when profiling was
 * opted into (`mongodb_profiler.cli`). No config check is needed here: the subscriber
 * only records in CLI when opted in, so a non-empty count already means profiling was
 * on; a plain run records nothing and prints nothing.
 *
 * Fires on every console command and `messenger:consume --limit=N` — hence the config
 * opt-in rather than a per-command option. Wired as a `kernel.event_listener` on
 * `console.terminate` from `config/services.php`.
 */
final readonly class ProfileConsoleListener
{
    public function __construct(
        private ProfilerSubscriber $subscriber,
        private ProfileSummary $summary,
    ) {
    }

    public function __invoke(ConsoleTerminateEvent $event): void
    {
        if (0 === $this->subscriber->getQueryCount()) {
            return;
        }

        $io = new SymfonyStyle($event->getInput(), $event->getOutput());

        $io->newLine();
        $io->section('MongoDB profile');
        $io->writeln(\sprintf(
            '<info>%d</info> command(s) in <info>%0.2f</info> ms',
            $this->subscriber->getQueryCount(),
            $this->subscriber->getTotalTimeMs(),
        ));

        $rows = [];
        foreach ($this->summary->group($this->subscriber->getQueries()) as $row) {
            $rows[] = [
                $row['operation'],
                $row['collection'],
                (string) $row['count'],
                \sprintf('%0.2f', $row['totalMs']),
            ];
        }

        if ([] !== $rows) {
            $io->table(['op', 'collection', 'count', 'total ms'], $rows);
        }

        // Surface failed commands — the web panel flags them red; the CLI summary
        // captured the error too and must not silently drop it.
        $errors = array_values(array_filter(
            $this->subscriber->getQueries(),
            static fn (array $query): bool => null !== $query['error'],
        ));
        if ([] !== $errors) {
            $io->warning(\sprintf('%d command(s) failed:', \count($errors)));
            foreach ($errors as $query) {
                $io->writeln(\sprintf(
                    '  <fg=red>%s %s</> — %s',
                    $query['commandName'],
                    $query['collection'],
                    (string) $query['error'],
                ));
            }
            $io->newLine();
        }

        $duplicateGroups = $this->subscriber->getDuplicateGroups();
        if ([] !== $duplicateGroups) {
            $io->warning(\sprintf('%d duplicate/N+1 group(s) detected:', \count($duplicateGroups)));
            foreach ($duplicateGroups as $group) {
                $io->writeln(\sprintf(
                    '  <comment>%d×</comment> %s %s <fg=gray>(%s:%d)</>',
                    $group['count'],
                    $group['commandName'],
                    $group['collection'],
                    $group['trace']['repository']['name'],
                    $group['trace']['repository']['line'],
                ));
            }
            $io->newLine();
        }

        if ($this->subscriber->getDroppedCount() > 0) {
            $io->warning(\sprintf(
                '%d command(s) were not recorded: %s',
                $this->subscriber->getDroppedCount(),
                (string) $this->subscriber->getFirstDropReason(),
            ));
        }

        if ($this->subscriber->isCapped()) {
            $io->warning(\sprintf(
                'Query cap reached: only the first %d commands were stored individually (totals and duplicate counts above are exact). Raise mongodb_profiler.max_queries to store more.',
                $this->subscriber->getMaxQueries(),
            ));
        }
    }
}
