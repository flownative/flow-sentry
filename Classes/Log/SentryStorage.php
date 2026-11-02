<?php
declare(strict_types=1);

namespace Flownative\Sentry\Log;

use Flownative\Sentry\SentryClientTrait;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\ThrowableStorage\FileStorage;
use Neos\Flow\Log\ThrowableStorageInterface;

/**
 * Captures a throwable to Sentry and writes a dump file through Flow's FileStorage
 * if the option writeDumpFiles asks for it or capturing fails.
 *
 * @phpstan-consistent-constructor
 * @Flow\Proxy(false)
 * @Flow\Autowiring(false)
 */
class SentryStorage implements ThrowableStorageInterface
{
    use SentryClientTrait;

    private array $options = [];
    private ?\Closure $requestInformationRenderer = null;
    private ?\Closure $backtraceRenderer = null;

    /**
     * Factory method to get an instance.
     *
     * @param array $options
     * @return ThrowableStorageInterface
     */
    public static function createWithOptions(array $options): ThrowableStorageInterface
    {
        $storage = new static();
        $storage->options = $options;
        return $storage;
    }

    /**
     * @param \Closure $requestInformationRenderer
     * @return ThrowableStorageInterface
     */
    public function setRequestInformationRenderer(\Closure $requestInformationRenderer): ThrowableStorageInterface
    {
        $this->requestInformationRenderer = $requestInformationRenderer;
        return $this;
    }

    /**
     * @param \Closure $backtraceRenderer
     * @return ThrowableStorageInterface
     */
    public function setBacktraceRenderer(\Closure $backtraceRenderer): ThrowableStorageInterface
    {
        $this->backtraceRenderer = $backtraceRenderer;
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
        $captured = false;
        $sentryMessage = 'no client available';
        try {
            if ($sentryClient = self::getSentryClient()) {
                $captureResult = $sentryClient->captureThrowable($throwable, $additionalData);
                $captured = $captureResult->suceess;
                $sentryMessage = sprintf(
                    '%s – %s',
                    $captureResult->eventId ? '#' . $captureResult->eventId : 'no ID',
                    $captureResult->message ?: ($captured ? 'ok' : 'failed'),
                );
            }
        } catch (\Throwable $e) {
            $sentryMessage = 'Error capturing message – ' . $this->getErrorLogMessage($e);
        }

        $message = !$captured || ($this->options['writeDumpFiles'] ?? false)
            ? $this->writeDumpFile($throwable, $additionalData)
            : $this->getErrorLogMessage($throwable);

        return sprintf('%s (Sentry: %s)', $message, $sentryMessage);
    }

    private function writeDumpFile(\Throwable $throwable, array $additionalData): string
    {
        try {
            $fileStorage = FileStorage::createWithOptions($this->options);
            if ($this->requestInformationRenderer !== null) {
                $fileStorage->setRequestInformationRenderer($this->requestInformationRenderer);
            }
            if ($this->backtraceRenderer !== null) {
                $fileStorage->setBacktraceRenderer($this->backtraceRenderer);
            }
            $message = $fileStorage->logThrowable($throwable, $additionalData);
            // FileStorage answers an unwritable directory with a message that omits the throwable.
            return str_contains($message, ' - See also: ') ? $message : $this->getErrorLogMessage($throwable) . ' – ' . $message;
        } catch (\Throwable $e) {
            return $this->getErrorLogMessage($throwable) . ' – Could not write a dump file: ' . $e->getMessage();
        }
    }

    protected function getErrorLogMessage(\Throwable $error): string
    {
        // getCode() does not always return an integer, e.g. in PDOException it can be a string
        if (is_int($error->getCode()) && $error->getCode() > 0) {
            $errorCodeString = ' #' . $error->getCode();
        } else {
            $errorCodeString = ' [' . $error->getCode() . ']';
        }
        $backTrace = $error->getTrace();
        $line = isset($backTrace[0]['line']) ? ' in line ' . $backTrace[0]['line'] . ' of ' . $backTrace[0]['file'] : '';

        return 'Exception' . $errorCodeString . $line . ': ' . $error->getMessage();
    }
}
