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
use GuzzleHttp\Psr7\ServerRequest;
use Jenssegers\Agent\Agent;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Error\WithReferenceCodeInterface;
use Neos\Flow\Exception;
use Neos\Flow\Package\Exception\UnknownPackageException;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Session\Session;
use Neos\Flow\Session\SessionManagerInterface;
use Neos\Flow\Utility\Environment;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\ExceptionDataBag;
use Sentry\ExceptionMechanism;
use Sentry\Frame;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Severity;
use Sentry\Stacktrace;
use Sentry\StacktraceBuilder;
use Sentry\State\Scope;
use Throwable;

/**
 * @Flow\Scope("singleton")
 */
class SentryClient
{
    protected string $dsn;
    protected string $environment;
    protected string $release;
    protected array $excludeExceptionTypes = [];
    protected StacktraceBuilder $stacktraceBuilder;

    /**
     * @Flow\Inject
     * @var UserContextServiceInterface
     */
    protected $userContextService;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject(lazy=false)
     * @var SessionManagerInterface
     */
    protected $sessionManager;

    /**
     * @Flow\Inject
     * @var PackageManager
     */
    protected $packageManager;

    public function injectSettings(array $settings): void
    {
        $this->dsn = $settings['dsn'] ?? '';
        $this->environment = $settings['environment'] ?? '';
        $this->release = $settings['release'] ?? '';
        $this->excludeExceptionTypes = $settings['capture']['excludeExceptionTypes'] ?? [];
    }

