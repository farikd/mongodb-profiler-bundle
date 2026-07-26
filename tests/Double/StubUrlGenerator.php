<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Double;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Hand-written fake standing in for a router that DOES know the explain route — the
 * opposite state of {@see ThrowingUrlGenerator}. Records the last call so a test can
 * assert what was asked for, without booting a kernel.
 */
final class StubUrlGenerator implements UrlGeneratorInterface
{
    private RequestContext $context;

    /** @var array{name: string, parameters: array<string, mixed>}|null */
    public ?array $lastCall = null;

    public function __construct(private readonly string $url = 'https://example.test/explain')
    {
        $this->context = new RequestContext();
    }

    /** @param array<string, mixed> $parameters */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        $this->lastCall = ['name' => $name, 'parameters' => $parameters];

        return $this->url;
    }

    public function setContext(RequestContext $context): void
    {
        $this->context = $context;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }
}
