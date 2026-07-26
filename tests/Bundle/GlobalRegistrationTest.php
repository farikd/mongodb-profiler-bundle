<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Bundle;

use Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber;
use Farikd\MongodbProfilerBundle\Tests\Fixtures\MongoTestCase;
use Farikd\MongodbProfilerBundle\Tests\Fixtures\TestKernel;
use MongoDB\Client;

/**
 * The load-bearing assumption of the whole design: `Bundle::boot()` registers the subscriber
 * with the driver's PROCESS-WIDE registry, so it sees clients built before and after boot,
 * and `shutdown()` takes it back out again.
 */
final class GlobalRegistrationTest extends MongoTestCase
{
    private const string COLLECTION = 'profiler_global_probe';

    protected function tearDown(): void
    {
        $this->dropCollections(self::COLLECTION);

        parent::tearDown();
    }

    public function testCapturesAClientBuiltAfterBoot(): void
    {
        $kernel = new TestKernel(['cli' => true]);
        $kernel->boot();

        try {
            $this->freshClient()->selectDatabase($this->database()->getDatabaseName())
                ->selectCollection(self::COLLECTION)->find(['probe' => 1])->toArray();

            self::assertContains('find', array_column($this->subscriber($kernel)->getQueries(), 'commandName'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testCapturesAClientBuiltBeforeBoot(): void
    {
        $client = $this->freshClient();

        $kernel = new TestKernel(['cli' => true]);
        $kernel->boot();

        try {
            $client->selectDatabase($this->database()->getDatabaseName())
                ->selectCollection(self::COLLECTION)->find(['probe' => 1])->toArray();

            self::assertContains('find', array_column($this->subscriber($kernel)->getQueries(), 'commandName'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testShutdownDeregistersSoASecondKernelDoesNotDoubleRecord(): void
    {
        $first = new TestKernel(['cli' => true]);
        $first->boot();
        $firstSubscriber = $this->subscriber($first);
        $first->shutdown();

        $second = new TestKernel(['cli' => true]);
        $second->boot();

        try {
            $this->freshClient()->selectDatabase($this->database()->getDatabaseName())
                ->selectCollection(self::COLLECTION)->find(['probe' => 1])->toArray();

            self::assertSame(0, $firstSubscriber->getQueryCount(), 'the shut-down kernel stopped collecting');
            self::assertGreaterThan(0, $this->subscriber($second)->getQueryCount());
        } finally {
            $second->shutdown();
        }
    }

    public function testIgnoredCommandsAreReplacedWholesaleNotMerged(): void
    {
        $kernel = new TestKernel(['cli' => true, 'ignored_commands' => ['find']]);
        $kernel->boot();

        try {
            $collection = $this->freshClient()->selectDatabase($this->database()->getDatabaseName())
                ->selectCollection(self::COLLECTION);

            for ($i = 0; $i < 5; ++$i) {
                $collection->insertOne(['probe' => $i]);
            }

            // batchSize smaller than the result set forces the driver to issue a real
            // getMore to fetch the remaining documents.
            iterator_to_array($collection->find([], ['batchSize' => 2]));

            $operations = array_column($this->subscriber($kernel)->getQueries(), 'commandName');

            self::assertNotContains(
                'find',
                $operations,
                'the configured list took effect: find was ignored',
            );
            self::assertContains(
                'getMore',
                $operations,
                'the defaults were NOT merged in: getMore is only ignored by default, not by this config',
            );
        } finally {
            $kernel->shutdown();
        }
    }

    private function freshClient(): Client
    {
        $uri = getenv('MONGODB_URI');
        self::assertIsString($uri);

        return new Client($uri, [], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
    }

    private function subscriber(TestKernel $kernel): ProfilerSubscriber
    {
        $subscriber = $kernel->getContainer()->get('mongodb_profiler.subscriber');
        self::assertInstanceOf(ProfilerSubscriber::class, $subscriber);

        return $subscriber;
    }
}
