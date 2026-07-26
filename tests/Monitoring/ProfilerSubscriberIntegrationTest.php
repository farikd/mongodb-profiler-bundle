<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Monitoring;

use Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber;
use Farikd\MongodbProfilerBundle\Tests\Fixtures\MongoTestCase;

/**
 * Validates the driver wiring end-to-end: attach the subscriber to a real client, run
 * repository-style queries, and assert they were captured. Driver monitoring events
 * cannot be constructed in PHP, so this is the only way to prove `commandStarted`/
 * `commandSucceeded` fire and pair. Also proves driver-level capture picks up a direct
 * `collection()` call (which a repository wrapper would miss).
 */
final class ProfilerSubscriberIntegrationTest extends MongoTestCase
{
    private const string COLLECTION = 'profiler_subscriber_probe';

    protected function tearDown(): void
    {
        $this->dropCollections(self::COLLECTION);

        parent::tearDown();
    }

    public function testCapturesRealDriverCommands(): void
    {
        $subscriber = new ProfilerSubscriber(cliProfilingEnabled: true);
        $client = $this->client();
        $client->addSubscriber($subscriber);

        try {
            $collection = $this->database()->selectCollection(self::COLLECTION);
            $collection->find(['probe_marker' => 1])->toArray();
            // A direct collection() call — the kind a BaseRepository wrapper cannot see.
            $collection->countDocuments(['probe_marker' => 1]);
        } finally {
            $client->removeSubscriber($subscriber);
        }

        self::assertGreaterThanOrEqual(1, $subscriber->getQueryCount());

        $collections = array_column($subscriber->getQueries(), 'collection');
        self::assertContains(self::COLLECTION, $collections, 'the profiled query targets the probe collection');

        $operations = array_column($subscriber->getQueries(), 'commandName');
        self::assertContains('find', $operations, 'the find command was captured');
    }

    public function testCliProfilingDisabledRecordsNothing(): void
    {
        // Under phpunit PHP_SAPI is 'cli', so a subscriber without the opt-in must stay
        // inert — this is the "ordinary dev cron/consumer pays nothing" guarantee.
        $subscriber = new ProfilerSubscriber(cliProfilingEnabled: false);
        $client = $this->client();
        $client->addSubscriber($subscriber);

        try {
            $this->database()->selectCollection(self::COLLECTION)->find(['probe_marker' => 1])->toArray();
        } finally {
            $client->removeSubscriber($subscriber);
        }

        self::assertSame(0, $subscriber->getQueryCount());
    }

    public function testCapturesApplicationPing(): void
    {
        // MongoProbe issues a raw `ping` command past BaseRepository — the purest proof
        // of driver-level capture, so an application ping must be visible (unlike the
        // driver's handshake chatter, which stays ignored).
        $subscriber = new ProfilerSubscriber(cliProfilingEnabled: true);
        $client = $this->client();
        $client->addSubscriber($subscriber);

        try {
            $this->database()->command(['ping' => 1]);
        } finally {
            $client->removeSubscriber($subscriber);
        }

        self::assertContains('ping', array_column($subscriber->getQueries(), 'commandName'));
    }
}
