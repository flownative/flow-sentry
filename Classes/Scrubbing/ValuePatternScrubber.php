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

use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Redacts values matching named patterns from all free-text carrying parts
 * of an event: message, exception values, breadcrumbs, extras (including
 * the log backend's additional data), contexts, tags, fingerprint and the
 * request interface. The user interface is deliberately NOT touched, so an
 * allowed editor username survives even if it looks like an email address.
 *
 * Objects encountered in event data are replaced by "[object <class>]"
 * entirely — their content is never serialized by this scrubber.
 *
 * Options:
 * - patterns: string[] — names of built-in patterns to apply. Default [].
 *   Available: url-credentials, email, ipv4, ipv6, iban, phone.
 *   The phone pattern is prone to false positives (invoice or serial
 *   numbers); enable it deliberately.
 * - urlCredentialSchemes: string[] — URI schemes whose userinfo is
 *   redacted by the url-credentials pattern.
 * - sensitiveKeys: string[] — array keys whose values are redacted
 *   entirely, matched case-insensitively as substring.
 * - replacement: string — the replacement marker. Default "[Filtered]".
 * - maxDepth: int — recursion depth limit for nested data. Default 10.
 */
final class ValuePatternScrubber implements EventScrubberInterface
{
    private const BUILTIN_PATTERNS = [
        // "/" is legal in RFC local parts but omitted so path-embedded
        // addresses ("/unsubscribe/user@host") redact only the address
        'email' => '/[A-Za-z0-9.!#$%&\'*+=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)+/',
        'ipv4' => '/\b(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\b/',
        'ipv6' => '/(?<![0-9a-z:.])(?=(?:[0-9a-f]{0,4}:){2})(?:[0-9a-f]{1,4}:|:){2,8}(?:[0-9a-f]{1,4})?(?:%[0-9a-z]+)?(?![0-9a-z:])/i',
        'iban' => '/\b[A-Za-z]{2}\d{2}(?: ?[A-Za-z0-9]{1,4}){3,8}\b/',
        // A number only qualifies with an international prefix or interior
        // separators — bare digit runs (exception codes, timestamps, IDs,
        // reference codes) never match. Guards keep ISO and dotted dates.
        'phone' => '/(?<![\w\/.])(?!\d{4}-\d{2}-\d{2}(?!\d))(?!\d{1,2}\.\d{1,2}\.\d{2,4}(?![\d.]))(?:\+\d[\d \-\/().]{6,}\d|\d{1,5}[ \-\/().][\d \-\/().]{4,}\d)(?!\w)/',
    ];

    private const DEFAULT_URL_CREDENTIAL_SCHEMES = [
        'http', 'https', 'ftp', 'ftps', 'sftp', 'ssh', 'smtp', 'smtps', 'imap', 'imaps',
        'mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql', 'redis', 'rediss',
        'amqp', 'amqps', 'mongodb', 'mongodb+srv', 'ldap', 'ldaps',
    ];

    /**
     * Compared against a canonical key form with all non-alphanumeric
     * characters removed, so api_key, api-key and X-Api-Key all match.
     */
    private const DEFAULT_SENSITIVE_KEYS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'auth',
        'apikey', 'credentials', 'cookie', 'sessionid', 'privatekey',
        'bearer', 'passphrase', 'csrf',
    ];

    private array $activePatterns;
    private bool $applyUrlCredentials;
    private array $urlCredentialSchemes;
    private array $sensitiveKeys;
    private string $replacement;
    private int $maxDepth;

    public function __construct(array $options = [])
    {
        $this->activePatterns = [];
        $this->applyUrlCredentials = false;
        foreach ($options['patterns'] ?? [] as $patternName) {
            if ($patternName === 'url-credentials') {
                $this->applyUrlCredentials = true;
                continue;
            }
            if (!isset(self::BUILTIN_PATTERNS[$patternName])) {
                throw new RuntimeException(sprintf('ValuePatternScrubber: unknown pattern "%s"', $patternName), 1755264020);
            }
            $this->activePatterns[$patternName] = self::BUILTIN_PATTERNS[$patternName];
        }
        $this->urlCredentialSchemes = array_map('strtolower', $options['urlCredentialSchemes'] ?? self::DEFAULT_URL_CREDENTIAL_SCHEMES);
        $this->sensitiveKeys = array_map(
            static fn($key) => (string)preg_replace('/[^a-z0-9]/', '', strtolower((string)$key)),
            $options['sensitiveKeys'] ?? self::DEFAULT_SENSITIVE_KEYS
        );
        $this->replacement = (string)($options['replacement'] ?? '[Filtered]');
        $this->maxDepth = (int)($options['maxDepth'] ?? 10);
    }

    public function scrub(Event $event, ?EventHint $hint): ?Event
    {
        if ($event->getMessage() !== null) {
            $scrubbedParams = $this->scrubValue($event->getMessageParams());
            $event->setMessage(
                $this->scrubString($event->getMessage()),
                is_array($scrubbedParams) ? $scrubbedParams : [],
                $event->getMessageFormatted() !== null ? $this->scrubString($event->getMessageFormatted()) : null
            );
        }

        foreach ($event->getExceptions() as $exceptionDataBag) {
            $exceptionDataBag->setValue($this->scrubString($exceptionDataBag->getValue()));
        }

        $breadcrumbs = [];
        $breadcrumbsChanged = false;
        foreach ($event->getBreadcrumbs() as $breadcrumb) {
            $scrubbedMessage = $breadcrumb->getMessage() !== null ? $this->scrubString($breadcrumb->getMessage()) : null;
            $scrubbedCategory = $this->scrubString($breadcrumb->getCategory());
            $scrubbedMetadata = $this->scrubValue($breadcrumb->getMetadata());
            if ($scrubbedMessage !== $breadcrumb->getMessage()
                || $scrubbedCategory !== $breadcrumb->getCategory()
                || $scrubbedMetadata !== $breadcrumb->getMetadata()) {
                $breadcrumb = new Breadcrumb(
                    $breadcrumb->getLevel(),
                    $breadcrumb->getType(),
                    $scrubbedCategory,
                    $scrubbedMessage,
                    is_array($scrubbedMetadata) ? $scrubbedMetadata : [],
                    $breadcrumb->getTimestamp()
                );
                $breadcrumbsChanged = true;
            }
            $breadcrumbs[] = $breadcrumb;
        }
        if ($breadcrumbsChanged) {
            $event->setBreadcrumb($breadcrumbs);
        }

        $extra = $this->scrubValue($event->getExtra());
        $event->setExtra(is_array($extra) ? $extra : []);

        foreach ($event->getContexts() as $contextName => $contextData) {
            if (is_array($contextData)) {
                $scrubbedContext = $this->scrubValue($contextData);
                $event->setContext($contextName, is_array($scrubbedContext) ? $scrubbedContext : []);
            }
        }

        $tags = [];
        foreach ($event->getTags() as $tagKey => $tagValue) {
            $tags[$this->scrubString((string)$tagKey)] = $this->scrubString((string)$tagValue);
        }
        $event->setTags($tags);

        $fingerprint = $event->getFingerprint();
        if ($fingerprint !== []) {
            $event->setFingerprint(array_map(fn($part) => $this->scrubString((string)$part), $fingerprint));
        }

        $request = $event->getRequest();
        if ($request !== []) {
            $scrubbedRequest = $this->scrubValue($request);
            $event->setRequest(is_array($scrubbedRequest) ? $scrubbedRequest : []);
        }

        $checkIn = $event->getCheckIn();
        if ($checkIn !== null) {
            if (!method_exists($checkIn, 'setMonitorSlug')) {
                // fail-closed: an unscrubbable check-in must not pass through
                throw new RuntimeException('ValuePatternScrubber: CheckIn::setMonitorSlug() is unavailable in this SDK version', 1755264023);
            }
            $checkIn->setMonitorSlug($this->scrubString($checkIn->getMonitorSlug()));
        }

        $transaction = $event->getTransaction();
        if ($transaction !== null) {
            $event->setTransaction($this->scrubString($transaction));
        }
        foreach ($event->getSpans() as $span) {
            $description = $span->getDescription();
            if ($description !== null) {
                $span->setDescription($this->scrubString($description));
            }
            $spanTags = [];
            foreach ($span->getTags() as $spanTagKey => $spanTagValue) {
                $spanTags[$this->scrubString((string)$spanTagKey)] = $this->scrubString((string)$spanTagValue);
            }
            if ($spanTags !== []) {
                $span->setTags($spanTags);
            }
            $spanData = $span->getData();
            if (is_array($spanData) && $spanData !== []) {
                $scrubbedSpanData = $this->scrubValue($spanData);
                $span->setData(is_array($scrubbedSpanData) ? $scrubbedSpanData : []);
            }
        }

        // The user interface is deliberately not touched (allowed usernames survive).
        return $event;
    }

    private function scrubValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > $this->maxDepth) {
            return $this->replacement . ' (depth limit)';
        }
        if (is_string($value)) {
            return $this->scrubString($value);
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $result[$key] = $this->replacement;
                    continue;
                }
                $resultKey = is_string($key) ? $this->scrubString($key) : $key;
                $result[$resultKey] = $this->scrubValue($item, $depth + 1);
            }
            return $result;
        }
        if (is_object($value)) {
            if ($value instanceof \DateTimeInterface) {
                return $value;
            }
            // Never serialize object content here — replace it entirely
            return '[object ' . ScrubberChain::normalizeClassName(get_class($value)) . ']';
        }
        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $canonicalKey = (string)preg_replace('/[^a-z0-9]/', '', strtolower($key));
        foreach ($this->sensitiveKeys as $sensitiveKey) {
            if ($sensitiveKey !== '' && str_contains($canonicalKey, $sensitiveKey)) {
                return true;
            }
        }
        return false;
    }

    private function scrubString(string $value): string
    {
        if ($this->applyUrlCredentials) {
            $value = preg_replace_callback(
                '~\b([a-z][a-z0-9+.-]*)(://)([^/?#@\s]++)@~i',
                fn(array $matches) => in_array(strtolower($matches[1]), $this->urlCredentialSchemes, true)
                    ? $matches[1] . $matches[2] . $this->replacement . '@'
                    : $matches[0],
                $value
            );
            if ($value === null) {
                throw new RuntimeException('ValuePatternScrubber: url-credentials replacement failed', 1755264021);
            }
        }
        foreach ($this->activePatterns as $patternName => $pattern) {
            // preg_replace_callback keeps the replacement literal — a
            // configured replacement like "$0" must not re-insert the match
            $value = preg_replace_callback($pattern, fn(): string => $this->replacement, $value);
            if ($value === null) {
                // fail-closed: a failing regex must not let the value through
                throw new RuntimeException(sprintf('ValuePatternScrubber: pattern "%s" failed', $patternName), 1755264022);
            }
        }
        return $value;
    }
}
