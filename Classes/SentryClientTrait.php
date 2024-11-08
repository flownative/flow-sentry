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

use Neos\Flow\Core\Bootstrap;
use Neos\Flow\ObjectManagement\CompileTimeObjectManager;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;

trait SentryClientTrait
{
    protected static function getSentryClient(): ?SentryClient
    {
        if (!Bootstrap::$staticObjectManager instanceof ObjectManagerInterface || Bootstrap::$staticObjectManager instanceof CompileTimeObjectManager) {
            return null;
        }
        return Bootstrap::$staticObjectManager->get(SentryClient::class);
    }
}
