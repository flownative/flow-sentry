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

use Closure;

/**
 * Builds the callable for the SDK's "integrations" option. Only the callable
 * form can remove a default integration — an "integrations" array is merged
 * with the defaults by the SDK and cannot unregister anything.
 */
final class IntegrationFilter
{
    /**
     * @param string[] $excludedIntegrationClassNames
     */
    public static function excluding(array $excludedIntegrationClassNames): Closure
    {
        return static function (array $integrations) use ($excludedIntegrationClassNames): array {
            return array_values(array_filter(
                $integrations,
                static function ($integration) use ($excludedIntegrationClassNames): bool {
                    return !in_array(get_class($integration), $excludedIntegrationClassNames, true);
                }
            ));
        };
    }
}
