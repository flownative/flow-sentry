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

use Neos\Flow\Security\Account;
use Neos\Flow\Security\Context;

class DefaultUserContextService implements UserContextServiceInterface
{
    public function getUserContext(Context $securityContext): UserContextInterface
    {
        $userContext = new UserContext();

        if ($securityContext->isInitialized()) {
            $account = $securityContext->getAccount();
            if ($account instanceof Account) {
                $userContext->setUsername($account->getAccountIdentifier());
            }
        }
        return $userContext;
    }
}
