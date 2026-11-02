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

use Flownative\Sentry\StringShape;
use PHPUnit\Framework\TestCase;

final class StringShapeTest extends TestCase
{
    public function testAnOrdinaryStringIsDescribedByLengthAndEncoding(): void
    {
        self::assertStringStartsWith('5 characters, 7 bytes, UTF-8', StringShape::of('Grüße'));
    }

    public function testUnusualCodePointsAreNamedOnceEach(): void
    {
        self::assertStringEndsWith('unusual U+0009 U+200B U+2028', StringShape::of("a\tb\u{200B}\u{200B}\u{2028}c"));
    }

    public function testInvalidUtf8IsCountedInBytesOnly(): void
    {
        self::assertSame('3 bytes, not valid UTF-8', StringShape::of("a\xC3\x28"));
    }

    public function testManyUnusualCodePointsAreCut(): void
    {
        $value = implode('', array_map(static fn (int $codePoint): string => mb_chr($codePoint, 'UTF-8'), range(1, 12)));

        self::assertStringEndsWith('U+000A and 2 more', StringShape::of($value));
    }
}
