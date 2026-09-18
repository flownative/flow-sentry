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

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Reduces the request interface of an event to a keep-only set: method,
 * URL and query string (filtered against a query parameter allowlist,
 * fragments always removed) and allowlisted headers. Request body, cookies
 * and env are always dropped. Breadcrumb "url" metadata is filtered with
 * the same rules.
 *
 * Options:
 * - queryParamAllowlist: string[] — query parameters to keep, compared
 *   case-sensitively against the form-url-decoded parameter name. Default [].
 * - headerAllowlist: string[] — header names to keep, case-insensitive.
 *   Default []. An allowlisted Referer still gets its query string filtered.
 */
final class RequestScrubber implements EventScrubberInterface
{
    private array $queryParamAllowlist;
    private array $headerAllowlist;

    public function __construct(array $options = [])
    {
        $this->queryParamAllowlist = array_map('strval', $options['queryParamAllowlist'] ?? []);
        $this->headerAllowlist = array_map(
            static fn($headerName) => strtolower((string)$headerName),
            $options['headerAllowlist'] ?? []
        );
    }

    public function scrub(Event $event, ?EventHint $hint): ?Event
    {
        $request = $event->getRequest();
        if ($request !== []) {
            $cleanRequest = [];
            if (isset($request['method'])) {
                $cleanRequest['method'] = $request['method'];
            }
            if (isset($request['url'])) {
                $cleanRequest['url'] = $this->filterUrl((string)$request['url']);
            }
            if (isset($request['query_string'])) {
                $cleanRequest['query_string'] = $this->filterQueryString((string)$request['query_string']);
            }
            if (isset($request['headers']) && is_array($request['headers'])) {
                $cleanRequest['headers'] = $this->filterHeaders($request['headers']);
            }
            // data, cookies and env are dropped on purpose
            $event->setRequest($cleanRequest);
        }

        $breadcrumbs = [];
        $breadcrumbsChanged = false;
        foreach ($event->getBreadcrumbs() as $breadcrumb) {
            $metadata = $breadcrumb->getMetadata();
            if (isset($metadata['url']) && is_string($metadata['url'])) {
                $filteredUrl = $this->filterUrl($metadata['url']);
                if ($filteredUrl !== $metadata['url']) {
                    $metadata['url'] = $filteredUrl;
                    $breadcrumb = new Breadcrumb(
                        $breadcrumb->getLevel(),
                        $breadcrumb->getType(),
                        $breadcrumb->getCategory(),
                        $breadcrumb->getMessage(),
                        $metadata,
                        $breadcrumb->getTimestamp()
                    );
                    $breadcrumbsChanged = true;
                }
            }
            $breadcrumbs[] = $breadcrumb;
        }
        if ($breadcrumbsChanged) {
            $event->setBreadcrumb($breadcrumbs);
        }

        return $event;
    }

    private function filterUrl(string $url): string
    {
        $fragmentPosition = strpos($url, '#');
        if ($fragmentPosition !== false) {
            $url = substr($url, 0, $fragmentPosition);
        }
        $queryPosition = strpos($url, '?');
        if ($queryPosition === false) {
            return $url;
        }
        $filteredQuery = $this->filterQueryString(substr($url, $queryPosition + 1));
        return $filteredQuery === ''
            ? substr($url, 0, $queryPosition)
            : substr($url, 0, $queryPosition) . '?' . $filteredQuery;
    }

    private function filterQueryString(string $queryString): string
    {
        if ($queryString === '') {
            return '';
        }
        // A fragment has no business in a query string field either
        $fragmentPosition = strpos($queryString, '#');
        if ($fragmentPosition !== false) {
            $queryString = substr($queryString, 0, $fragmentPosition);
        }

        // No parse_str(): it would collapse repeated parameters and mangle
        // names. Delimiters are captured so surviving pairs keep their own.
        $tokens = preg_split('/([&;])/', $queryString, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $kept = '';
        for ($i = 0; $i < count($tokens); $i += 2) {
            $rawPair = $tokens[$i];
            if ($rawPair === '') {
                continue;
            }
            $rawName = strstr($rawPair, '=', true);
            if ($rawName === false) {
                $rawName = $rawPair;
            }
            $decodedName = rawurldecode(str_replace('+', ' ', $rawName));
            if (in_array($decodedName, $this->queryParamAllowlist, true)) {
                $precedingDelimiter = $i > 0 ? ($tokens[$i - 1] ?: '&') : '';
                $kept .= ($kept === '' ? '' : $precedingDelimiter) . $rawPair;
            }
        }
        return $kept;
    }

    private function filterHeaders(array $headers): array
    {
        $keptHeaders = [];
        foreach ($headers as $headerName => $headerValue) {
            $normalizedName = strtolower((string)$headerName);
            if (!in_array($normalizedName, $this->headerAllowlist, true)) {
                continue;
            }
            if ($normalizedName === 'referer' || $normalizedName === 'referrer') {
                $headerValue = is_array($headerValue)
                    ? array_map(fn($value) => $this->filterUrl((string)$value), $headerValue)
                    : $this->filterUrl((string)$headerValue);
            }
            $keptHeaders[$headerName] = $headerValue;
        }
        return $keptHeaders;
    }
}
