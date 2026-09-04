<?php
declare(strict_types=1);

namespace Flownative\Sentry\Tests\Unit\Scrubbing;

use Flownative\Sentry\Scrubbing\ValuePatternScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\UserDataBag;

final class ValuePatternScrubberTest extends TestCase
{
    private function scrubberWith(array $patterns, array $options = []): ValuePatternScrubber
    {
        return new ValuePatternScrubber(array_merge(['patterns' => $patterns], $options));
    }

    public function testEmailIsRedactedInMessageAndExtra(): void
    {
        $scrubber = $this->scrubberWith(['email']);
        $event = Event::createEvent();
        $event->setMessage('Contact not found for email visitor@example.com');
        $event->setExtra(['note' => 'reply to visitor@example.com please']);

        $event = $scrubber->scrub($event, null);

        self::assertSame('Contact not found for email [Filtered]', $event->getMessage());
        self::assertSame('reply to [Filtered] please', $event->getExtra()['note']);
    }

    public function testUrlCredentialsAreRedactedForAllowlistedSchemesOnly(): void
    {
        $scrubber = $this->scrubberWith(['url-credentials']);
        $event = Event::createEvent();
        $event->setMessage(implode(' ', [
            'https://elastic:s3cret@es.example.com:9200/index',
            'redis://default:hunter2@redis.example.com',
            'https://tokenonly@api.example.com',
            'namespace://foo:bar@notacredential',
            'mailto:someone@example.com',
        ]));

        $message = $scrubber->scrub($event, null)->getMessage();

        self::assertStringContainsString('https://[Filtered]@es.example.com:9200/index', $message);
        self::assertStringContainsString('redis://[Filtered]@redis.example.com', $message);
        self::assertStringContainsString('https://[Filtered]@api.example.com', $message);
        self::assertStringContainsString('namespace://foo:bar@notacredential', $message);
        self::assertStringContainsString('mailto:someone@example.com', $message);
        self::assertStringNotContainsString('s3cret', $message);
        self::assertStringNotContainsString('hunter2', $message);
        self::assertStringNotContainsString('tokenonly', $message);
    }

    public function testIpAddressesAreRedacted(): void
    {
        $scrubber = $this->scrubberWith(['ipv4', 'ipv6']);
        $event = Event::createEvent();
        $event->setMessage('from 203.0.113.7 and fe80::1a2b:3c4d%eth0 but version 1.2 stays');

        $message = $scrubber->scrub($event, null)->getMessage();

        self::assertStringNotContainsString('203.0.113.7', $message);
        self::assertStringNotContainsString('fe80::1a2b', $message);
        self::assertStringContainsString('version 1.2 stays', $message);
    }

    public function testSensitiveKeysAreRedactedEntirely(): void
    {
        $scrubber = $this->scrubberWith([]);
        $event = Event::createEvent();
        $event->setExtra([
            'Password' => 'hunter2',
            'nested' => ['api_key' => 'abc123', 'harmless' => 'value'],
        ]);

        $extra = $scrubber->scrub($event, null)->getExtra();

        self::assertSame('[Filtered]', $extra['Password']);
        self::assertSame('[Filtered]', $extra['nested']['api_key']);
        self::assertSame('value', $extra['nested']['harmless']);
    }

    public function testObjectsAreReplacedWithoutSerializingContent(): void
    {
        $scrubber = $this->scrubberWith([]);
        $formData = new class {
            public string $email = 'visitor@example.com';
        };
        $event = Event::createEvent();
        $event->setExtra(['form' => $formData, 'when' => new \DateTimeImmutable('2026-08-15')]);

        $extra = $scrubber->scrub($event, null)->getExtra();

        self::assertIsString($extra['form']);
        self::assertStringStartsWith('[object ', $extra['form']);
        self::assertStringNotContainsString('visitor@example.com', $extra['form']);
        self::assertInstanceOf(\DateTimeImmutable::class, $extra['when']);
    }

    public function testDepthLimitCutsDeepStructures(): void
    {
        $scrubber = $this->scrubberWith([], ['maxDepth' => 2]);
        $event = Event::createEvent();
        $event->setExtra(['a' => ['b' => ['c' => ['d' => 'too deep']]]]);

        $extra = $scrubber->scrub($event, null)->getExtra();

        self::assertSame('[Filtered] (depth limit)', $extra['a']['b']['c']);
    }

    public function testTagsAndFingerprintAreScrubbed(): void
    {
        $scrubber = $this->scrubberWith(['email']);
        $event = Event::createEvent();
        $event->setTags(['reporter' => 'visitor@example.com']);
        $event->setFingerprint(['route', 'visitor@example.com']);

        $event = $scrubber->scrub($event, null);

        self::assertSame('[Filtered]', $event->getTags()['reporter']);
        self::assertSame(['route', '[Filtered]'], $event->getFingerprint());
    }

