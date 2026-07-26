<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Explain;

use MongoDB\Client;

/**
 * Re-runs one stored read command through MongoDB's `explain` (`executionStats`) and parses
 * the plan. `executionStats` executes the read again — safe because only reads are
 * explainable — which yields the docs-examined-vs-returned ratio for free.
 *
 * Owns its client rather than borrowing the application's: the bundle must not require a
 * consuming app to expose a connection service, and the client is built lazily so an install
 * that never opens an explain pays nothing.
 */
final class ExplainRunner
{
    private ?Client $client = null;

    public function __construct(
        private readonly string $uri,
        private readonly string $database,
        private readonly ExplainCommandBuilder $builder,
        private readonly ExplainPlanParser $parser,
    ) {
    }

    public function explain(string $commandName, string $collection, mixed $filter): ExplainResult
    {
        $command = $this->builder->build($commandName, $collection, $filter);

        $cursor = $this->client()->selectDatabase($this->database)->command($command);
        $documents = $cursor->toArray();
        $result = $documents[0] ?? [];

        return $this->parser->parse((array) $result);
    }

    private function client(): Client
    {
        return $this->client ??= new Client($this->uri, [], ['typeMap' => [
            'root' => 'array',
            'document' => 'array',
            'array' => 'array',
        ]]);
    }
}
