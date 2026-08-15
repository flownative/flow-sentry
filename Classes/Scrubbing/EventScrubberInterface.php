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
 * A scrubber removes or redacts data from an event before it is sent to
 * Sentry. Scrubbers are registered explicitly via the
 * Flownative.Sentry.scrubbers settings and run for error events,
 * transactions and check-ins, after the configured exception excludes.
 *
 * Implementations are constructed with the "options" array of their
 * registry entry: __construct(array $options = []).
 *
 * The scrub() implementation may mutate the given event, replace it, or
 * return null to discard it. If a scrubber throws, the event is discarded
 * and a synthetic failure event is sent instead (fail-closed, fail-loud).
 *
 * The EventHint may contain the original throwable including unscrubbed
 * data — it must never be copied into the event or logged.
 */
interface EventScrubberInterface
{
    public function scrub(Event $event, ?EventHint $hint): ?Event;
}
