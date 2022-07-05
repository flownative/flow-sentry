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

class ProductionExceptionHandler extends \Neos\Flow\Error\ProductionExceptionHandler
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

        $exceptionWasLogged = false;
        if ($this->throwableStorage instanceof ThrowableStorageInterface && isset($this->renderingOptions['logException']) && $this->renderingOptions['logException']) {
            $message = $this->throwableStorage->logThrowable($exception);
            $this->logger->critical($message);
            $exceptionWasLogged = true;
        }

        try {
            if ($sentryClient = self::getSentryClient()) {
                $sentryClient->captureThrowable($exception);
            }
        } catch (\Throwable $e) {
        }

        switch (PHP_SAPI) {
            case 'cli':
                # Doesn't return:
                $this->echoExceptionCli($exception, $exceptionWasLogged);
            break;
            default:
                $this->echoExceptionWeb($exception);
        }
    }
}
