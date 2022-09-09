<?php
declare(strict_types=1);

namespace Flownative\Sentry\Test;

/*
 * This file is part of the Flownative.Sentry package.
 *
 * (c) Karsten Dambekalns, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

class JsonSerializableTestArgument implements \JsonSerializable
{
    private int $value;

    public function __construct(int $value)
    {
        $this->value = $value;
    }

    public function jsonSerialize()
    {
        return $this->value;
    }
}
