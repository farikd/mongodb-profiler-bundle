<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Double;

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Hand-written fake standing in for a real router that has no route named
 * `_mongodb_profiler_explain` — the state an installer leaves the container in by
 * skipping the routes import while still configuring `explain`. Always throws, so a test
 * using it exercises {@see \Farikd\MongodbProfilerBundle\Twig\ExplainUrlExtension}'s
 * catch branch directly, without booting a kernel.
 */
final class ThrowingUrlGenerator implements UrlGeneratorInterface
{
    private RequestContext $context;

    public function __construct()
    {
        $this->context = new RequestContext();
    }

    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        throw new RouteNotFoundException(\sprintf('Route not found: "%s".', $name));
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
