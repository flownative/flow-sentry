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
use Sentry\Severity;
use Throwable;

/**
 * Runs the configured scrubbers in order. If a scrubber returns null the
 * event is discarded and the chain stops. If a scrubber throws, the
 * (possibly partially scrubbed) event is discarded and a synthetic failure
 * event — built from scratch, so nothing from the failed event can leak —
 * is returned instead, making scrubbing failures visible in Sentry.
 */
final class ScrubberChain
{
    /**
     * @param array<string, EventScrubberInterface> $scrubbers indexed by registry identifier, in execution order
     */
    public function __construct(
        private readonly array $scrubbers
    ) {
    }

    public function process(Event $event, ?EventHint $hint): ?Event
    {
        $originalEventId = (string)$event->getId();
        $originalExceptionClass = null;
        $originalExceptionCode = null;
        if ($hint?->exception instanceof Throwable) {
            $originalExceptionClass = get_class($hint->exception);
            $code = $hint->exception->getCode();
            $originalExceptionCode = is_int($code) ? $code : null;
        } elseif ($event->getExceptions() !== []) {
            $originalExceptionClass = $event->getExceptions()[0]->getType();
        }

        foreach ($this->scrubbers as $identifier => $scrubber) {
            try {
                $event = $scrubber->scrub($event, $hint);
            } catch (Throwable $throwable) {
                return $this->createFailureEvent((string)$identifier, $throwable, $originalEventId, $originalExceptionClass, $originalExceptionCode);
            }
            if ($event === null) {
                return null;
            }
        }
        return $event;
    }

    /**
     * The failure event is allowlist-by-construction: every field is set
     * deliberately and nothing is derived from the discarded event apart from
     * the explicitly chosen facts (identifiers, class names, integer code).
     */
    private function createFailureEvent(string $identifier, Throwable $throwable, string $originalEventId, ?string $originalExceptionClass, ?int $originalExceptionCode): ?Event
    {
        try {
            $scrubberExceptionClass = self::normalizeClassName(get_class($throwable));

            $event = Event::createEvent();
            $event->setMessage('Flownative Sentry event scrubber failed – the original event was discarded');
            $event->setLevel(Severity::error());
            $event->setLogger('flownative.sentry.scrubber');
            $event->setFingerprint(['flownative-sentry-scrubber-failure', $identifier, $scrubberExceptionClass]);

            $extra = [
                'scrubber' => $identifier,
                'scrubber_exception_class' => $scrubberExceptionClass,
                'discarded_event_id' => $originalEventId,
            ];
            if ($originalExceptionClass !== null) {
                $extra['original_exception_class'] = self::normalizeClassName($originalExceptionClass);
            }
            if ($originalExceptionCode !== null) {
                $extra['original_exception_code'] = $originalExceptionCode;
            }
            $event->setExtra($extra);

            return $event;
        } catch (Throwable) {
            // Deliberately without any detail: nothing from the failed path may leak
            error_log('Flownative.Sentry: event scrubber failure event could not be created; event discarded');
            return null;
        }
    }

    /**
     * Anonymous class names contain file paths and must not be transmitted.
     */
    public static function normalizeClassName(string $className): string
    {
        if (str_contains($className, '@anonymous')) {
            return '[anonymous]';
        }
        return preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $className) === 1 ? $className : '[invalid-class]';
    }
}
