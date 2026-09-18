<?php
declare(strict_types=1);

namespace Flownative\Sentry\Tests\Unit;

use Flownative\Sentry\Context\UserContext;
use Flownative\Sentry\Context\UserContextServiceInterface;
use Flownative\Sentry\Scrubbing\ValuePatternScrubber;
use Flownative\Sentry\SentryClient;
use Neos\Flow\Core\ApplicationContext;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Utility\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\CheckInStatus;
use Sentry\Event;
use Sentry\Frame;
use Sentry\SentrySdk;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Severity;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Sentry\UserDataBag;

/**
 * Initializes the real SentryClient against the installed SDK and inspects
 * what reaches its transport, so the privacy settings are verified on the
 * final event of every capture path: the package's own capture methods as
 * well as direct SDK captures (listeners, foreign code, transactions).
 */
final class SentryClientTest extends TestCase
{
    private const FOREIGN_USER = [
        'id' => 'foreign-42',
        'username' => 'foreign-user',
        'email' => 'foreign@example.com',
        'ip_address' => '203.0.113.9',
        'name' => 'Foreign Name',
    ];

    private const SECRET_ARGUMENT = 'secret-argument-value';

    /** @var Event[] */
    private array $sentEvents = [];

    private mixed $previousObjectManager;

    public static function setUpBeforeClass(): void
    {
        // Defined by the Flow bootstrap in a real application; not a prefix of paths used by other tests
        defined('FLOW_PATH_ROOT') || define('FLOW_PATH_ROOT', '/flownative-sentry-test-root/');
        defined('FLOW_VERSION_BRANCH') || define('FLOW_VERSION_BRANCH', '9.0');
    }

    protected function setUp(): void
    {
        $this->previousObjectManager = Bootstrap::$staticObjectManager;

        $environment = new Environment(new ApplicationContext('Testing'));
        $securityContext = $this->createMock(SecurityContext::class);
        $securityContext->method('isInitialized')->willReturn(true);
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(static fn (string $objectName) => match ($objectName) {
            Environment::class => $environment,
            SecurityContext::class => $securityContext,
        });
        Bootstrap::$staticObjectManager = $objectManager;
    }

    protected function tearDown(): void
    {
        Bootstrap::$staticObjectManager = $this->previousObjectManager;
        SentrySdk::setCurrentHub(new Hub());
    }

    public function testClientInitializesAndRoutesCheckInsThroughTheScrubberChain(): void
    {
        $this->initializeClient(['scrubbers' => [
            'patterns' => ['className' => ValuePatternScrubber::class, 'options' => ['patterns' => ['email']]],
        ]]);

        \Sentry\captureCheckIn('visitor@example.com', CheckInStatus::ok());

        self::assertCount(1, $this->sentEvents);
        self::assertSame('[Filtered]', $this->sentEvents[0]->getCheckIn()?->getMonitorSlug());
    }

    public static function restrictiveUserContextCaptures(): \Generator
    {
        $capturePaths = [
            'wrapper throwable' => ['wrapper', static fn (SentryClient $client) => $client->captureThrowable(new \RuntimeException('boom'))],
            'wrapper message' => ['wrapper', static fn (SentryClient $client) => $client->captureMessage('boom', Severity::error())],
            'SDK exception' => ['sdk', static fn () => SentrySdk::getCurrentHub()->captureException(new \RuntimeException('boom'))],
            'SDK message' => ['sdk', static fn () => SentrySdk::getCurrentHub()->captureMessage('boom')],
            'SDK transaction' => ['sdk', static fn () => SentrySdk::getCurrentHub()->startTransaction(new TransactionContext('GET /newsletter'))->finish()],
        ];
        // The package's own captures carry the Flow user, direct SDK captures whatever user the scope holds
        $expectedUsernames = [
            'username' => ['wrapper' => 'flow-editor', 'sdk' => 'foreign-user'],
            'sha1' => ['wrapper' => sha1('flow-editor'), 'sdk' => sha1('foreign-user')],
            'none' => ['wrapper' => null, 'sdk' => null],
        ];
        foreach ($expectedUsernames as $mode => $usernameByOrigin) {
            foreach ($capturePaths as $pathName => [$origin, $capture]) {
                yield $mode . ' via ' . $pathName => [$mode, $capture, $usernameByOrigin[$origin]];
            }
        }
    }

    #[DataProvider('restrictiveUserContextCaptures')]
    public function testRestrictiveUserContextReplacesInheritedUserOnTheFinalEvent(string $mode, \Closure $capture, ?string $expectedUsername): void
    {
        $client = $this->initializeClient(['userContext' => $mode]);
        self::setScopeUser(self::FOREIGN_USER);

        $capture($client);

        self::assertCount(1, $this->sentEvents);
        $transmittedUser = $this->transmittedPayload($this->sentEvents[0])['user'] ?? null;
        if ($expectedUsername === null) {
            self::assertNull($transmittedUser);
        } else {
            self::assertSame(['username' => $expectedUsername], array_filter($transmittedUser ?? [], static fn ($value) => $value !== null));
        }

        // The mode applies to outgoing events only; the scope stays intact for foreign code
        self::assertSame(self::FOREIGN_USER['email'], $this->currentScopeUser()?->getEmail());
    }

