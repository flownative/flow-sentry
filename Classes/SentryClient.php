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
use Flownative\Sentry\Context\WithExtraDataInterface;
use Flownative\Sentry\Log\CaptureResult;
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
use Neos\Utility\Arrays;
use Psr\Log\LoggerInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\ExceptionDataBag;
use Sentry\ExceptionMechanism;
use Sentry\Frame;
use Sentry\Options;
use Sentry\SentrySdk;
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
    protected float $sampleRate;
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
        $this->dsn = $settings['dsn'] ?: '';
        $this->environment = $settings['environment'] ?: '';
        $this->release = $settings['release'] ?: '';
        $this->sampleRate = (float)($settings['sampleRate'] ?? 1);
        $this->excludeExceptionTypes = $settings['capture']['excludeExceptionTypes'] ?? [];
    }

    public function initializeObject(): void
    {
        if (empty($this->dsn)) {
            return;
        }

        $representationSerializer = new RepresentationSerializer(
            new Options([])
        );
        $representationSerializer->setSerializeAllObjects(true);
        $this->stacktraceBuilder = new StacktraceBuilder(
            new Options([]),
            $representationSerializer
        );

        \Sentry\init([
            'dsn' => $this->dsn,
            'environment' => $this->environment,
            'release' => $this->release,
            'sample_rate' => $this->sampleRate,
            'in_app_exclude' => [
                FLOW_PATH_ROOT . '/Packages/Application/Flownative.Sentry/Classes/',
                FLOW_PATH_ROOT . '/Packages/Framework/Neos.Flow/Classes/Aop/',
                FLOW_PATH_ROOT . '/Packages/Framework/Neos.Flow/Classes/Error/',
                FLOW_PATH_ROOT . '/Packages/Framework/Neos.Flow/Classes/Log/',
                FLOW_PATH_ROOT . '/Packages/Libraries/neos/flow-log/',
            ],
            'attach_stacktrace' => true,
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
            } catch (UnknownPackageException) {
            }
        }
        if (empty($flowVersion)) {
            $flowVersion = FLOW_VERSION_BRANCH;
        }

        $currentSession = $this->sessionManager?->getCurrentSession();

        SentrySdk::getCurrentHub()->configureScope(static function (Scope $scope) use ($flowVersion, $currentSession): void {
            $scope->setTag('flow_version', $flowVersion);
            $scope->setTag('flow_context', (string)Bootstrap::$staticObjectManager->get(Environment::class)->getContext());

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

    public function captureThrowable(Throwable $throwable, array $extraData = [], array $tags = []): CaptureResult
    {
        if (empty($this->dsn)) {
            return new CaptureResult(
                false,
                'Failed capturing throwable, because no Sentry DSN was set. Please check your settings.',
                ''
            );
        }

        if ($this->excludeException($throwable)) {
            return new CaptureResult(
                true,
                'Skipped capturing throwable, it is in excludeExceptionTypes',
                ''
            );
        }

        if ($throwable instanceof WithReferenceCodeInterface) {
            $extraData['Reference Code'] = $throwable->getReferenceCode();
        }
        if ($throwable instanceof WithExtraDataInterface) {
            $extraData = Arrays::arrayMergeRecursiveOverrule($extraData, $throwable->getExtraData());
        }

        $extraData['PHP Process Inode'] = getmyinode();
        $extraData['PHP Process PID'] = getmypid();
        $extraData['PHP Process UID'] = getmyuid();
        $extraData['PHP Process GID'] = getmygid();
        $extraData['PHP Process User'] = get_current_user();

        $tags['exception_code'] = (string)$throwable->getCode();

        $this->setTags();
        $this->configureScope($extraData, $tags);
        $event = Event::createEvent();
        $this->addThrowableToEvent($throwable, $event);
        $sentryEventId = SentrySdk::getCurrentHub()->captureEvent($event);

        return new CaptureResult(
            true,
            '',
            (string)$sentryEventId
        );
    }

    public function captureMessage(string $message, Severity $severity, array $extraData = [], array $tags = []): CaptureResult
    {
        if (empty($this->dsn)) {
            return new CaptureResult(
                false,
                'Failed capturing message, because no Sentry DSN was set. Please check your settings.',
                ''
            );
        }

        $this->setTags();
        $this->configureScope($extraData, $tags);
        $eventHint = EventHint::fromArray([
            'stacktrace' => $this->prepareStacktrace(),
        ]);
        $sentryEventId = SentrySdk::getCurrentHub()->captureMessage($message, $severity, $eventHint);

        return new CaptureResult(
            true,
            $message,
            (string)$sentryEventId
        );
    }

    private function excludeException(\Throwable $throwable): bool
    {
        $excludedExceptions = array_keys(array_filter($this->excludeExceptionTypes));
        return in_array(get_class($throwable), $excludedExceptions, true);
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
        });
    }

    private function renderCleanPathAndFilename(string $rawPathAndFilename): string
    {
        if (preg_match('#Flow_Object_Classes/\w+.php$#', $rawPathAndFilename) !== 1) {
            return $rawPathAndFilename;
        }
        if (!file_exists($rawPathAndFilename)) {
            return $rawPathAndFilename;
        }
        $classProxyFile = file_get_contents($rawPathAndFilename);
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
                !str_contains($classPathAndFilename, 'Packages/Framework/')
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
