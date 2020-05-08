<?php
declare(strict_types=1);

namespace Flownative\Sentry;

/*
 * This file is part of the Flownative.Sentry package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flownative\Sentry\Context\UserContext;
use Flownative\Sentry\Context\UserContextServiceInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Error\WithReferenceCodeInterface;
use Neos\Flow\Exception;
use Neos\Flow\Log\PsrSystemLoggerInterface;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Utility\Environment;
use Psr\Log\LogLevel;
use Sentry\Options;
use Sentry\Severity;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Throwable;

/**
 * @Flow\Scope("singleton")
 */
class SentryClient
{
    /**
     * @var string
     */
    protected $dsn;

    /**
     * @var string
     */
    protected $environment;

    /**
     * @var string
     */
    protected $release;

    /**
     * @var array
     */
    protected $excludeExceptionTypes = [];

    /**
     * @Flow\Inject
     * @var UserContextServiceInterface
     */
    protected $userContextService;

    /**
     * @Flow\Inject
     * @var PsrSystemLoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var PackageManager
     */
    protected $packageManager;

    /**
     * @param array $settings
     */
    public function injectSettings(array $settings): void
    {
        $this->dsn = $settings['dsn'] ?? '';
        $this->environment = $settings['environment'] ?? '';
        $this->release = $settings['release'] ?? '';
        $this->excludeExceptionTypes = $settings['capture']['excludeExceptionTypes'] ?? [];
    }

    /**
     * Initialize the Sentry client and fatal error handler (shutdown function)
     */
    public function initializeObject(): void
    {
        if (empty($this->dsn)) {
            return;
        }
        \Sentry\init([
            'dsn' => $this->dsn,
            'environment' => $this->environment,
            'release' => $this->release,
            'project_root' => FLOW_PATH_ROOT,
            'prefixes' => [FLOW_PATH_ROOT],
            'sample_rate' => 1,
            'in_app_exclude' => [
                FLOW_PATH_ROOT . '/Packages/Application/Flownative.Sentry/Classes/',
                FLOW_PATH_ROOT . '/Packages/Framework/Neos.Flow/Classes/Aop/',
                FLOW_PATH_ROOT . '/Packages/Framework/Neos.Flow/Classes/Error/',
                FLOW_PATH_ROOT . '/Packages/Framework/Neos.Flow/Classes/Log/',
                FLOW_PATH_ROOT . '/Packages/Libraries/neos/flow-log/'
            ],
            'default_integrations' => false,
            'attach_stacktrace' => true
        ]);

        $client = Hub::getCurrent()->getClient();
        if (!$client) {
            return;
        }
        $this->setTags();
    }

    /**
     * @return void
     */
    private function setTags(): void
    {
        $flowVersion = FLOW_VERSION_BRANCH;
        if ($this->packageManager) {
            $flowPackage = $this->packageManager->getPackage('Neos.Flow');
            $flowVersion = $flowPackage->getInstalledVersion();
        }

        Hub::getCurrent()->configureScope(static function (Scope $scope) use ($flowVersion): void {
            $scope->setTag('flow_version', $flowVersion);
            $scope->setTag('flow_context', (string)Bootstrap::$staticObjectManager->get(Environment::class)->getContext());
            $scope->setTag('php_version', PHP_VERSION);
        });
    }

    /**
     * @return Options
     */
    public function getOptions(): Options
    {
        if ($client = Hub::getCurrent()->getClient()) {
            return $client->getOptions();
        }
        return new Options();
    }

    /**
     * Capture an exception or error
     *
     * @param Throwable $throwable The exception or error to capture
     * @param array $extraData Additional data passed to the Sentry event
     * @param array $tags
     */
    public function captureThrowable(Throwable $throwable, array $extraData = [], array $tags = []): void
    {
        if (empty($this->dsn)) {
            return;
        }

        if ($throwable instanceof WithReferenceCodeInterface) {
            $extraData['Reference Code'] = $throwable->getReferenceCode();
        }
        $extraData['PHP Process Inode'] = getmyinode();
        $extraData['PHP Process PID'] = getmypid();
        $extraData['PHP Process UID'] = getmyuid();
        $extraData['PHP Process GID'] = getmygid();
        $extraData['PHP Process User'] = get_current_user();

        $tags['exception_code'] = (string)$throwable->getCode();

        $captureException = (!in_array(get_class($throwable), $this->excludeExceptionTypes, true));

        if ($captureException) {
            $this->configureScope($extraData, $tags);
            if ($throwable instanceof Exception && $throwable->getStatusCode() === 404) {
                Hub::getCurrent()->configureScope(static function (Scope $scope): void {
                    $scope->setLevel(Severity::warning());
                });
                $sentryEventId = \Sentry\captureException($throwable);
            } else {
                $sentryEventId = \Sentry\captureException($throwable);
            }
        } else {
            $sentryEventId = 'ignored';
        }

        if ($this->logger) {
            $this->logger->log(
                ($captureException ? LogLevel::CRITICAL : LogLevel::NOTICE),
                sprintf(
                    'Exception %s: %s (Ref: %s | Sentry: %s)',
                    $throwable->getCode(),
                    $throwable->getMessage(),
                    ($throwable instanceof WithReferenceCodeInterface ? $throwable->getReferenceCode() : '-'),
                    $sentryEventId
                )
            );
        }
    }

    /**
     * Capture a message
     *
     * @param string $message The message to capture, for example a log message
     * @param Severity $severity The severity
     * @param array $extraData Additional data passed to the Sentry event
     * @param array $tags
     * @return string|null
     */
    public function captureMessage(string $message, Severity $severity, array $extraData = [], array $tags = []): ?string
    {
        if (empty($this->dsn)) {
            if ($this->logger) {
                $this->logger->warning(sprintf('Sentry: Failed capturing message, because no Sentry DSN was set. Please check your settings.'));
            }
            return null;
        }

        if (preg_match('/Sentry: [0-9a-f]{32}/', $message) === 1) {
            return null;
        }

        $this->configureScope($extraData, $tags);
        $sentryEventId = \Sentry\captureMessage($message, $severity);
        if ($this->logger) {
            $this->logger->log(
                (string)$severity,
                sprintf(
                    '%s (Sentry: %s)',
                    $message,
                    $sentryEventId
                )
            );
        }

        return $sentryEventId;
    }

    /**
     * @param array $extraData
     * @param array $tags
     * @return void
     */
    private function configureScope(array $extraData, array $tags): void
    {
        $securityContext = Bootstrap::$staticObjectManager->get(SecurityContext::class);
        if ($securityContext instanceof SecurityContext && $securityContext->isInitialized()) {
            $userContext = $this->userContextService->getUserContext($securityContext);
        } else {
            $userContext = new UserContext();
        }

        Hub::getCurrent()->configureScope(static function (Scope $scope) use ($userContext, $extraData, $tags): void {
            foreach ($extraData as $extraDataKey => $extraDataValue) {
                $scope->setExtra($extraDataKey, $extraDataValue);
            }
            foreach ($tags as $tagKey => $tagValue) {
                $scope->setTag($tagKey, $tagValue);
            }
            $scope->setUser($userContext->toArray());
            $scope->setLevel(null);
        });
    }
}
