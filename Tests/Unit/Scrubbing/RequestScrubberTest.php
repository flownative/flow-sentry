<?php
declare(strict_types=1);

namespace Flownative\Sentry\Tests\Unit\Scrubbing;

use Flownative\Sentry\Scrubbing\RequestScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;

final class RequestScrubberTest extends TestCase
{
    public function testBodyCookiesAndEnvAreAlwaysDropped(): void
    {
        $scrubber = new RequestScrubber();
        $event = Event::createEvent();
        $event->setRequest([
            'method' => 'POST',
            'url' => 'https://example.com/contact',
            'data' => ['email' => 'visitor@example.com', 'message' => 'secret'],
            'cookies' => ['session' => 'abc'],
            'env' => ['REMOTE_ADDR' => '203.0.113.7'],
        ]);

        $request = $scrubber->scrub($event, null)->getRequest();

        self::assertSame('POST', $request['method']);
        self::assertArrayNotHasKey('data', $request);
        self::assertArrayNotHasKey('cookies', $request);
        self::assertArrayNotHasKey('env', $request);
    }

    public function testQueryStringIsFilteredAgainstAllowlist(): void
    {
        $scrubber = new RequestScrubber(['queryParamAllowlist' => ['search']]);
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://example.com/find?search=a&search=b&token=SECRET&se%61rch=encoded#fragment',
            'query_string' => 'search=a&search=b&token=SECRET',
        ]);

        $request = $scrubber->scrub($event, null)->getRequest();

        // repeated params survive, token is dropped, encoded name is form-decoded for comparison, fragment is gone
        self::assertSame('https://example.com/find?search=a&search=b&se%61rch=encoded', $request['url']);
        self::assertSame('search=a&search=b', $request['query_string']);
    }

    public function testUrlWithoutRemainingParamsLosesQuestionMark(): void
    {
        $scrubber = new RequestScrubber();
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://example.com/find?token=SECRET']);

        self::assertSame('https://example.com/find', $scrubber->scrub($event, null)->getRequest()['url']);
    }

    public function testHeadersAreKeepOnlyAndRefererQueryIsFiltered(): void
    {
        $scrubber = new RequestScrubber(['headerAllowlist' => ['User-Agent', 'Referer'], 'queryParamAllowlist' => ['search']]);
        $event = Event::createEvent();
        $event->setRequest([
            'headers' => [
                'user-agent' => 'TestBrowser/1.0',
                'X-Custom-Secret' => 'internal',
                'Referer' => 'https://example.com/from?search=term&token=SECRET',
            ],
        ]);

        $headers = $scrubber->scrub($event, null)->getRequest()['headers'];

        self::assertSame('TestBrowser/1.0', $headers['user-agent']);
        self::assertArrayNotHasKey('X-Custom-Secret', $headers);
        self::assertSame('https://example.com/from?search=term', $headers['Referer']);
    }

    public function testSemicolonDelimitersArePreservedAndFragmentInQueryStringIsCut(): void
    {
        $scrubber = new RequestScrubber(['queryParamAllowlist' => ['a', 'c']]);
        $event = Event::createEvent();
        $event->setRequest(['query_string' => 'a=1;b=2;c=3&d=4#frag']);

        self::assertSame('a=1;c=3', $scrubber->scrub($event, null)->getRequest()['query_string']);
    }

    public function testParamNamesAreCaseSensitive(): void
    {
        $scrubber = new RequestScrubber(['queryParamAllowlist' => ['search']]);
        $event = Event::createEvent();
        $event->setRequest(['query_string' => 'Search=term&search=kept']);

        self::assertSame('search=kept', $scrubber->scrub($event, null)->getRequest()['query_string']);
    }

    public function testBreadcrumbUrlMetadataIsFiltered(): void
    {
        $scrubber = new RequestScrubber(['queryParamAllowlist' => ['page']]);
        $event = Event::createEvent();
        $event->setBreadcrumb([
            new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_HTTP,
                'http',
                null,
                ['url' => 'https://example.com/api?page=2&token=SECRET', 'status_code' => 200]
            ),
        ]);

        $breadcrumbs = $scrubber->scrub($event, null)->getBreadcrumbs();

        self::assertSame('https://example.com/api?page=2', $breadcrumbs[0]->getMetadata()['url']);
        self::assertSame(200, $breadcrumbs[0]->getMetadata()['status_code']);
    }
}
