<?php
declare(strict_types=1);

namespace Flownative\Sentry\Tests\Unit\Scrubbing;

use Flownative\Sentry\Scrubbing\EventScrubberInterface;
use Flownative\Sentry\Scrubbing\ScrubberChain;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventHint;

final class ScrubberChainTest extends TestCase
{
    public function testScrubbersRunInGivenOrder(): void
    {
        $calls = [];
        $makeScrubber = function (string $name) use (&$calls): EventScrubberInterface {
            return new class($name, $calls) implements EventScrubberInterface {
                public function __construct(private readonly string $name, private array &$calls)
                {
                }

                public function scrub(Event $event, ?EventHint $hint): ?Event
                {
                    $this->calls[] = $this->name;
                    return $event;
                }
            };
        };

        $chain = new ScrubberChain(['first' => $makeScrubber('first'), 'second' => $makeScrubber('second')]);
        $result = $chain->process(Event::createEvent(), null);

        self::assertNotNull($result);
        self::assertSame(['first', 'second'], $calls);
    }

    public function testNullReturnDiscardsEventAndStopsChain(): void
    {
        $secondCalled = false;
        $discarding = new class implements EventScrubberInterface {
            public function scrub(Event $event, ?EventHint $hint): ?Event
            {
                return null;
            }
        };
        $recording = new class($secondCalled) implements EventScrubberInterface {
            public function __construct(private bool &$called)
            {
            }

            public function scrub(Event $event, ?EventHint $hint): ?Event
            {
                $this->called = true;
                return $event;
            }
        };

        $chain = new ScrubberChain(['discard' => $discarding, 'after' => $recording]);

        self::assertNull($chain->process(Event::createEvent(), null));
        self::assertFalse($secondCalled);
    }

    public function testThrowingScrubberProducesSyntheticFailureEvent(): void
    {
        $throwing = new class implements EventScrubberInterface {
            public function scrub(Event $event, ?EventHint $hint): ?Event
            {
                throw new \RuntimeException('contains secret@example.com PII');
            }
        };
        $chain = new ScrubberChain(['broken' => $throwing]);

        $originalEvent = Event::createEvent();
        $originalEvent->setMessage('original message with visitor@example.com');
        $hint = EventHint::fromArray(['exception' => new \DomainException('sensitive original message', 4711)]);

        $failureEvent = $chain->process($originalEvent, $hint);

        self::assertNotNull($failureEvent);
        self::assertNotSame($originalEvent, $failureEvent);
        self::assertSame('Flownative Sentry event scrubber failed – the original event was discarded', $failureEvent->getMessage());
        self::assertSame(['flownative-sentry-scrubber-failure', 'broken', 'RuntimeException'], $failureEvent->getFingerprint());
        self::assertSame('flownative.sentry.scrubber', $failureEvent->getLogger());

        $extra = $failureEvent->getExtra();
        self::assertSame('broken', $extra['scrubber']);
        self::assertSame('RuntimeException', $extra['scrubber_exception_class']);
        self::assertSame('DomainException', $extra['original_exception_class']);
        self::assertSame(4711, $extra['original_exception_code']);
        self::assertSame((string)$originalEvent->getId(), $extra['discarded_event_id']);

        // Nothing from the original event or the throwable messages may appear
        $serialized = var_export($extra, true) . (string)$failureEvent->getMessage();
        self::assertStringNotContainsString('visitor@example.com', $serialized);
        self::assertStringNotContainsString('secret@example.com', $serialized);
        self::assertStringNotContainsString('sensitive original message', $serialized);
    }

    public function testNormalizeClassName(): void
    {
        self::assertSame('RuntimeException', ScrubberChain::normalizeClassName('RuntimeException'));
        self::assertSame('Foo\Bar\Baz', ScrubberChain::normalizeClassName('Foo\Bar\Baz'));
        self::assertSame('[anonymous]', ScrubberChain::normalizeClassName('class@anonymous/var/www/secret/path.php:12$0'));
        self::assertSame('[invalid-class]', ScrubberChain::normalizeClassName("Weird\0Class/With/Path"));
    }
}