    public function initializeObject(): void
    {
        $this->stacktraceBuilder = new StacktraceBuilder(
            new Options([]),
            new RepresentationSerializer(
                new Options([])
            )
        );

        if (empty($this->dsn)) {
            return;
        }

        \Sentry\init([
            'dsn' => $this->dsn,
            'environment' => $this->environment,
            'release' => $this->release,
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

        $client = SentrySdk::getCurrentHub()->getClient();
        if (!$client) {
            return;
        }
        $this->setTags();
    }

    private function setTags(): void
    {
        $flowVersion = '';
        if ($this->packageManager) {
            try {
                $flowPackage = $this->packageManager->getPackage('Neos.Flow');
                $flowVersion = $flowPackage->getInstalledVersion();
            } catch (UnknownPackageException $e) {
            }
        }
        if (empty($flowVersion)) {
            $flowVersion = FLOW_VERSION_BRANCH;
        }

        $currentSession = null;
        if ($this->sessionManager) {
            $currentSession = $this->sessionManager->getCurrentSession();
        }

        SentrySdk::getCurrentHub()->configureScope(static function (Scope $scope) use ($flowVersion, $currentSession): void {
            $scope->setTag('flow_version', $flowVersion);
            $scope->setTag('flow_context', (string)Bootstrap::$staticObjectManager->get(Environment::class)->getContext());
            $scope->setTag('php_version', PHP_VERSION);

            if (PHP_SAPI !== 'cli') {
                $scope->setTag('uri',
                    (string)ServerRequest::fromGlobals()->getUri());

                $agent = new Agent();
                $scope->setContext('client_os', [
                    'name' => $agent->platform(),
                    'version' => $agent->version($agent->platform())
                ]);

                $scope->setContext('client_browser', [
                    'name' => $agent->browser(),
                    'version' => $agent->version($agent->browser())
                ]);
            }

            if ($currentSession instanceof Session && $currentSession->isStarted()) {
                $scope->setTag('flow_session_sha1', sha1($currentSession->getId()));
            }
        });
    }

    public function getOptions(): Options
    {
        if ($client = SentrySdk::getCurrentHub()->getClient()) {
            return $client->getOptions();
        }
        return new Options();
    }

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
                SentrySdk::getCurrentHub()->configureScope(static function (Scope $scope): void {
                    $scope->setLevel(Severity::warning());
                });
            }
            $event = Event::createEvent();
            $this->addThrowableToEvent($throwable, $event);
            $sentryEventId = SentrySdk::getCurrentHub()->captureEvent($event);
        } else {
            $sentryEventId = 'ignored';
        }
        if ($this->logger) {
            $this->logger->log(
                ($captureException ? LogLevel::CRITICAL : LogLevel::NOTICE),
                sprintf(
                    'Exception #%s: %s (Ref: %s | Sentry: %s)',
                    $throwable->getCode(),
                    $throwable->getMessage(),
                    ($throwable instanceof WithReferenceCodeInterface ? $throwable->getReferenceCode() : '-'),
                    $sentryEventId
                )
            );
        }
    }

    public function captureMessage(string $message, Severity $severity, array $extraData = [], array $tags = []): ?EventId
    {
        if (empty($this->dsn)) {
            if ($this->logger) {
                $this->logger->warning('Sentry: Failed capturing message, because no Sentry DSN was set. Please check your settings.');
            }
            return null;
        }

        if (preg_match('/Sentry: [0-9a-f]{32}/', $message) === 1) {
            return null;
        }

        $this->configureScope($extraData, $tags);
        $eventHint = EventHint::fromArray([
            'stacktrace' => $this->prepareStacktrace()
        ]);
        $sentryEventId = \Sentry\captureMessage($message, $severity, $eventHint);

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

    private function configureScope(array $extraData, array $tags): void
    {
        $securityContext = Bootstrap::$staticObjectManager->get(SecurityContext::class);
        if ($securityContext instanceof SecurityContext && $securityContext->isInitialized()) {
            $userContext = $this->userContextService->getUserContext($securityContext);
        } else {
            $userContext = new UserContext();
        }

        SentrySdk::getCurrentHub()->configureScope(static function (Scope $scope) use ($userContext, $extraData, $tags): void {
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

    private function renderCleanPathAndFilename(string $rawPathAndFilename): string
    {
        if (preg_match('#Flow_Object_Classes/\w+.php$#', $rawPathAndFilename) !== 1) {
            return $rawPathAndFilename;
        }
        $absolutePathAndFilename = FLOW_PATH_ROOT . trim($rawPathAndFilename, '/');
        if (!file_exists($absolutePathAndFilename)) {
            return $rawPathAndFilename;
        }
        $classProxyFile = file_get_contents($absolutePathAndFilename);
        if ($classProxyFile === false) {
            return $rawPathAndFilename;
        }
        if (preg_match('@# PathAndFilename: ([/\w.]+\.php)@', $classProxyFile, $matches) !== 1) {
            return $rawPathAndFilename;
        }
        return str_replace(['_', FLOW_PATH_ROOT], '/', $matches[1]);
    }

    private function prepareStacktrace(\Throwable $throwable = null): Stacktrace
    {
        if ($throwable) {
            $stacktrace = $this->stacktraceBuilder->buildFromException($throwable);
        } else {
            $stacktrace = $this->stacktraceBuilder->buildFromBacktrace(
                debug_backtrace(0),
                __FILE__,
                __LINE__ - 3
            );
        }

        $frames = [];
        foreach ($stacktrace->getFrames() as $frame) {
            $classPathAndFilename = $this->renderCleanPathAndFilename($frame->getFile());
            $frames [] = new Frame(
                str_replace('_Original::', '::', (string)$frame->getFunctionName()),
                $classPathAndFilename,
                $frame->getLine(),
                $frame->getRawFunctionName(),
                $frame->getAbsoluteFilePath(),
                $frame->getVars(),
                strpos($classPathAndFilename, 'Packages/Framework/') === false
            );
        }
        return new Stacktrace($frames);
    }

    private function addThrowableToEvent(Throwable $throwable, Event $event): void
    {
        if ($throwable instanceof \ErrorException && null === $event->getLevel()) {
            $event->setLevel(Severity::fromError($throwable->getSeverity()));
        }

        $exceptions = [];
        do {
            $exceptions[] = new ExceptionDataBag(
                $throwable,
                $this->prepareStacktrace($throwable),
                new ExceptionMechanism(ExceptionMechanism::TYPE_GENERIC, true)
            );
        } while ($throwable = $throwable->getPrevious());

        $event->setExceptions($exceptions);

    }
}
