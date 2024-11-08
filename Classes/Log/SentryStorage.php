<?php
declare(strict_types=1);

namespace Flownative\Sentry\Log;

use Flownative\Sentry\SentryClientTrait;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\ThrowableStorageInterface;

/**
 * Stores detailed information about throwables into files.
 *
 * @phpstan-consistent-constructor
 * @Flow\Proxy(false)
 * @Flow\Autowiring(false)
 */
class SentryStorage implements ThrowableStorageInterface
{
    use SentryClientTrait;

    /**
     * Factory method to get an instance.
     *
     * @param array $options
     * @return ThrowableStorageInterface
     */
    public static function createWithOptions(array $options): ThrowableStorageInterface
    {
        return new static();
    }

    /**
     * @param \Closure $requestInformationRenderer
     * @return ThrowableStorageInterface
     */
    public function setRequestInformationRenderer(\Closure $requestInformationRenderer): ThrowableStorageInterface
    {
        return $this;
    }

    /**
     * @param \Closure $backtraceRenderer
     * @return ThrowableStorageInterface
     */
    public function setBacktraceRenderer(\Closure $backtraceRenderer): ThrowableStorageInterface
    {
        return $this;
    }

    /**
     * Stores information about the given exception and returns information about
     * the exception and where the details have been stored. The returned message
     * can be logged or displayed as needed.
     *
     * The returned message follows this pattern:
     * Exception #<code> in <line> of <file>: <message> - See also: <dumpFilename>
     *
     * @param \Throwable $throwable
     * @param array $additionalData
     * @return string Informational message about the stored throwable
     */
    public function logThrowable(\Throwable $throwable, array $additionalData = []): string
    {
        $message = $this->getErrorLogMessage($throwable);
        try {
            if ($sentryClient = self::getSentryClient()) {
                $captureResult = $sentryClient->captureThrowable($throwable, $additionalData);
                if ($captureResult->suceess) {
                    $message .= ' (Sentry: #' . $captureResult->eventId . ')';
                } else {
                    $message .= ' (Sentry: ' . $captureResult->message . ')';
                }
            }
        } catch (\Throwable $e) {
            $message .= ' – Error capturing message: ' . $this->getErrorLogMessage($e);
        }

        return $message;
    }

    protected function getErrorLogMessage(\Throwable $error): string
    {
        $errorCodeNumber = ($error->getCode() > 0) ? ' #' . $error->getCode() : '';
        $backTrace = $error->getTrace();
        $line = isset($backTrace[0]['line']) ? ' in line ' . $backTrace[0]['line'] . ' of ' . $backTrace[0]['file'] : '';

        return 'Exception' . $errorCodeNumber . $line . ': ' . $error->getMessage();
    }
}