    public static function usernameBasedUserContextModes(): array
    {
        return ['username' => ['username'], 'sha1' => ['sha1']];
    }

    #[DataProvider('usernameBasedUserContextModes')]
    public function testUsernameBasedUserContextSendsNoUserWithoutUsername(string $mode): void
    {
        $client = $this->initializeClient(['userContext' => $mode], new UserContext());
        self::setScopeUser(array_diff_key(self::FOREIGN_USER, ['username' => true]));

        $client->captureThrowable(new \RuntimeException('boom'));
        SentrySdk::getCurrentHub()->captureMessage('boom');

        self::assertCount(2, $this->sentEvents);
        foreach ($this->sentEvents as $sentEvent) {
            self::assertArrayNotHasKey('user', $this->transmittedPayload($sentEvent));
        }
    }

    public function testServiceUserContextKeepsItsPreviousBehavior(): void
    {
        $client = $this->initializeClient(['userContext' => 'service']);
        self::setScopeUser(self::FOREIGN_USER);

        $client->captureThrowable(new \RuntimeException('boom'));
        SentrySdk::getCurrentHub()->captureMessage('boom');

        self::assertCount(2, $this->sentEvents);
        $wrapperUser = $this->transmittedPayload($this->sentEvents[0])['user'];
        self::assertSame(['flow-7', 'flow-editor', 'flow-editor@example.com'], [$wrapperUser['id'], $wrapperUser['username'], $wrapperUser['email']]);
        self::assertSame(self::FOREIGN_USER['email'], $this->transmittedPayload($this->sentEvents[1])['user']['email']);
    }

    public static function stacktraceCapturePaths(): array
    {
        return [
            'wrapper throwable' => [static fn (SentryClient $client) => $client->captureThrowable(self::exceptionThrownWithArgument(self::SECRET_ARGUMENT))],
            'wrapper message' => [static fn (SentryClient $client) => self::callWithArgument(static fn () => $client->captureMessage('boom', Severity::error()), self::SECRET_ARGUMENT)],
            'SDK exception' => [static fn () => SentrySdk::getCurrentHub()->captureException(self::exceptionThrownWithArgument(self::SECRET_ARGUMENT))],
            'SDK message' => [static fn () => self::callWithArgument(static fn () => SentrySdk::getCurrentHub()->captureMessage('boom'), self::SECRET_ARGUMENT)],
        ];
    }

