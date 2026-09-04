<?php
declare(strict_types=1);

namespace Flownative\Sentry\Tests\Unit\Scrubbing;

use Flownative\Sentry\Scrubbing\SpanDataScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\Tracing\Span;

final class SpanDataScrubberTest extends TestCase
{
    public function testTransactionNameLosesUrlQuery(): void
    {
        $event = Event::createEvent();
        $event->setTransaction('GET https://example.com/find?search=visitor%40example.com');

        $event = (new SpanDataScrubber())->scrub($event, null);

        self::assertSame('GET https://example.com/find', $event->getTransaction());
    }

    public function testSpanDataIsDroppedAndDescriptionQueryStripped(): void
    {
        $span = new Span();
        $span->setDescription('GET https://api.example.com/person?email=visitor%40example.com');
        $span->setData(['body' => 'secret']);

        $event = Event::createEvent();
        $event->setSpans([$span]);

        (new SpanDataScrubber())->scrub($event, null);

        self::assertSame(['body' => '[Filtered]'], $span->getData());
        self::assertSame('GET https://api.example.com/person', $span->getDescription());
    }

    public function testSqlPlaceholdersSurvive(): void
    {
        $span = new Span();
        $span->setDescription('SELECT * FROM feedback WHERE id = ? AND text = ?');

        $event = Event::createEvent();
        $event->setSpans([$span]);

        (new SpanDataScrubber())->scrub($event, null);

        self::assertSame('SELECT * FROM feedback WHERE id = ? AND text = ?', $span->getDescription());
    }

    public function testRelativeAndVerbPrefixedTargetsLoseTheirQuery(): void
    {
        $span = new Span();
        $span->setDescription('GET /person?email=visitor%40example.com');

        $event = Event::createEvent();
        $event->setTransaction('/find?search=secret');
        $event->setSpans([$span]);

        $event = (new SpanDataScrubber())->scrub($event, null);

        self::assertSame('/find', $event->getTransaction());
        self::assertSame('GET /person', $span->getDescription());
    }

    public function testTraceContextDataIsCleared(): void
    {
        $event = Event::createEvent();
        $event->setContext('trace', ['trace_id' => 'abc', 'data' => ['url' => 'https://x?y=z']]);

        $event = (new SpanDataScrubber())->scrub($event, null);

        self::assertSame([], $event->getContexts()['trace']['data']);
        self::assertSame('abc', $event->getContexts()['trace']['trace_id']);
    }
}
