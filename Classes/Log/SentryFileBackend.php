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

    /**
     * @param string $message
     * @param int $severity
     * @param null $additionalData
     * @param null $packageKey
     * @param null $className
     * @param null $methodName
     * @return void
     */
    public function append($message, $severity = LOG_INFO, $additionalData = null, $packageKey = null, $className = null, $methodName = null): void
    {
        try {
            $sentryClient = self::getSentryClient();
            if ($severity <= LOG_NOTICE && $sentryClient) {
                switch ($severity) {
                    case LOG_WARNING:
                        $sentrySeverity = Severity::warning();
                    break;
                    case LOG_ERR:
                        $sentrySeverity = Severity::error();
                    break;
                    case LOG_CRIT:
                    case LOG_ALERT:
                    case LOG_EMERG:
                        $sentrySeverity = Severity::fatal();
                    break;
                    default:
                        $sentrySeverity = Severity::info();

                }
                $sentryClient->captureMessage($message, $sentrySeverity, ['Additional Data' => $additionalData]);
            }
        } catch (\Throwable $e) {
        }
        parent::append($message, $severity, $additionalData, $packageKey, $className, $methodName);
    }
}
