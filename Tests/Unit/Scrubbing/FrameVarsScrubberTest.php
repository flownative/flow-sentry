<?php
declare(strict_types=1);

namespace Flownative\Sentry\Tests\Unit\Scrubbing;

use Flownative\Sentry\Scrubbing\FrameVarsScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;

final class FrameVarsScrubberTest extends TestCase
{
    private function stacktraceWithVars(): Stacktrace
    {
        return new Stacktrace([
            new Frame(
                'App\Controller::submitAction',
                'DistributionPackages/App/Classes/Controller.php',
                42,
                'submitAction',
                '/var/www/html/DistributionPackages/App/Classes/Controller.php',
                ['email' => 'visitor@example.com', 'message' => 'secret'],
                true
            ),
        ]);
    }

    public function testExceptionStacktraceFramesLoseVarsAndAbsolutePath(): void
    {
        $event = Event::createEvent();
        $event->setExceptions([
            new ExceptionDataBag(new \RuntimeException('boom'), $this->stacktraceWithVars(), null),
        ]);

        $event = (new FrameVarsScrubber())->scrub($event, null);
        $frame = $event->getExceptions()[0]->getStacktrace()->getFrames()[0];

        self::assertSame([], $frame->getVars());
        self::assertNull($frame->getAbsoluteFilePath());
        self::assertSame('App\Controller::submitAction', $frame->getFunctionName());
        self::assertSame('DistributionPackages/App/Classes/Controller.php', $frame->getFile());
        self::assertSame(42, $frame->getLine());
        self::assertTrue($frame->isInApp());
    }

    public function testEventLevelStacktraceIsAlsoStripped(): void
    {
        $event = Event::createEvent();
        $event->setStacktrace($this->stacktraceWithVars());

        $event = (new FrameVarsScrubber())->scrub($event, null);

        self::assertSame([], $event->getStacktrace()->getFrames()[0]->getVars());
    }

    public function testAbsoluteFilePathsAreRelativized(): void
    {
        $event = Event::createEvent();
        $event->setStacktrace(new Stacktrace([
            new Frame('vendor_function', '/Users/alice/Sites/project/vendor/lib/File.php', 10, null, '/Users/alice/Sites/project/vendor/lib/File.php', [], false),
        ]));

        $frame = (new FrameVarsScrubber())->scrub($event, null)->getStacktrace()->getFrames()[0];

        self::assertStringNotContainsString('/Users/alice', $frame->getFile());
        self::assertSame('…/File.php', $frame->getFile());
    }

    public function testAnonymousClassPathsInFunctionNamesAreStripped(): void
    {
        $event = Event::createEvent();
        $event->setStacktrace(new Stacktrace([
            new Frame('class@anonymous/Users/alice/Sites/secret/File.php:12$3f::handle', 'File.php', 12, 'class@anonymous/Users/alice/Sites/secret/File.php:12$3f::handle', null, [], true),
        ]));

        $frame = (new FrameVarsScrubber())->scrub($event, null)->getStacktrace()->getFrames()[0];

        self::assertStringNotContainsString('/Users/alice', (string)$frame->getFunctionName());
        self::assertStringNotContainsString('/Users/alice', (string)$frame->getRawFunctionName());
    }
}
