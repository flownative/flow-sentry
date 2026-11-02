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

use ErrorException;
use Flownative\Sentry\SentryClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Goes through reflection, because the exclusion is only reachable from the
 * before_send callback of an initialized Sentry SDK.
 */
final class SentryClientExclusionTest extends TestCase
{
    private const LIBRARY_FILE = 'Packages/Libraries/acme/lib/src/Thing.php';
    private const COMBINED_KEY = 'E_WARNING | E_DEPRECATED | E_USER_DEPRECATED';

    public static function setUpBeforeClass(): void
    {
        if (!defined('FLOW_PATH_ROOT')) {
            define('FLOW_PATH_ROOT', '/flow/root/');
        }
    }

    public static function levelsOfTheCombinedMask(): array
    {
        return [
            'E_WARNING' => [E_WARNING],
            'E_DEPRECATED' => [E_DEPRECATED],
            'E_USER_DEPRECATED' => [E_USER_DEPRECATED],
        ];
    }

    #[DataProvider('levelsOfTheCombinedMask')]
    public function testCombinedMaskMatchesEachOfItsLevels(int $severity): void
    {
        $client = $this->clientExcluding([self::COMBINED_KEY => ['Packages/Libraries/']]);

        self::assertTrue($this->isExcluded($client, $this->errorInLibrary($severity)));
    }

    public function testLevelOutsideTheMaskIsNotExcluded(): void
    {
        $client = $this->clientExcluding([self::COMBINED_KEY => ['Packages/Libraries/']]);

        self::assertFalse($this->isExcluded($client, $this->errorInLibrary(E_NOTICE)));
    }

    public function testPathOutsideThePrefixIsNotExcluded(): void
    {
        $client = $this->clientExcluding([self::COMBINED_KEY => ['Packages/Libraries/']]);
        $error = new ErrorException('Warning', 0, E_WARNING, FLOW_PATH_ROOT . 'Packages/Application/Acme/Thing.php', 1);

        self::assertFalse($this->isExcluded($client, $error));
    }

    public function testThrowableWithoutErrorLevelIsNeverExcluded(): void
    {
        $client = $this->clientExcluding(['E_ALL' => ['Packages/Libraries/']]);

        self::assertFalse($this->isExcluded($client, $this->exceptionInLibrary()));
    }

    public function testErrorLevelKeyLeavesOrdinaryExceptionsAlone(): void
    {
        $client = $this->clientExcluding(['E_ERROR' => ['Packages/Libraries/']]);

        self::assertFalse($this->isExcluded($client, $this->exceptionInLibrary()));
    }

    public function testKeysOfTheSameMaskKeepAllTheirPrefixes(): void
    {
        $client = $this->clientExcluding([
            'E_WARNING | E_NOTICE' => ['Packages/Other/'],
            'E_NOTICE | E_WARNING' => ['Packages/Libraries/'],
        ]);

        self::assertTrue($this->isExcluded($client, $this->errorInLibrary(E_WARNING)));
    }

    public function testLeadingSlashOrDotOfAPrefixIsIgnored(): void
    {
        foreach (['/Packages/Libraries/', './Packages/Libraries/'] as $prefix) {
            $client = $this->clientExcluding(['E_WARNING' => [$prefix]]);

            self::assertTrue($this->isExcluded($client, $this->errorInLibrary(E_WARNING)), $prefix);
        }
    }

    public function testPrefixOfTheRootAloneExcludesNothing(): void
    {
        $client = $this->clientExcluding(['E_WARNING' => ['/', './', '']]);

        self::assertFalse($this->isExcluded($client, $this->errorInLibrary(E_WARNING)));
    }

    private function clientExcluding(array $excludeExceptionFilePathPrefixesByErrorLevel): SentryClient
    {
        return new SentryClient([
            'dsn' => '',
            'environment' => '',
            'release' => '',
            'capture' => ['excludeExceptionFilePathPrefixesByErrorLevel' => $excludeExceptionFilePathPrefixesByErrorLevel],
        ]);
    }

    private function errorInLibrary(int $severity): ErrorException
    {
        return new ErrorException('An error', 0, $severity, FLOW_PATH_ROOT . self::LIBRARY_FILE, 1);
    }

    private function exceptionInLibrary(): RuntimeException
    {
        $exception = new RuntimeException('An exception');
        (new ReflectionProperty($exception, 'file'))->setValue($exception, FLOW_PATH_ROOT . self::LIBRARY_FILE);

        return $exception;
    }

    private function isExcluded(SentryClient $client, Throwable $throwable): bool
    {
        return (new ReflectionMethod($client, 'shouldExcludeException'))->invoke($client, $throwable);
    }
}
