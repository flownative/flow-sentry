<?php
declare(strict_types=1);

namespace Flownative\Sentry\Log;

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
use Neos\Flow\Log\Backend\FileBackend;
use Sentry\Severity;

class SentryFileBackend extends FileBackend
{
    use SentryClientTrait;

    private bool $capturingMessage = false;

    /**
     * Appends the given message along with the additional information into the log.
     *
     * @param string $message The message to log
     * @param int $severity One of the LOG_* constants
     * @param mixed $additionalData A variable containing more information about the event to be logged
     * @param string|null $packageKey Key of the package triggering the log
     * @param string|null $className Name of the class triggering the log
     * @param string|null $methodName Name of the method triggering the log
     * @return void
     */
    public function append(string $message, int $severity = LOG_INFO, $additionalData = null, ?string $packageKey = null, ?string $className = null, ?string $methodName = null): void
    {
        if ($this->capturingMessage) {
            return;
        }

        try {
            $this->capturingMessage = true;

            $sentryClient = self::getSentryClient();
            if ($severity <= LOG_NOTICE && $sentryClient) {
                $sentrySeverity = match ($severity) {
                    LOG_WARNING => Severity::warning(),
                    LOG_ERR => Severity::error(),
                    LOG_CRIT, LOG_ALERT, LOG_EMERG => Severity::fatal(),
                    default => Severity::info(),
                };

                }

                $sentryClient->captureMessage($message, $sentrySeverity, ['Additional Data' => $additionalData]);
            }
            parent::append($message, $severity, $additionalData, $packageKey, $className, $methodName);
        } catch (\Throwable $throwable) {
            echo sprintf('SentryFileBackend: %s (%s)', $throwable->getMessage(), $throwable->getCode());
        } finally {
            $this->capturingMessage = false;
        }
    }
}
