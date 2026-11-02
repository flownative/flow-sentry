<?php
declare(strict_types=1);

namespace Flownative\Sentry;

/*
 * This file is part of the Flownative.Sentry package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

/**
 * Describes a string without its content: length, encoding, normalization and
 * the code points that could explain why a value caused an error.
 */
final class StringShape
{
    private const MAX_CODE_POINTS = 10;

    public static function of(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return sprintf('%d bytes, not valid UTF-8', strlen($value));
        }

        $shape = sprintf('%d characters, %d bytes, UTF-8', mb_strlen($value), strlen($value));
        // Normalizer comes with ext-intl, which this package does not require.
        if (class_exists(\Normalizer::class)) {
            $shape .= \Normalizer::isNormalized($value) ? ', NFC' : ', not NFC';
        }
        $unusual = self::unusualCodePoints($value);

        return $unusual === '' ? $shape : $shape . ', unusual ' . $unusual;
    }

    /**
     * Controls, format characters, private use, unassigned code points and line or paragraph separators, each once.
     */
    private static function unusualCodePoints(string $value): string
    {
        preg_match_all('/[\p{C}\p{Zl}\p{Zp}]/u', $value, $matches);
        $codePoints = array_values(array_unique(array_map(
            static fn (string $character): string => sprintf('U+%04X', mb_ord($character, 'UTF-8')),
            $matches[0]
        )));
        if (count($codePoints) > self::MAX_CODE_POINTS) {
            return implode(' ', array_slice($codePoints, 0, self::MAX_CODE_POINTS)) . sprintf(' and %d more', count($codePoints) - self::MAX_CODE_POINTS);
        }

        return implode(' ', $codePoints);
    }
}
