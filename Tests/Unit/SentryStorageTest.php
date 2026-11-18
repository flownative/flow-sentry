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

use Flownative\Sentry\Log\CaptureResult;
use Flownative\Sentry\Log\SentryStorage;
use Flownative\Sentry\SentryClient;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Utility\Files;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SentryStorageTest extends TestCase
{
    private string $storagePath;
    private ?ObjectManagerInterface $previousObjectManager;

    public static function setUpBeforeClass(): void
    {
        if (!defined('FLOW_PATH_DATA')) {
            define('FLOW_PATH_DATA', sys_get_temp_dir() . '/flownative-sentry-test-data-' . getmypid() . '/');
        }
    }

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/' . uniqid('flownative-sentry-test-', true);
        mkdir($this->storagePath);
        $this->previousObjectManager = Bootstrap::$staticObjectManager;
    }

    protected function tearDown(): void
    {
        Bootstrap::$staticObjectManager = $this->previousObjectManager;
        chmod($this->storagePath, 0755);
        Files::removeDirectoryRecursively($this->storagePath);
    }

    public function testOptionOffAndClientPresentWritesNoFile(): void
    {
        $message = $this->storage(['writeDumpFiles' => false], $this->clientAnswering(true, '', 'abc'))->logThrowable(new RuntimeException('Boom'));

        self::assertSame([], $this->dumpFiles());
        self::assertStringContainsString('Boom', $message);
        self::assertStringContainsString('(Sentry: #abc – ok)', $message);
        self::assertStringNotContainsString('See also', $message);
    }

    public function testOptionOnWritesAndCaptures(): void
    {
        $client = $this->clientAnswering(true, '', 'abc');
        $client->expects(self::once())->method('captureThrowable');

        $message = $this->storage(['writeDumpFiles' => true], $client)->logThrowable(new RuntimeException('Boom'));

        self::assertCount(1, $this->dumpFiles());
        self::assertStringContainsString('See also: ' . basename($this->dumpFiles()[0]), $message);
        self::assertStringContainsString('(Sentry: #abc – ok)', $message);
    }

    public function testDumpIsRenderedByTheRenderersPassedToTheStorage(): void
    {
        $storage = $this->storage(['writeDumpFiles' => true], $this->clientAnswering(true, '', 'abc'));
        $storage->setBacktraceRenderer(static fn () => 'THE BACKTRACE');
        $storage->setRequestInformationRenderer(static fn () => 'THE REQUEST');

        $storage->logThrowable(new RuntimeException('Boom'));

        $dump = file_get_contents($this->dumpFiles()[0]);
        self::assertStringContainsString('THE BACKTRACE', $dump);
        self::assertStringContainsString('THE REQUEST', $dump);
    }

    public function testNoClientWritesAFile(): void
    {
        $message = $this->storage([], null)->logThrowable(new RuntimeException('Boom'));

        self::assertCount(1, $this->dumpFiles());
        self::assertStringContainsString('(Sentry: no client available)', $message);
    }

    public function testResultSayingItFailedWritesAFile(): void
    {
        $message = $this->storage([], $this->clientAnswering(false, 'No DSN', ''))->logThrowable(new RuntimeException('Boom'));

        self::assertCount(1, $this->dumpFiles());
        self::assertStringContainsString('(Sentry: no ID – No DSN)', $message);
    }

    public function testCaptureThrowingAnExceptionWritesAFile(): void
    {
        $client = $this->createMock(SentryClient::class);
        $client->method('captureThrowable')->willThrowException(new RuntimeException('Sentry is broken'));

        $message = $this->storage([], $client)->logThrowable(new RuntimeException('Boom'));

        self::assertCount(1, $this->dumpFiles());
        self::assertStringContainsString('Boom', $message);
        self::assertStringContainsString('Sentry is broken', $message);
    }

    public function testExcludedExceptionWritesNoFile(): void
    {
        $message = $this->storage([], $this->clientAnswering(true, 'Skipped, excluded by configuration.', ''))->logThrowable(new RuntimeException('Boom'));

        self::assertSame([], $this->dumpFiles());
        self::assertStringContainsString('Skipped, excluded by configuration.', $message);
    }

    public static function unwritableStoragePaths(): array
    {
        return [
            'a directory without write permission' => [static function (string $storagePath): string {
                chmod($storagePath, 0555);
                return $storagePath;
            }],
            'a path below a regular file' => [static function (string $storagePath): string {
                touch($storagePath . '/file');
                return $storagePath . '/file/Exceptions';
            }],
        ];
    }

    #[DataProvider('unwritableStoragePaths')]
    public function testUnwritablePathNeitherThrowsNorLosesTheSentryReport(callable $prepareStoragePath): void
    {
        $unwritablePath = $prepareStoragePath($this->storagePath);
        $client = $this->clientAnswering(true, '', 'abc');
        $client->expects(self::once())->method('captureThrowable');
        $this->useClient($client);

        $message = SentryStorage::createWithOptions(['writeDumpFiles' => true, 'storagePath' => $unwritablePath])->logThrowable(new RuntimeException('Boom'));

        self::assertStringContainsString('Boom', $message);
        self::assertStringContainsString('(Sentry: #abc – ok)', $message);
    }

    public function testUnwritablePathKeepsTheThrowableInTheMessageWhenCapturingFailed(): void
    {
        chmod($this->storagePath, 0555);

        $message = $this->storage([], null)->logThrowable(new RuntimeException('Boom'));

        self::assertStringContainsString('Boom', $message);
        self::assertStringContainsString('Could not write', $message);
    }

    public function testCreateWithoutOptionsWritesNoFileByDefault(): void
    {
        $this->useClient($this->clientAnswering(true, '', 'abc'));

        $message = SentryStorage::createWithOptions([])->logThrowable(new RuntimeException('Boom'));

        self::assertDirectoryDoesNotExist(FLOW_PATH_DATA . 'Logs/Exceptions');
        self::assertStringNotContainsString('See also', $message);
    }

    private function storage(array $options, ?SentryClient $client): ThrowableStorageInterface
    {
        $this->useClient($client);
        $storage = SentryStorage::createWithOptions($options + ['storagePath' => $this->storagePath]);
        $storage->setBacktraceRenderer(static fn () => '');
        $storage->setRequestInformationRenderer(static fn () => '');
        return $storage;
    }

    private function useClient(?SentryClient $client): void
    {
        Bootstrap::$staticObjectManager = null;
        if ($client !== null) {
            Bootstrap::$staticObjectManager = $this->createMock(ObjectManagerInterface::class);
            Bootstrap::$staticObjectManager->method('get')->willReturn($client);
        }
    }

    private function clientAnswering(bool $success, string $message, string $eventId): SentryClient
    {
        $client = $this->createMock(SentryClient::class);
        $client->method('captureThrowable')->willReturn(new CaptureResult($success, $message, $eventId));
        return $client;
    }

    private function dumpFiles(): array
    {
        return glob($this->storagePath . '/*.txt') ?: [];
    }
}
