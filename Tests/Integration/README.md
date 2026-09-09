# Representation serializer regression test

Run inside an existing Neos distribution with `neos/eel` and `sentry/sentry` installed. The package does not need a configured DSN. The test builds an SDK exception stack, invokes the package’s event assembler, and captures the assembled event using an SDK client with an in-memory transport and default integrations disabled. It makes no network calls.

```sh
ddev exec php Packages/Application/Flownative.Sentry/Tests/Integration/RepresentationSerializerTest.php
```

The script exits nonzero on failures and treats PHP warnings as exceptions, recording them before the SDK can catch them. It covers array, empty, scalar, recursive and subclassed Eel contexts, nested arrays and objects on both sides of a custom depth boundary, ordinary stringable objects, the existing request serializer, the SDK exception stack builder, and the captured exception chain’s types, messages, and attached Eel argument.

To compare another serializer revision using the same checks, pass its PHP file as the first argument. The event-assembly check uses reflection to supply the stacktrace builder and invoke the private assembler without Flow services; it does not exercise the full public capture/scope lifecycle. No PHPUnit dependency or Flow/database bootstrap is required.
