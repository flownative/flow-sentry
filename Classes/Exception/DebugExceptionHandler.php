<?php
declare(strict_types=1);

namespace Flownative\Sentry\Exception;

/*
 * This file is part of the Flownative.Sentry package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flownative\Sentry\SentryClientTrait;
use Throwable;

class DebugExceptionHandler extends \Neos\Flow\Error\DebugExceptionHandler
{
    use SentryClientTrait;

    /**
     * @param Throwable $exception
     */
    public function echoExceptionWeb($exception): void
    {
        try {
            if ($sentryClient = self::getSentryClient()) {
                $sentryClient->captureThrowable($exception);
            }
        } catch (\Throwable $e) {
        }
        parent::echoExceptionWeb($exception);
    }

    /**
     * @param Throwable $exception
     */
    public function echoExceptionCli(Throwable $exception): void
    {
        try {
            if ($sentryClient = self::getSentryClient()) {
                $sentryClient->captureThrowable($exception);
            }
        } catch (\Throwable $e) {
        }
        parent::echoExceptionCli($exception);
    }
}
