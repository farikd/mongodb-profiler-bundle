<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Tests\Profiler;

use Farikd\MongodbProfilerBundle\Profiler\FilterFormatter;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The formatter is the fix for "I can't see the $match/$group args": profiler_dump cut
 * nested filter/pipeline values server-side, so we render readable JSON instead. These
 * tests pin that every level survives and that BSON scalars read mongo-shell style.
 */
final class FilterFormatterTest extends TestCase
{
    public function testRendersSimpleFindFilter(): void
    {
        $json = (new FilterFormatter())->toReadable(['status' => 'discovered']);

        self::assertSame("{\n    \"status\": \"discovered\"\n}", $json);
    }

    public function testRendersEveryLevelOfAPipeline(): void
    {
        // The whole point of the change: the $match condition and the $group accumulators
        // must all be present, not cut to `{ … }`.
        $json = (new FilterFormatter())->toReadable([
            ['$match' => ['deletedAt' => null]],
            ['$group' => ['_id' => '$status', 'count' => ['$sum' => 1]]],
        ]);

        self::assertStringContainsString('"$match"', $json);
        self::assertStringContainsString('"deletedAt": null', $json);
        self::assertStringContainsString('"$group"', $json);
        self::assertStringContainsString('"_id": "$status"', $json);
        self::assertStringContainsString('"$sum": 1', $json);
    }

    public function testDistinguishesDocumentFromList(): void
    {
        $formatter = new FilterFormatter();

        // An empty document stays `{}`, an empty array stays `[]`.
        self::assertSame('{}', $formatter->toReadable(new stdClass()));
        self::assertSame('[]', $formatter->toReadable([]));
    }

    public function testRendersBsonScalarsMongoShellStyle(): void
    {
        $formatter = new FilterFormatter();
        $id = new ObjectId('5a2493c33c95a1281836eb6a');

        $json = $formatter->toReadable([
            '_id' => $id,
            'createdAt' => new UTCDateTime(0),
            'title' => new Regex('^foo', 'i'),
        ]);

        self::assertStringContainsString('"_id": "ObjectId(5a2493c33c95a1281836eb6a)"', $json);
        self::assertStringContainsString('"createdAt": "1970-01-01T00:00:00.000Z"', $json);
        self::assertStringContainsString('"title": "/^foo/i"', $json);
    }

    public function testRecursesIntoBsonDocumentAndArray(): void
    {
        $filter = new BSONDocument([
            '$or' => new BSONArray([
                new BSONDocument(['status' => 'indexed']),
                new BSONDocument(['status' => 'transcribed']),
            ]),
        ]);

        $json = (new FilterFormatter())->toReadable($filter);

        self::assertStringContainsString('"$or"', $json);
        self::assertStringContainsString('"status": "indexed"', $json);
        self::assertStringContainsString('"status": "transcribed"', $json);
        // BSONArray became a JSON list, BSONDocument a JSON object.
        self::assertStringContainsString('[', $json);
    }

    public function testKeepsUnicodeReadable(): void
    {
        // Ukrainian text must stay legible, not be escaped to \uXXXX.
        $json = (new FilterFormatter())->toReadable(['q' => 'Юрко']);

        self::assertStringContainsString('Юрко', $json);
    }

    public function testClipsAPathologicallyLargeFilter(): void
    {
        $json = (new FilterFormatter())->toReadable(['_id' => ['$in' => range(1, 5000)]]);

        self::assertStringEndsWith('… (clipped)', $json);
        self::assertLessThan(21000, \strlen($json));
    }
}
