<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Console;

use Farikd\MongodbProfilerBundle\Console\ProfileConsoleListener;
use Farikd\MongodbProfilerBundle\Console\ProfileSummary;
use Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber;
use Farikd\MongodbProfilerBundle\Tests\Monitoring\UnserializableFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ProfileConsoleListenerTest extends TestCase
{
    public function testPrintsNothingWhenNoCommandsRecorded(): void
    {
        $output = $this->runListener(new ProfilerSubscriber(cliProfilingEnabled: true));

        self::assertSame('', $output);
    }

    public function testPrintsSummaryTableForRecordedCommands(): void
    {
        $subscriber = new ProfilerSubscriber(cliProfilingEnabled: true);
        $subscriber->recordCommand($this->record('find', 'videos', durationMs: 2.0));
        $subscriber->recordCommand($this->record('aggregate', 'chunks', durationMs: 5.0));

        $output = $this->runListener($subscriber);

        self::assertStringContainsString('MongoDB profile', $output);
        self::assertStringContainsString('2 command(s)', $output);
        self::assertStringContainsString('aggregate', $output);
        self::assertStringContainsString('chunks', $output);
    }

    public function testSurfacesFailedCommands(): void
    {
        $subscriber = new ProfilerSubscriber(cliProfilingEnabled: true);
        $subscriber->recordCommand($this->record('find', 'videos', durationMs: 1.0));
        $subscriber->recordCommand($this->record('aggregate', 'videos', durationMs: 1.0, error: 'index not found: nope_1'));

        $output = $this->runListener($subscriber);

        self::assertStringContainsString('command(s) failed', $output);
        self::assertStringContainsString('index not found: nope_1', $output);
    }

    public function testDroppedRecordsAreReported(): void
    {
        $subscriber = new ProfilerSubscriber(cliProfilingEnabled: true);
        $subscriber->recordCommand($this->record('find', 'videos'));
        $subscriber->recordCommand($this->record('find', 'videos', new UnserializableFilter()));

        $output = $this->runListener($subscriber);

        self::assertStringContainsString('were not recorded', $output);
    }

    private function runListener(ProfilerSubscriber $subscriber): string
    {
        $listener = new ProfileConsoleListener($subscriber, new ProfileSummary());
        $output = new BufferedOutput();
        $event = new ConsoleTerminateEvent(new Command('test'), new ArrayInput([]), $output, 0);

        $listener->__invoke($event);

        return $output->fetch();
    }

    /**
     * @return array{commandName: string, collection: string, filter: mixed, durationMs: float, error: string|null, trace: array{repository: array{name: string, file: string, line: int}, caller: array{name: string, file: string, line: int}}}
     */
    private function record(string $op, string $collection, mixed $filter = null, float $durationMs = 1.0, ?string $error = null): array
    {
        return [
            'commandName' => $op,
            'collection' => $collection,
            'filter' => $filter,
            'durationMs' => $durationMs,
            'error' => $error,
            'trace' => [
                'repository' => ['name' => 'n/a', 'file' => '', 'line' => 0],
                'caller' => ['name' => 'n/a', 'file' => '', 'line' => 0],
            ],
        ];
    }
}