    public function testUserInterfaceIsNotTouched(): void
    {
        $scrubber = $this->scrubberWith(['email']);
        $event = Event::createEvent();
        $event->setUser(UserDataBag::createFromArray(['username' => 'editor@agency.example']));

        $event = $scrubber->scrub($event, null);

        self::assertSame('editor@agency.example', $event->getUser()->getUsername());
    }

    public function testBreadcrumbMessageAndMetadataAreScrubbed(): void
    {
        $scrubber = $this->scrubberWith(['email']);
        $event = Event::createEvent();
        $event->setBreadcrumb([
            new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'System_Development.log',
                'Blocked spam from visitor@example.com',
                ['additionalData' => ['referrer' => 'mail from visitor@example.com']]
            ),
        ]);

        $breadcrumb = $scrubber->scrub($event, null)->getBreadcrumbs()[0];

        self::assertSame('Blocked spam from [Filtered]', $breadcrumb->getMessage());
        self::assertSame('mail from [Filtered]', $breadcrumb->getMetadata()['additionalData']['referrer']);
    }

    public function testUnknownPatternNameIsRejectedAtConstruction(): void
    {
        $this->expectException(\RuntimeException::class);
        new ValuePatternScrubber(['patterns' => ['no-such-pattern']]);
    }

    public function testNonStringMessageParamsAreScrubbedToo(): void
    {
        $scrubber = $this->scrubberWith(['email']);
        $event = Event::createEvent();
        $event->setMessage('Submission failed for %s', [
            'contact' => ['email' => 'visitor@example.com'],
            'Password' => 'hunter2',
        ]);

        $params = $scrubber->scrub($event, null)->getMessageParams();

        self::assertSame('[Filtered]', $params['contact']['email']);
        self::assertSame('[Filtered]', $params['Password']);
    }

    public function testDollarZeroReplacementDoesNotReinsertTheMatch(): void
    {
        $scrubber = $this->scrubberWith(['email'], ['replacement' => '$0']);
        $event = Event::createEvent();
        $event->setMessage('mail from visitor@example.com');

        self::assertSame('mail from $0', $scrubber->scrub($event, null)->getMessage());
    }

    public function testSeparatorVariantsOfSensitiveKeysAreCaught(): void
    {
        $scrubber = $this->scrubberWith([]);
        $event = Event::createEvent();
        $event->setExtra([
            'X-Api-Key' => 'abc',
            'session-id' => 'def',
            'sessionId' => 'ghi',
            'passphrase' => 'jkl',
        ]);

        $extra = $scrubber->scrub($event, null)->getExtra();

        self::assertSame(['X-Api-Key' => '[Filtered]', 'session-id' => '[Filtered]', 'sessionId' => '[Filtered]', 'passphrase' => '[Filtered]'], $extra);
    }

    public function testIpv6TrailingCompressionAndIsoDatesAreHandled(): void
    {
        $scrubber = $this->scrubberWith(['ipv6', 'phone']);
        $event = Event::createEvent();
        $event->setMessage('net 2001:db8:: on 2026-08-15 call +43 660 1234567');

        $message = $scrubber->scrub($event, null)->getMessage();

        self::assertStringNotContainsString('2001:db8::', $message);
        self::assertStringContainsString('2026-08-15', $message);
        self::assertStringNotContainsString('660 1234567', $message);
    }

    public function testBareDigitRunsAndDatesSurviveThePhonePattern(): void
    {
        $scrubber = $this->scrubberWith(['phone']);
        $event = Event::createEvent();
        $event->setMessage('code 1662712736 ref 20260816105252694 on 16.08.2026, call (0512) 53 60 93');

        $message = $scrubber->scrub($event, null)->getMessage();

        // Flow exception codes, reference codes and dotted dates are not phone numbers
        self::assertStringContainsString('1662712736', $message);
        self::assertStringContainsString('20260816105252694', $message);
        self::assertStringContainsString('16.08.2026', $message);
        // a separator-formatted number still is
        self::assertStringNotContainsString('53 60 93', $message);
    }

    public function testSpacedIbanIsRedacted(): void
    {
        $scrubber = $this->scrubberWith(['iban']);
        $event = Event::createEvent();
        $event->setMessage('pay to AT61 1904 3002 3457 3201 please');

        self::assertStringNotContainsString('1904 3002', $scrubber->scrub($event, null)->getMessage());
    }

    public function testTransactionNameAndSpanContentAreScrubbed(): void
    {
        $scrubber = $this->scrubberWith(['email']);
        $span = new \Sentry\Tracing\Span();
        $span->setDescription('GET /person/visitor@example.com');
        $span->setData(['request_target' => 'lookup visitor@example.com']);

        $event = Event::createEvent();
        $event->setTransaction('GET /unsubscribe/visitor@example.com');
        $event->setSpans([$span]);

        $event = $scrubber->scrub($event, null);

        self::assertSame('GET /unsubscribe/[Filtered]', $event->getTransaction());
        self::assertSame('GET /person/[Filtered]', $span->getDescription());
        self::assertSame('lookup [Filtered]', $span->getData()['request_target']);
    }
}
