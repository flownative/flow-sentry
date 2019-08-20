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
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Utility\Environment;
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
     * @var UserContextServiceInterface
     */
    protected $userContextService;

    /**
     * @var bool
     */
    protected $temporarilyIgnoreNewMessages = false;

    /**
     * @param UserContextServiceInterface $userContextService
     */
    public function injectUserContextService(UserContextServiceInterface $userContextService): void
    {
        $this->userContextService = $userContextService;
    }

    /**
     * @param array $settings
     */
    public function injectSettings(array $settings): void
    {
        $this->dsn = $settings['dsn'] ?? '';
        $this->environment = $settings['environment'] ?? '';
        $this->release = $settings['release'] ?? '';
    }

    /**
     * Initialize the Sentry client and fatal error handler (shutdown function)
     */
    public function initializeObject(): void
    {
        if (empty($this->dsn)) {
            return;
        }
        \Sentry\init(['dsn' => $this->dsn]);

        $client = Hub::getCurrent()->getClient();
        if (!$client) {
            return;
        }

        $options = $client->getOptions();
        $options->setEnvironment($this->environment);
        $options->setRelease($this->release);
        $options->setProjectRoot(FLOW_PATH_ROOT);
        $options->setPrefixes([FLOW_PATH_ROOT]);
        $options->setInAppExcludedPaths([
            FLOW_PATH_ROOT . '/Packages/Application/Flownative.Sentry/Classes/',
            FLOW_PATH_ROOT . '/Packages/Framework/Neos.Flow/Classes/Aop/',
            FLOW_PATH_ROOT . '/Packages/Framework/Neos.Flow/Classes/Error/',
            FLOW_PATH_ROOT . '/Packages/Framework/Neos.Flow/Classes/Log/',
            FLOW_PATH_ROOT . '/Packages/Libraries/neos/flow-log/'
        ]);
        $options->setAttachStacktrace(true);

        $this->setTags();

        $this->emitSentryClientCreated();
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

        // Ignore messages which might be captured by a logger which tries to log the exception we
        // are currently handling:
        $this->temporarilyIgnoreNewMessages = true;

        if ($throwable instanceof WithReferenceCodeInterface) {
            $extraData['referenceCode'] = $throwable->getReferenceCode();
        }
        $tags['code'] = (string)$throwable->getCode();
        $tags['unlogisch'] = true;

        $this->configureScope($extraData, $tags);

        \Sentry\captureException($throwable);

        $this->temporarilyIgnoreNewMessages = false;
    }

    /**
     * Capture a message
     *
     * @param string $message The message to capture, for example a log message
     * @param Severity $severity The severity
     * @param array $extraData Additional data passed to the Sentry event
     */
    public function captureMessage(string $message, Severity $severity, array $extraData = []): void
    {
        return;
        if (empty($this->dsn) || $this->temporarilyIgnoreNewMessages) {
            return;
        }
        $this->configureScope($extraData, []);
        \Sentry\captureMessage($message, $severity);
    }

    /**
     * Set tags fore the Sentry context
     * @return void
     */
    private function setTags(): void
    {
        $hub = Hub::getCurrent();
        $hub->configureScope(static function (Scope $scope): void {
            $scope->setTag('flow_context', (string)Bootstrap::$staticObjectManager->get(Environment::class)->getContext());
            $scope->setTag('flow_version', FLOW_VERSION_BRANCH);
            $scope->setTag('php_version', PHP_VERSION);
            $scope->setTag('foo', 'bar');
        });

    }

    /**
     * @param array $extraData
     * @param array $tags
     * @return void
     */
    private function configureScope(array $extraData, array $tags): void
    {
        $objectManager = Bootstrap::$staticObjectManager;
        $securityContext = $objectManager->get(SecurityContext::class);
        if ($securityContext instanceof SecurityContext && $securityContext->isInitialized()) {
            $userContext = $this->userContextService->getUserContext($securityContext);
        } else {
            $userContext = new UserContext();
        }

        $userContext->setEmail('foo@example.com');

        Hub::getCurrent()->configureScope(static function (Scope $scope) use ($userContext, $extraData, $tags): void {
            $scope->setUser($userContext->toArray());
            foreach ($extraData as $extraDataKey => $extraDataValue) {
                $scope->setExtra($extraDataKey, $extraDataValue);
            }
            foreach ($tags as $tagKey => $tagValue) {
                $scope->setTag($tagKey, $tagValue);
            }
        });
    }

    /**
     * @Flow\Signal
     * @return void
     */
    public function emitSentryClientCreated()
    {
    }
}
