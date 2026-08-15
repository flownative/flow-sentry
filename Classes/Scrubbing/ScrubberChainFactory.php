<?php
declare(strict_types=1);

namespace Flownative\Sentry\Scrubbing;

/*
 * This file is part of the Flownative.Sentry package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flownative\Sentry\Exception\InvalidConfigurationException;
use Neos\Utility\PositionalArraySorter;
use Throwable;

/**
 * Validates the Flownative.Sentry.scrubbers configuration at construction
 * time (i.e. during boot) and lazily builds the scrubber chain once, on the
 * first event. The chain closure is held by the SDK client options, so the
 * chain applies to whatever client sent the event, regardless of how the
 * surrounding SentryClient instance was created.
 */
final class ScrubberChainFactory
{
    private ScrubberChain $chain;

    /**
     * Validation, sorting AND scrubber instantiation happen here — during
     * settings injection, i.e. at boot. A misconfigured chain must fail the
     * deployment loudly instead of surfacing as a runtime scrubbing failure.
     *
     * @throws InvalidConfigurationException
     */
    public function __construct(array $scrubberConfiguration)
    {
        foreach ($scrubberConfiguration as $identifier => $entry) {
            if ($entry === null) {
                continue;
            }
            if (!is_array($entry) || !isset($entry['className']) || !is_string($entry['className'])) {
                throw new InvalidConfigurationException(
                    sprintf('Flownative.Sentry.scrubbers.%s must be null or an array with a "className" string', $identifier),
                    1755264010
                );
            }
            if (!class_exists($entry['className'])) {
                throw new InvalidConfigurationException(
                    sprintf('Flownative.Sentry.scrubbers.%s.className: class %s does not exist', $identifier, $entry['className']),
                    1755264011
                );
            }
            if (!is_subclass_of($entry['className'], EventScrubberInterface::class)) {
                throw new InvalidConfigurationException(
                    sprintf('Flownative.Sentry.scrubbers.%s.className: %s does not implement %s', $identifier, $entry['className'], EventScrubberInterface::class),
                    1755264012
                );
            }
            if (!(new \ReflectionClass($entry['className']))->isInstantiable()) {
                throw new InvalidConfigurationException(
                    sprintf('Flownative.Sentry.scrubbers.%s.className: %s is not instantiable', $identifier, $entry['className']),
                    1755264016
                );
            }
            if (isset($entry['options']) && !is_array($entry['options'])) {
                throw new InvalidConfigurationException(
                    sprintf('Flownative.Sentry.scrubbers.%s.options must be an array', $identifier),
                    1755264013
                );
            }
        }

        $enabledConfiguration = array_filter($scrubberConfiguration, static fn($entry) => $entry !== null);
        try {
            $sortedConfiguration = (new PositionalArraySorter($enabledConfiguration))->toArray();
        } catch (Throwable $throwable) {
            throw new InvalidConfigurationException(
                'Flownative.Sentry.scrubbers positions could not be resolved: ' . $throwable->getMessage(),
                1755264014
            );
        }
        // The sorter does not detect circular before/after references itself
        if (array_diff(array_keys($enabledConfiguration), array_keys($sortedConfiguration)) !== []) {
            throw new InvalidConfigurationException(
                'Flownative.Sentry.scrubbers positions are incomplete after sorting — check for circular before/after references',
                1755264015
            );
        }

        $scrubbers = [];
        foreach ($sortedConfiguration as $identifier => $entry) {
            $className = $entry['className'];
            try {
                $scrubbers[$identifier] = new $className($entry['options'] ?? []);
            } catch (Throwable $throwable) {
                throw new InvalidConfigurationException(
                    sprintf('Flownative.Sentry.scrubbers.%s could not be constructed: %s', $identifier, $throwable->getMessage()),
                    1755264017
                );
            }
        }
        $this->chain = new ScrubberChain($scrubbers);
    }

    public function getChain(): ScrubberChain
    {
        return $this->chain;
    }
}
