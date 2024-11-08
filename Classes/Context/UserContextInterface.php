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

interface UserContextInterface
{
    public function getId(): ?string;

    public function getUsername(): ?string;

    public function getEmail(): ?string;

    /**
     * Convert into an array with the following keys:
     *
     * "id", "username", "email"
     *
     * The keys must exist, but the values may be empty
     */
    public function toArray(): array;
}