    #[DataProvider('stacktraceCapturePaths')]
    public function testStacktraceVariablesAreCapturedByDefault(\Closure $capture): void
    {
        $client = $this->initializeClient();

        $capture($client);

        self::assertCount(1, $this->sentEvents);
        $frameVars = self::transmittedFrameVars($this->transmittedPayload($this->sentEvents[0]));
        self::assertStringContainsString(self::SECRET_ARGUMENT, json_encode($frameVars, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('stacktraceCapturePaths')]
    public function testDisabledStacktraceVariablesAreRemovedFromTheFinalEvent(\Closure $capture): void
    {
        $client = $this->initializeClient(['captureStacktraceVariables' => false]);

        $capture($client);

        self::assertCount(1, $this->sentEvents);
        self::assertSame([], self::transmittedFrameVars($this->transmittedPayload($this->sentEvents[0])));
    }

    public function testRemovingStacktraceVariablesKeepsTheRemainingFrameData(): void
    {
        $client = $this->initializeClient(['captureStacktraceVariables' => false]);

        SentrySdk::getCurrentHub()->captureException(self::exceptionThrownWithArgument(self::SECRET_ARGUMENT));

        self::assertCount(1, $this->sentEvents);
        $frame = self::findFrame($this->sentEvents[0], '::throwWithArgument');
        self::assertSame([], $frame->getVars());
        self::assertSame(__FILE__, $frame->getAbsoluteFilePath());
        self::assertGreaterThan(0, $frame->getLine());
        self::assertNotNull($frame->getContextLine());
    }

    public function testRedactedSpanKeysDoNotSurviveInTheTransactionPayload(): void
    {
        $this->initializeClient(['scrubbers' => [
            'patterns' => ['className' => ValuePatternScrubber::class, 'options' => ['patterns' => ['email']]],
        ]]);

        $transaction = SentrySdk::getCurrentHub()->startTransaction(new TransactionContext('GET /newsletter'));
        $spanContext = new SpanContext();
        $spanContext->setOp('db.query');
        $spanContext->setDescription('SELECT subscriber');
        $span = $transaction->startChild($spanContext);
        $span->setTags(['visitor@example.com' => 'subscriber', 'db.system' => 'mysql']);
        $span->setData(['visitor@example.com' => ['subscribed' => true], 'db.rows' => 3]);
        $span->setStatus(SpanStatus::ok());
        $span->finish();
        $transaction->finish();

        self::assertCount(1, $this->sentEvents);
        $sentEvent = $this->sentEvents[0];
        self::assertStringNotContainsString('visitor@example.com', json_encode($this->transmittedPayload($sentEvent), JSON_THROW_ON_ERROR));
        self::assertCount(1, $sentEvent->getSpans());
        $sentSpan = $sentEvent->getSpans()[0];
        self::assertSame(['[Filtered]' => 'subscriber', 'db.system' => 'mysql'], $sentSpan->getTags());
        self::assertSame(['[Filtered]' => ['subscribed' => true], 'db.rows' => 3], $sentSpan->getData());
        self::assertSame((string)$span->getSpanId(), (string)$sentSpan->getSpanId());
        self::assertSame((string)$transaction->getTraceId(), (string)$sentSpan->getTraceId());
        self::assertSame((string)$transaction->getSpanId(), (string)$sentSpan->getParentSpanId());
        self::assertSame('db.query', $sentSpan->getOp());
        self::assertSame('SELECT subscriber', $sentSpan->getDescription());
        self::assertSame((string)SpanStatus::ok(), (string)$sentSpan->getStatus());
        self::assertSame($span->getStartTimestamp(), $sentSpan->getStartTimestamp());
        self::assertSame($span->getEndTimestamp(), $sentSpan->getEndTimestamp());
    }

    private function initializeClient(array $settings = [], ?UserContext $userContext = null): SentryClient
    {
        $transport = new class($this->sentEvents) implements TransportInterface {
            public function __construct(private array &$sentEvents)
            {
            }

            public function send(Event $event): Result
            {
                $this->sentEvents[] = $event;
                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };

        $client = new SentryClient(array_replace([
            'dsn' => 'https://public@example.invalid/1',
            'environment' => 'testing',
            'release' => 'test',
            'tracesSampleRate' => 1.0,
            'sdkOptions' => ['transport' => $transport],
        ], $settings));

        if ($userContext === null) {
            $userContext = new UserContext();
            $userContext->setId('flow-7');
            $userContext->setUsername('flow-editor');
            $userContext->setEmail('flow-editor@example.com');
        }
        $userContextService = $this->createMock(UserContextServiceInterface::class);
        $userContextService->method('getUserContext')->willReturn($userContext);
        (new \ReflectionProperty(SentryClient::class, 'userContextService'))->setValue($client, $userContextService);

        $client->initializeObject();
        return $client;
    }

    /**
     * The event item of the envelope the HTTP transport would send.
     */
    private function transmittedPayload(Event $event): array
    {
        $envelope = (new PayloadSerializer(SentrySdk::getCurrentHub()->getClient()->getOptions()))->serialize($event);
        return json_decode(explode("\n", $envelope)[2], true, 512, JSON_THROW_ON_ERROR);
    }

    private static function transmittedFrameVars(array $payload): array
    {
        $frames = $payload['stacktrace']['frames'] ?? [];
        foreach ($payload['exception']['values'] ?? [] as $exception) {
            array_push($frames, ...($exception['stacktrace']['frames'] ?? []));
        }
        return array_column($frames, 'vars');
    }

    private static function setScopeUser(array $user): void
    {
        SentrySdk::getCurrentHub()->configureScope(static function (Scope $scope) use ($user): void {
            $scope->setUser($user);
        });
    }

    private function currentScopeUser(): ?UserDataBag
    {
        $user = null;
        SentrySdk::getCurrentHub()->configureScope(static function (Scope $scope) use (&$user): void {
            $user = $scope->getUser();
        });
        return $user;
    }

    private static function findFrame(Event $event, string $functionNameSuffix): Frame
    {
        $stacktraces = [$event->getStacktrace()];
        foreach ($event->getExceptions() as $exceptionDataBag) {
            $stacktraces[] = $exceptionDataBag->getStacktrace();
        }
        foreach ($stacktraces as $stacktrace) {
            foreach ($stacktrace?->getFrames() ?? [] as $frame) {
                if (str_ends_with((string)$frame->getFunctionName(), $functionNameSuffix)) {
                    return $frame;
                }
            }
        }
        self::fail(sprintf('No frame of a function ending with "%s" found', $functionNameSuffix));
    }

    private static function exceptionThrownWithArgument(string $argument): \RuntimeException
    {
        // Production php.ini files strip arguments from exception traces
        $previousSetting = ini_set('zend.exception_ignore_args', '0');
        try {
            self::throwWithArgument($argument);
        } catch (\RuntimeException $exception) {
            return $exception;
        } finally {
            ini_set('zend.exception_ignore_args', (string)$previousSetting);
        }
    }

    private static function throwWithArgument(string $argument): never
    {
        throw new \RuntimeException('boom');
    }

    private static function callWithArgument(\Closure $callback, string $argument): void
    {
        $callback();
    }
}
