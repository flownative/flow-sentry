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
use Sentry\Breadcrumb;
use Sentry\SentrySdk;

class SentryFileBackend extends FileBackend
{
    use SentryClientTrait;

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
        try {
            SentrySdk::getCurrentHub()->addBreadcrumb(
                new Breadcrumb(
                    $this->getBreadcrumbLevel($severity),
                    $this->getBreadcrumbType($severity),
                    basename($this->logFileUrl),
                    $message,
                    ($additionalData ?? []) + array_filter(compact('packageKey', 'className', 'methodName')),
                    time()
                )
            );
        } catch (\Throwable $throwable) {
            parent::append(
                sprintf('%s (%s)', $throwable->getMessage(), $throwable->getCode()),
                LOG_WARNING,
                null,
                'Flownative.Sentry',
                __CLASS__,
                __METHOD__
            );
        }

        parent::append($message, $severity, $additionalData, $packageKey, $className, $methodName);
    }

    private function getBreadcrumbLevel(int $severity): string
    {
        return match ($severity) {
            LOG_EMERG, LOG_ALERT, LOG_CRIT => Breadcrumb::LEVEL_FATAL,
            LOG_ERR => Breadcrumb::LEVEL_ERROR,
            LOG_WARNING => Breadcrumb::LEVEL_WARNING,
            LOG_NOTICE, LOG_INFO => Breadcrumb::LEVEL_INFO,
            default => Breadcrumb::LEVEL_DEBUG,
        };
    }

    private function getBreadcrumbType(int $severity): string
    {
        if ($severity >= LOG_ERR) {
            return Breadcrumb::TYPE_ERROR;
        }

        return Breadcrumb::TYPE_DEFAULT;
    }
}
