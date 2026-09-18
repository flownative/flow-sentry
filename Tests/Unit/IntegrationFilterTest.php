<?php
declare(strict_types=1);

namespace Flownative\Sentry\Tests\Unit;

use Flownative\Sentry\IntegrationFilter;
use PHPUnit\Framework\TestCase;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\Integration\RequestIntegration;

final class IntegrationFilterTest extends TestCase
{
    public function testExcludesOnlyTheGivenIntegrations(): void
    {
        $filter = IntegrationFilter::excluding([
            ExceptionListenerIntegration::class,
            ErrorListenerIntegration::class,
        ]);

        $result = $filter([
            new ExceptionListenerIntegration(),
            new ErrorListenerIntegration(),
            new FatalErrorListenerIntegration(),
            new RequestIntegration(),
        ]);

        $resultClasses = array_map('get_class', $result);
        self::assertSame([FatalErrorListenerIntegration::class, RequestIntegration::class], $resultClasses);
    }

    public function testEmptyExclusionKeepsEverything(): void
    {
        $filter = IntegrationFilter::excluding([]);
        $integrations = [new FatalErrorListenerIntegration()];
        self::assertSame($integrations, $filter($integrations));
    }
}
