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

use Neos\Flow\Annotations\InjectConfiguration;
use Neos\Flow\Cli\CommandController;
use Sentry\Severity;
use Sentry\State\Hub;
use Sentry\State\Scope;

final class SentryCommandController extends CommandController
{

    /**
     * @InjectConfiguration(path="dsn")
     * @var string
     */
    protected $dsn;

    /**
     * @InjectConfiguration(path="environment")
     * @var string
     */
    protected $environment;

    /**
     * @InjectConfiguration(path="release")
     * @var string
     */
    protected $release;

    /**
     * @InjectConfiguration(path="clientOptions")
     * @var string
     */
    protected $clientOptions;

    /**
     * Test the setup
     *
     * This command allows you to test the Sentry integration and validates that the
     * configuration, credentials and connection to the Sentry server work fine.
     *
     * For testing purposes, an event will be sent to Sentry.
     */
    public function testCommand(): void
    {
        $this->output->outputLine('<b>Testing Sentry setup …</b>');
        $this->output->outputLine('Using the following configuration:');
        $this->output->outputTable([
            ['DSN', $this->dsn],
            ['Environment', $this->environment],
            ['Release', $this->release]
        ], [
            'Option',
            'Value'
        ]);

        \Sentry\init(['dsn' => $this->dsn]);

        if (!$client = Hub::getCurrent()->getClient()) {
            $this->outputLine('<error>Failed initializing the Sentry SDK client</error>');
            exit (1);
        }

        $options = $client->getOptions();
        $options->setEnvironment($this->environment);
        $options->setRelease($this->release);
        $options->setProjectRoot(FLOW_PATH_ROOT);
        $options->setPrefixes([FLOW_PATH_ROOT]);

        \Sentry\configureScope(function(Scope $scope): void {
            $scope->setTag('test', 'test');
            $scope->setLevel(Severity::debug());
        });

        $client->captureMessage('Flownative Sentry Plugin Test', Severity::debug());

        $this->output->outputLine('<success>A message was sent to Sentry</success>');
    }
}
