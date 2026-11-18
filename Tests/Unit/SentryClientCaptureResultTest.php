<?php
declare(strict_types=1);

namespace Flownative\Sentry\Tests\Unit;

/*
 * This file is part of the Flownative.Sentry package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flownative\Sentry\SentryClient;
use Neos\Eel\Context;
use Neos\Flow\Core\ApplicationContext;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Utility\Environment;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\ErrorHandler;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/**
 * Runs the real SDK with a transport standing in for the Sentry server.
 */
final class SentryClientCaptureResultTest extends TestCase
{
    private ?ObjectManagerInterface $previousObjectManager;

    /**
     * The SDK registers these once per process, which PHPUnit would flag inside a test.
     */
    public static function setUpBeforeClass(): void
    {
        ErrorHandler::registerOnceErrorHandler();
        ErrorHandler::registerOnceExceptionHandler();
    }

    protected function setUp(): void
    {
        foreach (['FLOW_PATH_ROOT' => '/flow/root/', 'FLOW_VERSION_BRANCH' => 'test'] as $constant => $value) {
            if (!defined($constant)) {
                define($constant, $value);
            }
        }
        $environment = $this->createMock(Environment::class);
        $environment->method('getContext')->willReturn(new ApplicationContext('Testing'));
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnMap([
            [Environment::class, $environment],
            [SecurityContext::class, $this->createMock(SecurityContext::class)],
        ]);
        $this->previousObjectManager = Bootstrap::$staticObjectManager;
        Bootstrap::$staticObjectManager = $objectManager;
    }

    protected function tearDown(): void
    {
        Bootstrap::$staticObjectManager = $this->previousObjectManager;
        SentrySdk::init();
    }

    public function testDeliveredEventIsASuccessWithItsId(): void
    {
        $result = $this->clientWithServerAnswering(true)->captureThrowable(new RuntimeException('Boom'));

        self::assertTrue($result->suceess);
        self::assertNotSame('', $result->eventId);
    }

    public function testEventTheServerDidNotAcceptIsAFailure(): void
    {
        $result = $this->clientWithServerAnswering(false)->captureThrowable(new RuntimeException('Boom'));

        self::assertFalse($result->suceess);
        self::assertSame('', $result->eventId);
    }

    public function testEventSampledAwayIsNoFailure(): void
    {
        $result = $this->clientWithServerAnswering(false, ['sampleRate' => 0.0])->captureThrowable(new RuntimeException('Boom'));

        self::assertTrue($result->suceess);
    }

    public function testSubclassOfAnExcludedTypeIsNoFailure(): void
    {
        $settings = ['capture' => ['excludeExceptionTypes' => [RuntimeException::class => true]]];

        $result = $this->clientWithServerAnswering(false, $settings)->captureThrowable(new class ('Boom') extends RuntimeException {});

        self::assertTrue($result->suceess);
    }

    public function testAnEelArgumentIsCapturedWithoutSecondaryWarnings(): void
    {
        $capturedEvent = null;
        $client = $this->clientWithServerAnswering(true, [], static function (Event $event) use (&$capturedEvent): void {
            $capturedEvent = $event;
        });
        $previousIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
        $warnings = [];
        set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$warnings): never {
            $warnings[] = $message;
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        $throwWithContext = static function (Context $context): never {
            throw new RuntimeException('Original failure', 0, new \LogicException('Previous failure'));
        };


        try {
            try {
                $throwWithContext(new Context(['fixture' => 'value']));
            } catch (RuntimeException $exception) {
                $result = $client->captureThrowable($exception);
            }
        } finally {
            restore_error_handler();
            ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
        }


        self::assertSame([], $warnings);
        self::assertTrue($result->suceess);
        self::assertInstanceOf(Event::class, $capturedEvent);
        $exceptions = $capturedEvent->getExceptions();
        self::assertSame(
            [[RuntimeException::class, 'Original failure'], [\LogicException::class, 'Previous failure']],
            array_map(static fn ($exception): array => [$exception->getType(), $exception->getValue()], $exceptions)
        );
        $arguments = array_merge([], ...array_map(static fn ($frame): array => $frame->getVars(), $exceptions[0]->getStacktrace()->getFrames()));
        $expected = method_exists(Context::class, '__toString')
            ? 'Object ' . Context::class
            : ['__class ' => Context::class];
        self::assertContains($expected, $arguments);
    }

    private function clientWithServerAnswering(bool $accepted, array $settings = [], ?\Closure $onSend = null): SentryClient
    {
        $transport = new class ($accepted, $onSend) implements TransportInterface {
            public function __construct(private readonly bool $accepted, private readonly ?\Closure $onSend)
            {
            }

            public function send(Event $event): Result
            {
                ($this->onSend)?->__invoke($event);
                return $this->accepted ? new Result(ResultStatus::success(), $event) : new Result(ResultStatus::failed());
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };
        $client = new SentryClient($settings + [
            'dsn' => 'http://key@127.0.0.1/1',
            'environment' => 'testing',
            'release' => 'test',
            'sdkOptions' => ['transport' => $transport],
        ]);
        $client->initializeObject();
        return $client;
    }
}
