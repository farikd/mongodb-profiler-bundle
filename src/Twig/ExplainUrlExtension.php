<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Twig;

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Resolves the explain endpoint for the panel — or null when the feature is unavailable.
 *
 * A bundle cannot register routes, so an installer who skips the routes import would
 * otherwise get `RouteNotFoundException` while rendering the panel: the whole profiler
 * broken, blamed on this package. Misconfiguration must cost a feature, not the tool.
 */
final class ExplainUrlExtension extends AbstractExtension
{
    public const string ROUTE = '_mongodb_profiler_explain';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly bool $explainConfigured,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mongodb_profiler_explain_url', $this->explainUrl(...)),
        ];
    }

    public function explainUrl(string $token, int $index): ?string
    {
        if (!$this->explainConfigured) {
            return null;
        }

        try {
            return $this->urlGenerator->generate(self::ROUTE, ['token' => $token, 'index' => $index]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
