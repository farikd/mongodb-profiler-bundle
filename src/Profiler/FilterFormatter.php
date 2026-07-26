<?php

declare(strict_types=1);

namespace Farikd\MongodbProfilerBundle\Profiler;

use MongoDB\BSON\Binary;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Int64;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\Timestamp;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use Symfony\Bundle\WebProfilerBundle\Twig\WebProfilerExtension;

/**
 * Renders a captured Mongo filter/pipeline as readable, pretty-printed JSON for the
 * profiler panel — the "show the query" presentation a query profiler wants (like
 * Doctrine surfacing the SQL).
 *
 * Why not {@see WebProfilerExtension::dumpData}
 * (`profiler_dump`): its `maxDepth` argument only drives the *client-side* auto-expand;
 * the profiler's shared `HtmlDumper` cuts the tree server-side around depth two, so the
 * `$match`/`$group` arguments never reach the HTML and no depth argument can bring them
 * back. Formatting to JSON ourselves is deterministic and shows every level.
 *
 * BSON scalar types (ObjectId, dates, regex, …) are rendered mongo-shell style as short
 * strings; documents/arrays recurse, preserving object-vs-array shape so `{}` and `[]`
 * read correctly.
 */
final class FilterFormatter
{
    private const int MAX_LENGTH = 20000;

    public function toReadable(mixed $filter): string
    {
        $json = json_encode(
            $this->normalize($filter),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        if (false === $json) {
            return '/* unrenderable filter */';
        }

        // A pathological filter (e.g. a huge $in) should not blow up the panel; show a
        // head and say it was clipped rather than render megabytes into one table cell.
        if (\strlen($json) > self::MAX_LENGTH) {
            return substr($json, 0, self::MAX_LENGTH)."\n… (clipped)";
        }

        return $json;
    }

    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof ObjectId => 'ObjectId('.$value->__toString().')',
            $value instanceof UTCDateTime => $value->toDateTime()->format('Y-m-d\TH:i:s.v\Z'),
            $value instanceof Regex => '/'.$value->getPattern().'/'.$value->getFlags(),
            $value instanceof Decimal128 => (string) $value,
            $value instanceof Int64 => (string) $value,
            $value instanceof Timestamp => (string) $value,
            $value instanceof Binary => 'Binary(0x'.bin2hex($value->getData()).')',
            $value instanceof BSONArray => array_map($this->normalize(...), $value->getArrayCopy()),
            $value instanceof BSONDocument => $this->normalizeAssoc($value->getArrayCopy()),
            $value instanceof \stdClass => $this->normalizeAssoc((array) $value),
            \is_array($value) => array_is_list($value)
                ? array_map($this->normalize(...), $value)
                : $this->normalizeAssoc($value),
            \is_object($value) => $value instanceof \Stringable ? (string) $value : '('.$value::class.')',
            default => $value,
        };
    }

    /**
     * @param array<array-key, mixed> $assoc
     */
    private function normalizeAssoc(array $assoc): \stdClass
    {
        // Keep it an object so json_encode emits `{}` (a document), not `[]`.
        $out = new \stdClass();
        foreach ($assoc as $key => $value) {
            $out->{(string) $key} = $this->normalize($value);
        }

        return $out;
    }
}
