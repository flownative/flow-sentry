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
use Neos\Flow\Log\ThrowableStorageInterface;

final class DebugExceptionHandler extends \Neos\Flow\Error\DebugExceptionHandler
{
    use SentryClientTrait;

    /**
     * Handles the given exception
     *
     * @param \Throwable $exception The exception object
     * @return void
     */
    public function handleException($exception)
    {
        // Ignore if the error is suppressed by using the shut-up operator @
        if (error_reporting() === 0) {
            return;
        }

        $this->renderingOptions = $this->resolveCustomRenderingOptions($exception);

        if ($this->throwableStorage instanceof ThrowableStorageInterface && isset($this->renderingOptions['logException']) && $this->renderingOptions['logException']) {
            $message = $this->throwableStorage->logThrowable($exception);
            $this->logger->critical($message);
        }

        switch (PHP_SAPI) {
            case 'cli':
                try {
                    if ($sentryClient = self::getSentryClient()) {
                        $sentryClient->captureThrowable($exception);
                    }
                } catch (\Throwable $e) {
                }
                $this->echoExceptionCli($exception);
            break;
            default:
                try {
                    if ($sentryClient = self::getSentryClient()) {
                        $sentryClient->captureThrowable($exception);
                    }
                } catch (\Throwable $e) {
                }
                $this->echoExceptionWeb($exception);
        }
    }
}
