[![MIT license](http://img.shields.io/badge/license-MIT-brightgreen.svg)](http://opensource.org/licenses/MIT)
[![Packagist](https://img.shields.io/packagist/v/flownative/sentry.svg)](https://packagist.org/packages/flownative/sentry)
[![Maintenance level: Love](https://img.shields.io/badge/maintenance-%E2%99%A1%E2%99%A1%E2%99%A1-ff69b4.svg)](https://www.flownative.com/en/products/open-source.html)

# Sentry integration for Flow 6.x, 7.x and Flow 8.x

This [Flow](https://flow.neos.io) package allows you to automate
reporting of errors to [Sentry](https://www.sentry.io)

## Key Features


## Installation

The Sentry integration is installed as a regular Flow package via
Composer. For your existing project, simply include `flownative/sentry`
into the dependencies of your Flow or Neos distribution:

```bash
$ composer require flownative/sentry
```

## Configuration

You need to at least specify a DSN to be used as a logging target. Apart
from that, you can configure the Sentry environment and release. All
options can either be set in the Flow settings or, more conveniently, by
setting the respective environment variables.

```yaml
Flownative:
  Sentry:
    dsn: "%env:SENTRY_DSN%"
    environment: "%env:SENTRY_ENVIRONMENT%"
    release: "%env:SENTRY_RELEASE%"
```

Throwables (that includes exceptions and runtime errors) are logged as
Sentry events. You may specify a list of exceptions which should not be
recorded. If such an exception is thrown, it will only be logged as a
"notice".

```yaml
Flownative:
  Sentry:
    capture:
      excludeExceptionTypes:
        - 'Neos\Flow\Mvc\Controller\Exception\InvalidControllerException'
```

If an ignored exception is handled by this Sentry client, it is logged
similar to the following message:

```
… NOTICE Exception 12345: The exception message (Ref: 202004161706040c28ae | Sentry: ignored)
```

## Testing the Client

This package provides a command controller which allows you to log a
test message and a test exception.

Run the following command in your terminal to test your configuration:

```
./flow sentry:test
Testing Sentry setup …
Using the following configuration:
+-------------+------------------------------------------------------------+
| Option      | Value                                                      |
+-------------+------------------------------------------------------------+
| DSN         | https://abc123456789abcdef1234567890ab@sentry.io/1234567 |
| Environment | development                                                |
| Release     | dev                                                        |
| Server Name | test_container                                             |
| Sample Rate | 1                                                          |
+-------------+------------------------------------------------------------+
An informational message was sent to Sentry Event ID: #587abc123457abcd8f873b4212345678

This command will now throw an exception for testing purposes.

Test exception in SentryCommandController

  Type: Flownative\Sentry\Exception\SentryClientTestException
  Code: 1614759519
  File: Data/Temporary/Development/SubContextBeach/SubContextInstance/Cache/Code/Fl
        ow_Object_Classes/Flownative_Sentry_Command_SentryCommandController.php
  Line: 79

Nested exception:
Test "previous" exception thrown by the SentryCommandController

  Type: RuntimeException
  Code: 1614759554
  File: Data/Temporary/Development/SubContextBeach/SubContextInstance/Cache/Code/Fl
        ow_Object_Classes/Flownative_Sentry_Command_SentryCommandController.php
  Line: 78

Open Data/Logs/Exceptions/2021030308325919ecbf.txt for a full stack trace.


````
