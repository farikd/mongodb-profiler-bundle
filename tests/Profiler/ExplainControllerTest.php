<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Profiler;

use Farikd\MongodbProfilerBundle\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Functional test of the dev/test-only explain endpoint via the imported route: it loads a
 * stored profile server-side, re-runs the read with explain, and renders the plan. Drives
 * `GET /_probe` (a real `find` followed by a real `insertOne`, {@see TestKernel::probe()}) to
 * get a genuine profiler token with a captured read at index 0 and a captured write at index
 * 1, so the endpoint is exercised through the real profiler/collector round-trip rather than
 * a hand-seeded profile.
 */
final class ExplainControllerTest extends WebTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $profilerConfig = $options['profiler'] ?? [];
        \assert(\is_array($profilerConfig));

        return new TestKernel($profilerConfig, $options['routes'] ?? true);
    }

    public function testExplainRendersIndexPlanForStoredFind(): void
    {
        $client = $this->createExplainClient();
        $token = $this->probeAndGetToken($client);

        $client->request('POST', '/_mongodb-profiler/explain/' . $token . '/0');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertTrue(
            str_contains($body, 'IXSCAN') || str_contains($body, 'COLLSCAN'),
            'expected the explain fragment to report a scan type, got: ' . $body,
        );
    }

    public function testExplainRejectsNonReadCommand(): void
    {
        $client = $this->createExplainClient();
        $token = $this->probeAndGetToken($client);

        // Index 1 is the probe's recorded `insertOne` — not explainable.
        $client->request('POST', '/_mongodb-profiler/explain/' . $token . '/1');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('not an explainable', (string) $client->getResponse()->getContent());
    }

    public function testUnknownQueryIndexIs404(): void
    {
        $client = $this->createExplainClient();
        $token = $this->probeAndGetToken($client);

        $client->request('POST', '/_mongodb-profiler/explain/' . $token . '/99');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUnknownTokenIs404(): void
    {
        $client = $this->createExplainClient();

        $client->request('POST', '/_mongodb-profiler/explain/no-such-token/0');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function createExplainClient(): KernelBrowser
    {
        $uri = getenv('MONGODB_URI');
        self::assertIsString($uri);

        return self::createClient(['profiler' => [
            'cli' => true,
            'explain' => ['uri' => $uri, 'database' => 'mongodb_profiler_test'],
        ]]);
    }

    private function probeAndGetToken(KernelBrowser $client): string
    {
        $client->request('GET', '/_probe');
        $token = $client->getResponse()->headers->get('X-Debug-Token');
        self::assertIsString($token);

        return $token;
    }
}
