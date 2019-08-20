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

use Neos\Flow\Security\Context;

interface UserContextServiceInterface
{
    /**
     * Returns ContextData to be added to the sentry entry
     *
     * @param Context $securityContext
     * @return UserContextInterface
     */
    public function getUserContext(Context $securityContext): UserContextInterface;
}
