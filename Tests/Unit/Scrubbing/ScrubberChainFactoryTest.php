<?php
declare(strict_types=1);

namespace Flownative\Sentry\Tests\Unit\Scrubbing;

use Flownative\Sentry\Exception\InvalidConfigurationException;
use Flownative\Sentry\Scrubbing\EventScrubberInterface;
use Flownative\Sentry\Scrubbing\FrameVarsScrubber;
use Flownative\Sentry\Scrubbing\ScrubberChainFactory;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventHint;

final class OrderRecordingScrubber implements EventScrubberInterface
{
    public static array $order = [];
    private string $name;

    public function __construct(array $options = [])
    {
        $this->name = $options['name'] ?? '?';
    }

    public function scrub(Event $event, ?EventHint $hint): ?Event
    {
        self::$order[] = $this->name;
        return $event;
    }
}

final class ScrubberChainFactoryTest extends TestCase
{
    public function testEntryWithoutClassNameIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new ScrubberChainFactory(['broken' => ['position' => 'end']]);
    }

    public function testUnknownClassIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new ScrubberChainFactory(['broken' => ['className' => 'No\Such\ClassHere']]);
    }

    public function testClassWithoutInterfaceIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new ScrubberChainFactory(['broken' => ['className' => \stdClass::class]]);
    }

    public function testNonArrayOptionsAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new ScrubberChainFactory(['broken' => ['className' => FrameVarsScrubber::class, 'options' => 'nope']]);
    }

    public function testNullEntryDisablesScrubber(): void
    {
        OrderRecordingScrubber::$order = [];
        $factory = new ScrubberChainFactory([
            'active' => ['className' => OrderRecordingScrubber::class, 'options' => ['name' => 'active']],
            'disabled' => null,
        ]);
        $factory->getChain()->process(Event::createEvent(), null);
        self::assertSame(['active'], OrderRecordingScrubber::$order);
    }

    public function testNumericPositionsDetermineOrder(): void
    {
        OrderRecordingScrubber::$order = [];
        $factory = new ScrubberChainFactory([
            'later' => ['className' => OrderRecordingScrubber::class, 'position' => 200, 'options' => ['name' => 'later']],
            'earlier' => ['className' => OrderRecordingScrubber::class, 'position' => 100, 'options' => ['name' => 'earlier']],
        ]);
        $factory->getChain()->process(Event::createEvent(), null);
        self::assertSame(['earlier', 'later'], OrderRecordingScrubber::$order);
    }

    public function testUnresolvablePositionReferenceIsRejectedAtConstruction(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new ScrubberChainFactory([
            'orphan' => ['className' => FrameVarsScrubber::class, 'position' => 'after nonexisting'],
        ]);
    }

    public function testScrubberConstructorFailureIsRejectedAtConstruction(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new ScrubberChainFactory([
            'broken' => ['className' => \Flownative\Sentry\Scrubbing\ValuePatternScrubber::class, 'options' => ['patterns' => ['no-such-pattern']]],
        ]);
    }
}
