# Representation serializer regression test

Run inside an existing Neos distribution with `neos/eel` and `sentry/sentry` installed. The package does not need a configured DSN. The test builds an SDK exception stack and never initializes a Sentry client or makes network calls.

```sh
ddev exec php Packages/Application/Flownative.Sentry/Tests/Integration/RepresentationSerializerTest.php
```

The script exits nonzero on failures and treats PHP warnings as exceptions, recording them before the SDK can catch them. It covers array, empty, scalar, recursive and subclassed Eel contexts, a custom depth limit, ordinary stringable objects, the existing request serializer, and the SDK exception stack builder.

To compare another serializer revision using the same checks, pass its PHP file as the first argument. No PHPUnit dependency or Flow/database bootstrap is required.
