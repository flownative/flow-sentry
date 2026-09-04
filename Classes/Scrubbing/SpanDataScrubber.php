<?php
declare(strict_types=1);

namespace Flownative\Sentry\Scrubbing;

/*
 * This file is part of the Flownative.Sentry package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Sentry\Event;
use Sentry\EventHint;

/**
 * Scrubs tracing data of transaction events: span data is dropped entirely,
 * URL query strings are removed from the transaction name, span
 * descriptions and span tag values, and the trace context's data is
 * cleared. SQL placeholders ("?") in span descriptions are preserved —
 * only query strings attached to URLs are cut.
 */
final class SpanDataScrubber implements EventScrubberInterface
{
    /**
     * Absolute and scheme-relative URLs, HTTP-verb-prefixed relative targets
     * ("GET /path?x=1") and pure-path values ("/path?x=1"). SQL placeholders
     * ("WHERE id = ?") match none of these. Span data KEYS are kept — they
     * are schema-like identifiers; their values are overwritten.
     */
    private const URL_QUERY_PATTERNS = [
        '~((?:https?:)?//[^\s?#"\']+)\?[^\s"\']*~i',
        '~\b((?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+/[^\s?]*)\?\S*~',
        '~(?<=^)(/[^\s?]*)\?\S*(?=$)~',
    ];

    public function scrub(Event $event, ?EventHint $hint): ?Event
    {
        $transaction = $event->getTransaction();
        if ($transaction !== null) {
            $event->setTransaction($this->stripUrlQueries($transaction));
        }

        foreach ($event->getSpans() as $span) {
            // Span::setData() merges, so existing keys are overwritten with a marker
            $spanData = $span->getData();
            if (is_array($spanData) && $spanData !== []) {
                $span->setData(array_fill_keys(array_keys($spanData), '[Filtered]'));
            }
            $description = $span->getDescription();
            if ($description !== null) {
                $span->setDescription($this->stripUrlQueries($description));
            }
            $scrubbedSpanTags = [];
            foreach ($span->getTags() as $tagKey => $tagValue) {
                $scrubbedSpanTags[$tagKey] = $this->stripUrlQueries((string)$tagValue);
            }
            if ($scrubbedSpanTags !== []) {
                $span->setTags($scrubbedSpanTags);
            }
        }

        $contexts = $event->getContexts();
        if (isset($contexts['trace']) && is_array($contexts['trace']) && isset($contexts['trace']['data'])) {
            $traceContext = $contexts['trace'];
            $traceContext['data'] = [];
            $event->setContext('trace', $traceContext);
        }

        return $event;
    }

    private function stripUrlQueries(string $value): string
    {
        foreach (self::URL_QUERY_PATTERNS as $pattern) {
            $value = preg_replace($pattern, '$1', $value) ?? $value;
        }
        return $value;
    }
}
