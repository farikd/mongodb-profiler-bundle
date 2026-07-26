<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Profiler;

use Farikd\MongodbProfilerBundle\Explain\ExplainRunner;
use Farikd\MongodbProfilerBundle\Monitoring\ProfilerSubscriber;
use MongoDB\Driver\Exception\Exception as MongoDriverException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * Dev/test-only endpoint that re-runs one stored read command with `explain`, on demand
 * from the profiler panel's `[explain]` link. The query is loaded from the stored
 * profile server-side (never from a client-submitted body): the client sends only a
 * profiler token + row index.
 *
 * Registered via `config/routes.php`, imported by the consuming app under its own
 * dev/test conditions — env gating is the bundle's `enabled` (no services, no route
 * target) plus the app importing the route only where it wants it.
 */
final class ExplainController extends AbstractController
{
    public function __construct(
        // The Profiler class has no autowiring alias; wired explicitly in config/explain.php.
        private readonly Profiler $profiler,
        private readonly ExplainRunner $runner,
    ) {
    }

    public function __invoke(string $token, int $index): Response
    {
        $profile = $this->profiler->loadProfile($token);
        // The profiler keys collectors by getName(), which AbstractDataCollector
        // defaults to the FQCN — and that must match the template-registry id.
        if (null === $profile || !$profile->hasCollector(MongoDataCollector::class)) {
            throw new NotFoundHttpException('Unknown profiler token.');
        }

        $collector = $profile->getCollector(MongoDataCollector::class);
        if (!$collector instanceof MongoDataCollector) {
            throw new NotFoundHttpException('Unknown profiler token.');
        }

        $query = $collector->getRawQuery($index);
        if (null === $query) {
            throw new NotFoundHttpException('Unknown query index.');
        }

        if (!ProfilerSubscriber::isExplainable($query['commandName'])) {
            return $this->fragment(['error' => \sprintf('"%s" is not an explainable read command.', $query['commandName'])], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->runner->explain($query['commandName'], $query['collection'], $query['filter']);
        } catch (MongoDriverException $exception) {
            // Surface a genuine driver/query error inline rather than 500-ing the panel.
            // Deliberately narrow: a bug in our own builder/parser (a \Error) is NOT caught,
            // so it 500s as the bug it is instead of masquerading as a failed query.
            return $this->fragment(['error' => $exception->getMessage()]);
        }

        return $this->fragment(['result' => $result]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function fragment(array $context, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->renderView('@MongodbProfiler/mongodb_explain.html.twig', $context),
            $status,
        );
    }
}
