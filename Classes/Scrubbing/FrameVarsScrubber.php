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
use Sentry\Frame;
use Sentry\Stacktrace;

/**
 * Rebuilds all stack traces of an event with frames stripped of their
 * variables (captured function arguments), absolute file paths and source
 * context. Function names, cleaned file paths and line numbers remain, so
 * traces stay debuggable.
 */
final class FrameVarsScrubber implements EventScrubberInterface
{
    public function scrub(Event $event, ?EventHint $hint): ?Event
    {
        foreach ($event->getExceptions() as $exceptionDataBag) {
            $stacktrace = $exceptionDataBag->getStacktrace();
            if ($stacktrace !== null) {
                $exceptionDataBag->setStacktrace($this->rebuildStacktrace($stacktrace));
            }
        }

        $eventStacktrace = $event->getStacktrace();
        if ($eventStacktrace !== null) {
            $event->setStacktrace($this->rebuildStacktrace($eventStacktrace));
        }

        return $event;
    }

    private function rebuildStacktrace(Stacktrace $stacktrace): Stacktrace
    {
        $strippedFrames = [];
        foreach ($stacktrace->getFrames() as $frame) {
            $strippedFrames[] = new Frame(
                $this->stripAnonymousClassPaths($frame->getFunctionName()),
                $this->relativizeFilePath($frame->getFile()),
                $frame->getLine(),
                $this->stripAnonymousClassPaths($frame->getRawFunctionName()),
                null,
                [],
                $frame->isInApp()
            );
        }
        return new Stacktrace($strippedFrames);
    }

    /**
     * Frame::getFile() regularly carries the absolute path for files outside
     * the Flow proxy cache; local usernames and server layout must not leave
     * the system.
     */
    private function relativizeFilePath(string $file): string
    {
        if (!str_starts_with($file, '/')) {
            return $file;
        }
        if (defined('FLOW_PATH_ROOT') && str_starts_with($file, FLOW_PATH_ROOT)) {
            return substr($file, strlen(FLOW_PATH_ROOT));
        }
        return '…/' . basename($file);
    }

    /**
     * Anonymous class names embed file paths (class@anonymous/path/file.php:12).
     */
    private function stripAnonymousClassPaths(?string $functionName): ?string
    {
        if ($functionName === null || !str_contains($functionName, '@anonymous')) {
            return $functionName;
        }
        return preg_replace('/@anonymous[^:]*(?::\d+)?(\$\w+)?/', '@anonymous', $functionName) ?? '[anonymous]';
    }
}
