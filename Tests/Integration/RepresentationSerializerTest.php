<?php
declare(strict_types=1);

// Run from a Neos distribution: php <package>/Tests/Integration/RepresentationSerializerTest.php
$loader = require getcwd() . '/Packages/Libraries/autoload.php';
$loader->addPsr4('Neos\\Eel\\', getcwd() . '/Packages/Framework/Neos.Eel/Classes');
require $argv[1] ?? dirname(__DIR__, 2) . '/Classes/RepresentationSerializer.php';

use Flownative\Sentry\RepresentationSerializer;
use Neos\Eel\Context;
use Sentry\Options;
use Sentry\StacktraceBuilder;

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
    $check($shallowSerializer->representationSerialize($context) === 'Object ' . Context::class, 'custom depth is respected');

    $stringable = new class {
        public function __toString(): string
        {
            return 'normal string';
        }
    };
    $check($serializer->representationSerialize($stringable) === ['__class' => get_class($stringable), '__string' => 'normal string'], 'ordinary stringable objects retain existing behavior');

    $request = new GuzzleHttp\Psr7\Request('GET', 'https://example.invalid/fixture');
    $check($serializer->representationSerialize($request) === ['method' => 'GET', 'uri' => 'https://example.invalid/fixture', '__class' => get_class($request)], 'existing request serializer retains behavior');

    // The SDK must retain the original exception and its arguments without a secondary warning.
    ini_set('zend.exception_ignore_args', '0');
    $throwWithContext = static function (Context $context): never {
        throw new RuntimeException('Original fixture failure');
    };
    try {
        $throwWithContext($context);
    } catch (RuntimeException $exception) {
        $builder = new StacktraceBuilder(new Options([]), $serializer);
        $stacktrace = $builder->buildFromException($exception);
        $arguments = array_merge(...array_map(static fn ($frame): array => $frame->getVars(), $stacktrace->getFrames()));
        $check(in_array('Object ' . Context::class, $arguments, true), 'SDK exception stack retains the Eel argument');
        $check($exception->getMessage() === 'Original fixture failure', 'original exception remains available');
    }
    $check($warnings === [], 'all fixtures avoid secondary PHP warnings');
} finally {
    restore_error_handler();
}

echo sprintf('%d checks, %d failures, %d warnings%s', $checks, $failures, count($warnings), PHP_EOL);
exit($failures === 0 ? 0 : 1);
