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

use Flownative\Sentry\RepresentationSerializer;
use Flownative\Sentry\StringShape;
use JsonSerializable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Options;
use Stringable;

interface MarksPersonalData
{
}

class FullName implements MarksPersonalData, Stringable
{
    public function __construct(public string $value = 'Ada Lovelace')
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

final class Nickname extends FullName
{
}

final class JsonAddress implements MarksPersonalData, JsonSerializable
{
    public function jsonSerialize(): string
    {
        return 'ada@example.com';
    }
}

final class PlainSecret implements MarksPersonalData
{
    public string $value = 'hunter2';
}

final class BrokenName implements MarksPersonalData, Stringable
{
    public function __toString(): string
    {
        throw new \RuntimeException('Cannot be read', 1793520001);
    }
}

final class SignUp
{
    public FullName $name;
    public string $plan = 'basic';

    public function __construct()
    {
        $this->name = new FullName();
    }
}

final class ProductCode implements Stringable
{
    public function __toString(): string
    {
        return 'SKU-1';
    }
}

final class ProductSize implements JsonSerializable
{
    public function jsonSerialize(): string
    {
        return 'large';
    }
}

final class RepresentationSerializerTest extends TestCase
{
    #[Test]
    public function withoutRedactedTypesAnObjectIsShownThroughItsStringOrJson(): void
    {
        $serializer = $this->serializer([]);

        self::assertSame(['__class' => FullName::class, '__string' => 'Ada Lovelace'], $serializer->representationSerialize(new FullName()));
        self::assertSame(['__class' => JsonAddress::class, '__json' => '"ada@example.com"'], $serializer->representationSerialize(new JsonAddress()));
        self::assertSame(['value' => 'hunter2', '__class ' => PlainSecret::class], $serializer->representationSerialize(new PlainSecret()));
    }

    #[Test]
    public function withoutRedactedTypesAnEventKeepsItsData(): void
    {
        $name = new FullName();
        $event = Event::createEvent();
        $event->setExtra(['name' => $name]);
        $event->setContext('signUp', ['name' => $name]);
        $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'Signed up', ['name' => $name])]);

        $this->serializer([])->redactEvent($event);

        self::assertSame($name, $event->getExtra()['name']);
        self::assertSame($name, $event->getContexts()['signUp']['name']);
        self::assertSame($name, $event->getBreadcrumbs()[0]->getMetadata()['name']);
    }

    #[Test]
    public function aMarkedObjectIsShownByClassAndShape(): void
    {
        self::assertSame(
            ['__class' => FullName::class, '__shape' => StringShape::of('Ada Lovelace')],
            $this->serializer([FullName::class])->representationSerialize(new FullName())
        );
    }

    #[Test]
    public function aSubclassOfAMarkedClassIsMarked(): void
    {
        self::assertSame(
            ['__class' => Nickname::class, '__shape' => StringShape::of('Ada Lovelace')],
            $this->serializer([FullName::class])->representationSerialize(new Nickname())
        );
    }

    #[Test]
    public function anImplementerOfAMarkedInterfaceIsMarked(): void
    {
        self::assertSame(
            ['__class' => FullName::class, '__shape' => StringShape::of('Ada Lovelace')],
            $this->serializer([MarksPersonalData::class])->representationSerialize(new FullName())
        );
    }

    #[Test]
    public function jsonIsDescribedOnlyByItsShape(): void
    {
        self::assertSame(
            ['__class' => JsonAddress::class, '__shape' => StringShape::of('"ada@example.com"')],
            $this->serializer([MarksPersonalData::class])->representationSerialize(new JsonAddress())
        );
    }

    #[Test]
    public function anObjectWithNeitherStringNorJsonIsShownByClassOnly(): void
    {
        self::assertSame(
            ['__class' => PlainSecret::class],
            $this->serializer([MarksPersonalData::class])->representationSerialize(new PlainSecret())
        );
    }

    #[Test]
    public function aStringThatCannotBeReadLeavesTheClass(): void
    {
        self::assertSame(
            ['__class' => BrokenName::class],
            $this->serializer([MarksPersonalData::class])->representationSerialize(new BrokenName())
        );
    }

    #[Test]
    public function anUnmarkedObjectKeepsItsRepresentationNextToAMarkedOne(): void
    {
        $serializer = $this->serializer([MarksPersonalData::class]);

        self::assertSame(['__class' => ProductCode::class, '__string' => 'SKU-1'], $serializer->representationSerialize(new ProductCode()));
        self::assertSame(['__class' => ProductSize::class, '__json' => '"large"'], $serializer->representationSerialize(new ProductSize()));
    }

    #[Test]
    public function aMarkedObjectInsideAnObjectOrArrayIsRedacted(): void
    {
        $serializer = $this->serializer([MarksPersonalData::class]);
        $shape = ['__class' => FullName::class, '__shape' => StringShape::of('Ada Lovelace')];

        self::assertSame(['name' => $shape, 'plan' => 'basic', '__class ' => SignUp::class], $serializer->representationSerialize(new SignUp()));
        self::assertSame(['people' => [$shape]], $serializer->representationSerialize(['people' => [new FullName()]]));
    }

    #[Test]
    public function aMarkedObjectBelowTheDepthLimitStaysRedacted(): void
    {
        $serialized = $this->serializer([MarksPersonalData::class])->representationSerialize([[[[new FullName()]]]]);

        self::assertStringNotContainsString('Ada', (string)json_encode($serialized));
    }

    #[Test]
    public function extraDataContextsAndBreadcrumbsAreRedacted(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['name' => new FullName(), 'people' => [new FullName()], 'signUp' => new SignUp(), 'count' => 3]);
        $event->setContext('signUp', ['name' => new FullName(), 'plan' => 'basic']);
        $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'Signed up', ['name' => new FullName(), 'plan' => 'basic'])]);

        $this->serializer([MarksPersonalData::class])->redactEvent($event);

        $shape = ['__class' => FullName::class, '__shape' => StringShape::of('Ada Lovelace')];
        self::assertSame($shape, $event->getExtra()['name']);
        self::assertSame([$shape], $event->getExtra()['people']);
        self::assertSame($shape, $event->getExtra()['signUp']['name']);
        self::assertSame(3, $event->getExtra()['count']);
        self::assertSame(['name' => $shape, 'plan' => 'basic'], $event->getContexts()['signUp']);
        self::assertSame(['name' => $shape, 'plan' => 'basic'], $event->getBreadcrumbs()[0]->getMetadata());
        self::assertStringNotContainsString('Ada', (string)json_encode([$event->getExtra(), $event->getContexts(), $event->getBreadcrumbs()[0]->getMetadata()]));
    }

    /**
     * @param list<string> $redactedTypes
     */
    private function serializer(array $redactedTypes): RepresentationSerializer
    {
        $serializer = new RepresentationSerializer(new Options([]));
        $serializer->setSerializeAllObjects(true);
        $serializer->setRedactedTypes($redactedTypes);

        return $serializer;
    }
}
