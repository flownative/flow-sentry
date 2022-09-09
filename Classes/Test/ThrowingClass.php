<?php
declare(strict_types=1);

namespace Flownative\Sentry\Test;

/*
 * This file is part of the Flownative.Sentry package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

class ThrowingClass
{
    /**
     * @throws SentryClientTestException
     */
    public function throwException(StringableTestArgument $argument): void
    {
        $this->doNotThrowYet((float)(string)$argument);
    }

    /**
     * @throws SentryClientTestException
     */
    private function doNotThrowYet(float $argument): void
    {
        $anotherArgument = new JsonSerializableTestArgument((int)$argument);
        $this->doreallyThrowException($anotherArgument);
    }

    /**
     * @throws SentryClientTestException
     */
    private function doreallyThrowException(JsonSerializableTestArgument $argument): void
    {
        $previousException = new \RuntimeException('Test "previous" exception in ThrowingClass', 1662712735);
        throw new SentryClientTestException('Test exception in ThrowingClass', 1662712736, $previousException);
    }
}
