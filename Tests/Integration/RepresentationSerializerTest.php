<?php
declare(strict_types=1);

// Run from a Neos distribution: php <package>/Tests/Integration/RepresentationSerializerTest.php
$loader = require getcwd() . '/Packages/Libraries/autoload.php';
$loader->addPsr4('Neos\\Eel\\', getcwd() . '/Packages/Framework/Neos.Eel/Classes');
$loader->addPsr4('Flownative\\Sentry\\', dirname(__DIR__, 2) . '/Classes', true);
require $argv[1] ?? dirname(__DIR__, 2) . '/Classes/RepresentationSerializer.php';

use Flownative\Sentry\RepresentationSerializer;
use Flownative\Sentry\SentryClient;
use Neos\Eel\Context;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Options;
use Sentry\StacktraceBuilder;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

$warnings = [];
set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$warnings): never {
    $warnings[] = $message;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$serializer = new RepresentationSerializer(new Options([]));
$serializer->setSerializeAllObjects(true);
$failures = 0;
$checks = 0;
$check = static function (bool $condition, string $name) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $name . PHP_EOL);
    }
};

try {
    $context = new Context(['fixture' => 'value']);
    $result = $serializer->representationSerialize($context);
    $check($warnings === [], 'array-valued context emits no PHP warnings');
    $check($result === 'Object ' . Context::class, 'array-valued context retains its class description');

    foreach ([[], 'text', 42, false, null] as $value) {
        $result = $serializer->representationSerialize(new Context($value));
        $check($result === 'Object ' . Context::class, 'empty and scalar contexts retain class descriptions');
    }

    $subclass = new class(['fixture' => 'value']) extends Context {
        public function __toString(): string
        {
            throw new LogicException('Context string conversion must not be invoked');
        }
    };
    $result = $serializer->representationSerialize($subclass);
    $check($result === 'Object ' . get_class($subclass), 'context subclasses bypass string conversion');

    $cycle = new Context([]);
    $cycle->push($cycle);
    $result = $serializer->representationSerialize($cycle);
    $check($result === 'Object ' . Context::class, 'recursive contexts are not evaluated');

    $shallowSerializer = new RepresentationSerializer(new Options([]), 1);
    $shallowSerializer->setSerializeAllObjects(true);
    $deeperSerializer = new RepresentationSerializer(new Options([]), 2);
    $deeperSerializer->setSerializeAllObjects(true);
    $nestedArray = ['nested' => ['leaf' => 'value']];
    $check($shallowSerializer->representationSerialize($nestedArray) === ['nested' => 'Array of length 1'], 'nested array stops at depth one');
    $check($deeperSerializer->representationSerialize($nestedArray) === $nestedArray, 'nested array expands at depth two');

    $stringable = new class {
        public function __toString(): string
        {
            return 'normal string';
        }
    };
    $check($serializer->representationSerialize($stringable) === ['__class' => get_class($stringable), '__string' => 'normal string'], 'ordinary stringable objects retain existing behavior');

    $nestedObject = (object)['child' => $stringable];
    $shallowObject = $shallowSerializer->representationSerialize($nestedObject);
    $deeperObject = $deeperSerializer->representationSerialize($nestedObject);
    $check(is_array($shallowObject) && ($shallowObject['child'] ?? null) === 'Object ' . get_class($stringable), 'nested object stops at depth one');
    $check(is_array($deeperObject) && ($deeperObject['child'] ?? null) === ['__class' => get_class($stringable), '__string' => 'normal string'], 'nested object expands at depth two');

    $request = new GuzzleHttp\Psr7\Request('GET', 'https://example.invalid/fixture');
    $check($serializer->representationSerialize($request) === ['method' => 'GET', 'uri' => 'https://example.invalid/fixture', '__class' => get_class($request)], 'existing request serializer retains behavior');

    // The SDK must retain the original exception and its arguments without a secondary warning.
    ini_set('zend.exception_ignore_args', '0');
    $throwWithContext = static function (Context $context): never {
        throw new RuntimeException('Original fixture failure', 0, new LogicException('Previous fixture failure'));
    };
    try {
        $throwWithContext($context);
    } catch (RuntimeException $exception) {
        $builder = new StacktraceBuilder(new Options([]), $serializer);
        $stacktrace = $builder->buildFromException($exception);
        $arguments = array_merge(...array_map(static fn ($frame): array => $frame->getVars(), $stacktrace->getFrames()));
        $check(in_array('Object ' . Context::class, $arguments, true), 'SDK exception stack retains the Eel argument');

        // Exercise the package's event assembler without bootstrapping Flow or reading a DSN.
        $reflection = new ReflectionClass(SentryClient::class);
        $sentryClient = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('stacktraceBuilder')->setValue($sentryClient, $builder);
        $event = Event::createEvent();
        $reflection->getMethod('addThrowableToEvent')->invoke($sentryClient, $exception, $event);

        $transport = new class implements TransportInterface {
            public array $events = [];

            public function send(Event $event): Result
            {
                $this->events[] = $event;
                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };
        $sdkClient = ClientBuilder::create(['default_integrations' => false])->setTransport($transport)->getClient();
        $eventId = $sdkClient->captureEvent($event);
        $check(count($transport->events) === 1 && $eventId !== null, 'SDK delivers the assembled exception event to the in-memory transport');
        $capturedExceptions = ($transport->events[0] ?? null)?->getExceptions() ?? [];
        $exceptionDetails = array_map(static fn ($captured): array => [$captured->getType(), $captured->getValue()], $capturedExceptions);
        $check($exceptionDetails === [[RuntimeException::class, 'Original fixture failure'], [LogicException::class, 'Previous fixture failure']], 'captured event preserves original and previous exception types and messages');
        $capturedFrames = ($capturedExceptions[0] ?? null)?->getStacktrace()?->getFrames() ?? [];
        $capturedArguments = array_merge([], ...array_map(static fn ($frame): array => $frame->getVars(), $capturedFrames));
        $check(in_array('Object ' . Context::class, $capturedArguments, true), 'captured original exception has its Eel argument attached');
    }
    $check($warnings === [], 'all fixtures avoid secondary PHP warnings');
} finally {
    restore_error_handler();
}

echo sprintf('%d checks, %d failures, %d warnings%s', $checks, $failures, count($warnings), PHP_EOL);
exit($failures === 0 ? 0 : 1);
