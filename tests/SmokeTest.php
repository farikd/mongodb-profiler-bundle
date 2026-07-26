<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testTheMongodbExtensionIsLoaded(): void
    {
        self::assertTrue(\extension_loaded('mongodb'), 'ext-mongodb must be available in the test container');
    }

    public function testTheMongoServiceIsReachable(): void
    {
        $uri = getenv('MONGODB_URI');
        self::assertIsString($uri, 'MONGODB_URI must be set by the container environment');

        $manager = new \MongoDB\Driver\Manager($uri);
        $cursor = $manager->executeCommand('admin', new \MongoDB\Driver\Command(['ping' => 1]));

        $reply = $cursor->toArray()[0];

        self::assertSame(1.0, $reply->ok, 'the ping command must report ok: 1');
    }
}
