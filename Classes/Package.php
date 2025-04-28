<?php
declare(strict_types=1);

namespace Flownative\Sentry;

use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Booting\Sequence;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;

class Package extends BasePackage
{
    public function boot(Bootstrap $bootstrap)
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();

        $dispatcher->connect(Sequence::class, 'afterInvokeStep', function ($step) use ($bootstrap) {
            if ($step->getIdentifier() === 'neos.flow:objectmanagement:runtime') {
                $configurationManager = $bootstrap->getObjectManager()->get(ConfigurationManager::class);
                $settings = $configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, $this->getPackageKey());
                // instantiate client to set up Sentry and register error handler early
                /** @noinspection PhpExpressionResultUnusedInspection */
                new SentryClient($settings);
            }
        });
    }
}
