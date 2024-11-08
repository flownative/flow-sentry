<?php
declare(strict_types=1);

namespace Flownative\Sentry\Command;

/*
 * This file is part of the Flownative.Sentry package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flownative\Sentry\SentryClient;
use Flownative\Sentry\Test\SentryClientTestException;
use Flownative\Sentry\Test\StringableTestArgument;
use Flownative\Sentry\Test\ThrowingClass;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Sentry\Severity;

final class SentryCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var SentryClient
     */
    protected $sentryClient;

    /**
     * Test the setup
     *
     * This command allows you to test the Sentry integration and validates that the
     * configuration, credentials and connection to the Sentry server work fine.
     *
     * For testing purposes, an event will be sent to Sentry.
     *
     * @throws SentryClientTestException
     */
    public function testCommand(): void
    {
        $this->output->outputLine('<b>Testing Sentry setup …</b>');
        $this->output->outputLine();
        $this->output->outputLine('Using the following configuration:');

        $options = $this->sentryClient->getOptions();

        $this->output->outputTable([
            ['DSN', $options->getDsn()],
            ['Environment', $options->getEnvironment()],
            ['Release', $options->getRelease()],
            ['Server Name', $options->getServerName()],
            ['Sample Rate', $options->getSampleRate()]
        ], [
            'Option',
            'Value'
        ]);

        $captureResult = $this->sentryClient->captureMessage(
            'Flownative Sentry Plugin Test',
            Severity::debug(),
            [
                'Flownative Sentry Client Version' => 'dev'
            ]
        );

        $this->outputLine();
        $this->outputLine('An informational message was sent to Sentry');
        if ($captureResult) {
            $this->outputLine('<success>Event ID: #' . $captureResult->eventId . '</success>');
        } else {
            $this->outputLine('<error>' . $captureResult->message . '</error>');
        }

        $this->outputLine();
        $this->outputLine('This command will now throw an exception for testing purposes.');
        $this->outputLine();

        (new ThrowingClass())->throwException(new StringableTestArgument((string)M_PI));
    }
}
