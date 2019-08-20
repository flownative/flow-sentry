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
    /**
     * @return string|null
     */
    public function getId(): ?string;

    /**
     * @return string|null
     */
    public function getUsername(): ?string;

    /**
     * @return string|null
     */
    public function getEmail(): ?string;

    /**
     * Convert into an array with the following keys:
     *
     * "id", "username", "email"
     *
     * The keys must exist, but the values may be empty
     *
     * @return array
     */
    public function toArray(): array;
}
