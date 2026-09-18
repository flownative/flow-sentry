<?php
declare(strict_types=1);

namespace Flownative\Sentry\Tests\Unit\Scrubbing;

use Flownative\Sentry\Scrubbing\ScrubberChainFactory;
use Flownative\Sentry\Scrubbing\ValuePatternScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/**
 * Runs the chain through a real SDK client with an in-memory transport, so
 * scope enrichment (user, extras) demonstrably passes the before_send
 * composition the package installs.
 */
final class ScrubberChainClientIntegrationTest extends TestCase
{
    /** @var Event[] */
    private array $sentEvents = [];

    private function buildHub(callable $beforeSend): Hub
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

        $options = [
            'dsn' => 'https://public@example.invalid/1',
            'before_send' => $beforeSend,
        ];
        $builder = ClientBuilder::create($options);
        $builder->setTransport($transport);
        return new Hub($builder->getClient());
    }

    public function testScopeEnrichedEventIsScrubbedThroughRealClient(): void
    {
        $factory = new ScrubberChainFactory([
            'patterns' => ['className' => ValuePatternScrubber::class, 'options' => ['patterns' => ['email']]],
        ]);
        $scrub = static fn(Event $event, ?EventHint $hint): ?Event => $factory->getChain()->process($event, $hint);

        $hub = $this->buildHub($scrub);
        $hub->configureScope(static function (Scope $scope): void {
            $scope->setExtra('note', 'reply to visitor@example.com');
        });
        $hub->captureMessage('boom from visitor@example.com');

        self::assertCount(1, $this->sentEvents);
        $sentEvent = $this->sentEvents[0];
        self::assertSame('boom from [Filtered]', $sentEvent->getMessage());
        self::assertSame('reply to [Filtered]', $sentEvent->getExtra()['note']);
    }

    public function testLedgerComposedBeforeSendDeduplicatesLogThenRethrow(): void
    {
        // Mirrors the package's before_send composition: ledger check first,
        // then scrub, then mark on accept
        $ledger = new \Flownative\Sentry\CaptureLedger();
        $beforeSend = static function (Event $event, ?EventHint $hint) use ($ledger): ?Event {
            if ($hint?->exception !== null) {
                if ($ledger->hasBeenCaptured($hint->exception)) {
                    return null;
                }
            }
            if ($event !== null && $hint?->exception !== null) {
                $ledger->remember($hint->exception);
            }
            return $event;
        };

        $hub = $this->buildHub($beforeSend);
        $original = new \RuntimeException('driver failure');
        $hub->captureException($original);
        $wrapper = new \LogicException('query failed', 0, $original);
        $hub->captureException($wrapper);
        $unrelated = new \RuntimeException('second incident');
        $hub->captureException($unrelated);

        self::assertCount(2, $this->sentEvents);
    }

    public function testFailingScrubberYieldsSyntheticEventThroughRealClient(): void
    {
        $factory = new ScrubberChainFactory([
            'broken' => ['className' => ThrowingScrubber::class],
        ]);
        $scrub = static fn(Event $event, ?EventHint $hint): ?Event => $factory->getChain()->process($event, $hint);

        $hub = $this->buildHub($scrub);
        $hub->captureMessage('secret visitor@example.com payload');

        self::assertCount(1, $this->sentEvents);
        $sentEvent = $this->sentEvents[0];
        self::assertSame('Flownative Sentry event scrubber failed – the original event was discarded', $sentEvent->getMessage());
        self::assertStringNotContainsString('visitor@example.com', var_export($sentEvent->getExtra(), true));
    }
}

final class ThrowingScrubber implements \Flownative\Sentry\Scrubbing\EventScrubberInterface
{
    public function __construct(array $options = [])
    {
    }

    public function scrub(Event $event, ?EventHint $hint): ?Event
    {
        throw new \RuntimeException('scrubber exploded');
    }
}
