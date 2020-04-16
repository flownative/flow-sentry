[![MIT license](http://img.shields.io/badge/license-MIT-brightgreen.svg)](http://opensource.org/licenses/MIT)
[![Packagist](https://img.shields.io/packagist/v/flownative/sentry.svg)](https://packagist.org/packages/flownative/sentry)
[![Maintenance level: Love](https://img.shields.io/badge/maintenance-%E2%99%A1%E2%99%A1%E2%99%A1-ff69b4.svg)](https://www.flownative.com/en/products/open-source.html)

# Sentry integration for Flow 5.x

This [Flow](https://flow.neos.io) package allows you to automate reporting of errors to [Sentry](https://www.sentry.io) 

## Key Features


## Installation

The Sentry integration is installed as a regular Flow package via Composer. For your existing project, simply include 
`flownative/sentry` into the dependencies of your Flow or Neos distribution:

```bash
$ composer require flownative/sentry
```

## Configuration

You need to at least specify a DSN to be used as a logging target. Apart from that, you
can configure the Sentry environment and release. All options can either be set in the
Flow settings or, more conveniently, by setting the respective environment variables.

```yaml
Flownative:
  Sentry:
    dsn: "%env:SENTRY_DSN%"
    environment: "%env:SENTRY_ENVIRONMENT%"
    release: "%env:SENTRY_RELEASE%"
```

Throwables (that includes exceptions and runtime errors) are logged as Sentry events. 
You may specify a list of exceptions which should not be recorded. If such an exception
is thrown, it will only be logged as a "notice".

```yaml
Flownative:
  Sentry:
    capture:
      excludeExceptionTypes:
        - 'Neos\Flow\Mvc\Controller\Exception\InvalidControllerException'
```

If an ignore exception is handled by this Sentry client, it is logged similar to the
following message:

```
… NOTICE Exception 12345: The exception message (Ref: 202004161706040c28ae | Sentry: ignored)
```
