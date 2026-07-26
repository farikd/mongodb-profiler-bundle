<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Fixtures;

use MongoDB\Client;
use MongoDB\Database;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that hit a real MongoDB. Skips — never silently passes — when no server is
 * configured, so a missing service container reads as "not run" rather than "green".
 */
abstract class MongoTestCase extends TestCase
{
    private ?Client $client = null;

    protected function client(): Client
    {
        if ($this->client === null) {
            $uri = getenv('MONGODB_URI');
            if (!\is_string($uri) || $uri === '') {
                self::markTestSkipped('MONGODB_URI is not set; start the compose stack (task up).');
            }

            $this->client = new Client($uri, [], ['typeMap' => [
                'root' => 'array',
                'document' => 'array',
                'array' => 'array',
            ]]);
        }

        return $this->client;
    }

    protected function database(): Database
    {
        $name = getenv('MONGODB_DATABASE');
        $name = \is_string($name) && $name !== '' ? $name : 'mongodb_profiler_test';

        if (!str_ends_with($name, '_test')) {
            self::fail(sprintf('Refusing to run against non-test database "%s".', $name));
        }

        return $this->client()->selectDatabase($name);
    }

    protected function dropCollections(string ...$names): void
    {
        foreach ($names as $name) {
            $this->database()->selectCollection($name)->drop();
        }
    }
}
