<?php
declare(strict_types=1);

namespace Flownative\Sentry\Tests\Unit;

use Flownative\Sentry\CaptureLedger;
use PHPUnit\Framework\TestCase;

final class CaptureLedgerTest extends TestCase
{
    public function testUnknownThrowableIsNotCaptured(): void
    {
        $ledger = new CaptureLedger();
        self::assertFalse($ledger->hasBeenCaptured(new \RuntimeException('fresh')));
    }

    public function testRememberedThrowableIsCaptured(): void
    {
        $ledger = new CaptureLedger();
        $throwable = new \RuntimeException('captured');
        $ledger->remember($throwable);
        self::assertTrue($ledger->hasBeenCaptured($throwable));
    }

    public function testWrapperCarryingCapturedOriginalIsDetected(): void
    {
        $ledger = new CaptureLedger();
        $original = new \PDOException('driver error');
        $ledger->remember($original);

        $wrapper = new \RuntimeException('query failed', 0, $original);
        self::assertTrue($ledger->hasBeenCaptured($wrapper));
    }

    public function testRememberingWrapperMarksWholeChain(): void
    {
        $ledger = new CaptureLedger();
        $original = new \PDOException('driver error');
        $wrapper = new \RuntimeException('query failed', 0, $original);
        $ledger->remember($wrapper);

        self::assertTrue($ledger->hasBeenCaptured($original));
    }

    public function testDistinctThrowablesAreIndependent(): void
    {
        $ledger = new CaptureLedger();
        $ledger->remember(new \RuntimeException('first attempt'));
        self::assertFalse($ledger->hasBeenCaptured(new \RuntimeException('second attempt')));
    }
}
