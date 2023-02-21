<?php
declare(strict_types=1);

namespace Flownative\Sentry\Context;

/*
 * This file is part of the Flownative.Sentry package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

interface WithExtraDataInterface
{
    /**
     * Returns an array with extra data to communicate to Sentry when capturing an exception.
     *
     * This is supposed to be implemented by a user-defined exception.
     */
    public function getExtraData(): array;
}
