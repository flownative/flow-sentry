<?php
declare(strict_types=1);

namespace Flownative\Sentry\Log;

use Flownative\Sentry\SentryClientTrait;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Log\ThrowableStorage\FileStorage;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\ObjectManagement\CompileTimeObjectManager;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;

/**
 * Captures a throwable to Sentry and (optionally) also stores a Flow exception dump file
 * (Data/Logs/Exceptions/*.txt) like the default FileStorage.
 *
 * The file dump logging can be disabled via `Flownative.Sentry.storageLogging` (default: true).
 *
 * @phpstan-consistent-constructor
 * @Flow\Proxy(false)
 * @Flow\Autowiring(false)
 */
class SentryStorage implements ThrowableStorageInterface
{
    use SentryClientTrait;

    private ?FileStorage $fileStorage = null;
    private array $fileStorageOptions = [];

    private ?bool $storageLoggingOption = null;
    private ?bool $resolvedStorageLogging = null;

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
        $storageLoggingOption = array_key_exists('storageLogging', $options) ? (bool)$options['storageLogging'] : null;

        // Allow passing FileStorage options either directly, or nested under "fileStorageOptions".
        $fileStorageOptions = $options['fileStorageOptions'] ?? $options;
        if (is_array($fileStorageOptions) && array_key_exists('storageLogging', $fileStorageOptions)) {
            unset($fileStorageOptions['storageLogging']);
        }
        if (is_array($fileStorageOptions) && array_key_exists('fileStorageOptions', $fileStorageOptions)) {
            unset($fileStorageOptions['fileStorageOptions']);
        }

        return new static($fileStorageOptions, $storageLoggingOption);
    }

    public function __construct(array $fileStorageOptions = [], ?bool $storageLoggingOption = null)
    {
        $this->fileStorageOptions = $fileStorageOptions;
        $this->storageLoggingOption = $storageLoggingOption;
    }

    /**
     * @param \Closure $requestInformationRenderer
     * @return ThrowableStorageInterface
     */
    public function setRequestInformationRenderer(\Closure $requestInformationRenderer): ThrowableStorageInterface
    {
        $this->requestInformationRenderer = $requestInformationRenderer;
        if ($this->fileStorage !== null) {
            $this->fileStorage->setRequestInformationRenderer($requestInformationRenderer);
        }
        return $this;
    }

    /**
     * @param \Closure $backtraceRenderer
     * @return ThrowableStorageInterface
     */
    public function setBacktraceRenderer(\Closure $backtraceRenderer): ThrowableStorageInterface
    {
        $this->backtraceRenderer = $backtraceRenderer;
        if ($this->fileStorage !== null) {
            $this->fileStorage->setBacktraceRenderer($backtraceRenderer);
        }
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
        $message = $this->isStorageLoggingEnabled()
            ? $this->getFileStorage()->logThrowable($throwable, $additionalData)
            : $this->getErrorLogMessage($throwable);

        // Also capture to Sentry (best-effort)
        try {
            if ($sentryClient = self::getSentryClient()) {
                $captureResult = $sentryClient->captureThrowable($throwable, $additionalData);

                return sprintf(
                    '%s (Sentry: %s – %s)',
                    $message,
                    $captureResult->eventId ? '#' . $captureResult->eventId : 'no ID',
                    $captureResult->message ?: ($captureResult->suceess ? 'ok' : 'failed'),
                );
            }
        } catch (\Throwable $e) {
            return sprintf('%s (Sentry: Error capturing message – %s [%s])', $message, $e->getMessage(), (string)$e->getCode());
        }

        return $message . ' (Sentry: no client available)';
    }

    private function getFileStorage(): FileStorage
    {
        if ($this->fileStorage === null) {
            $this->fileStorage = FileStorage::createWithOptions($this->fileStorageOptions);
            if ($this->requestInformationRenderer !== null) {
                $this->fileStorage->setRequestInformationRenderer($this->requestInformationRenderer);
            }
            if ($this->backtraceRenderer !== null) {
                $this->fileStorage->setBacktraceRenderer($this->backtraceRenderer);
            }
        }

        return $this->fileStorage;
    }

    private function isStorageLoggingEnabled(): bool
    {
        if ($this->resolvedStorageLogging !== null) {
            return $this->resolvedStorageLogging;
        }

        if ($this->storageLoggingOption !== null) {
            return $this->resolvedStorageLogging = $this->storageLoggingOption;
        }

        $settings = $this->getPackageSettings();
        if (is_array($settings) && array_key_exists('storageLogging', $settings)) {
            return $this->resolvedStorageLogging = (bool)$settings['storageLogging'];
        }

        return $this->resolvedStorageLogging = true;
    }

    private function getPackageSettings(): ?array
    {
        try {
            if (!Bootstrap::$staticObjectManager instanceof ObjectManagerInterface || Bootstrap::$staticObjectManager instanceof CompileTimeObjectManager) {
                return null;
            }

            $configurationManager = Bootstrap::$staticObjectManager->get(ConfigurationManager::class);
            /** @var array|null $settings */
            $settings = $configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Flownative.Sentry');

            return is_array($settings) ? $settings : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getErrorLogMessage(\Throwable $error): string
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
