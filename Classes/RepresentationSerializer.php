<?php
declare(strict_types=1);

namespace Flownative\Sentry;

/*
 * This file is part of the Flownative.Sentry package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use GuzzleHttp\Psr7\Response;
use Neos\Flow\Cli\Request as CliRequest;
use Neos\Flow\Mvc\ActionRequest;
use Psr\Http\Message\RequestInterface;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Options;

/**
 * Serializes a value into a representation that should reasonably suggest
 * both the type and value, and be serializable into JSON.
 *
 * First is tries to use specialized serializers (see getClassSerializers),
 * then __toString(), then json_serialize(), if those are supported. As a
 * last resort foreach is used to iterate over object properties.
 *
 * Objects of a redacted type are shown only by their class and the shape of
 * their string or JSON form, wherever they are found.
 */
class RepresentationSerializer extends \Sentry\Serializer\RepresentationSerializer
{
    private int $maxDepth;

    /**
     * @var list<string>
     */
    private array $redactedTypes = [];

    public function __construct(Options $options, int $maxDepth = 3, ?string $mbDetectOrder = null)
    {
        parent::__construct($options, $maxDepth, $mbDetectOrder);
        $this->maxDepth = $maxDepth;
    }

    /**
     * @param list<string> $redactedTypes classes or interfaces, subclasses and implementers included
     */
    public function setRedactedTypes(array $redactedTypes): void
    {
        $this->redactedTypes = $redactedTypes;
    }

    /**
     * @return list<string> the configured types which are no class or interface, so they match nothing
     */
    public function unknownRedactedTypes(): array
    {
        return array_values(array_filter(
            $this->redactedTypes,
            static fn (string $type): bool => !class_exists($type) && !interface_exists($type)
        ));
    }

    /**
     * Applies the redacted types to what the SDK would otherwise encode as it is: extra data, contexts and breadcrumbs.
     * On a failure the data stays out of the event, and the error being reported still goes out.
     */
    public function redactEvent(Event $event): Event
    {
        if ($this->redactedTypes === []) {
            return $event;
        }

        try {
            $event->setExtra($this->redact($event->getExtra()));
            foreach ($event->getContexts() as $name => $context) {
                $event->setContext((string)$name, $this->redact($context));
            }
            $event->setBreadcrumb(array_map(function (Breadcrumb $breadcrumb): Breadcrumb {
                foreach ($breadcrumb->getMetadata() as $name => $value) {
                    $breadcrumb = $breadcrumb->withMetadata((string)$name, $this->redact($value));
                }

                return $breadcrumb;
            }, $event->getBreadcrumbs()));
        } catch (\Throwable) {
            $event->setExtra([]);
            foreach (array_keys($event->getContexts()) as $name) {
                $event->setContext((string)$name, ['__redaction' => 'failed']);
            }
            $event->setBreadcrumb([]);
        }

        return $event;
    }

    /**
     * @return array|float|bool|int|string|null
     */
    protected function serializeRecursively($value, int $_depth = 0): mixed
    {
        if (is_object($value) && $this->isRedacted($value)) {
            return $this->redactedRepresentation($value);
        }

        return parent::serializeRecursively($value, $_depth);
    }

    /**
     * @return array|float|bool|int|string|null
     */
    protected function serializeObject($object, int $_depth = 0, array $hashes = []): mixed
    {
        if ($this->isRedacted($object)) {
            return $this->redactedRepresentation($object);
        }

        if ($_depth >= $this->maxDepth || \in_array(spl_object_hash($object), $hashes, true)) {
            return $this->serializeValue($object);
        }

        // Try each serializer until there is none left or the serializer returned data
        $classSerializers = $this->getClassSerializers();
        foreach ($classSerializers as $targetType => $classSerializer) {
            if ($object instanceof $targetType) {
                $serializedObject = $this->serializeRecursively($classSerializer($object), $_depth + 1);
                $serializedObject['__class'] = get_class($object);
                return $serializedObject;
            }
        }

        if ($object instanceof \Stringable || is_callable([$object, '__toString'])) {
            $serializedObject = [
                '__class' => get_class($object),
                '__string' => (string)$object
            ];
        } elseif ($object instanceof \JsonSerializable) {
            try {
                $serializedObject = [
                    '__class' => get_class($object),
                    '__json' => json_encode($object, JSON_THROW_ON_ERROR)
                ];
            } catch (\JsonException $e) {
                $serializedObject = [
                    '__class' => get_class($object),
                    '__serialization_error' => $e->getMessage()
                ];
            }
        } else {
            $data = [];
            $hashes[] = spl_object_hash($object);

            foreach ($object as $key => $value) {
                if (is_object($value)) {
                    $data[$key] = $this->serializeObject($value, $_depth + 1, $hashes);
                } else {
                    $data[$key] = $this->serializeRecursively($value, $_depth + 1);
                }
            }

            $serializedObject = $data;
            $serializedObject['__class '] = get_class($object);
        }

        return $serializedObject;
    }

    private function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->redact(...), $value);
        }

        return is_object($value) ? $this->serializeRecursively($value) : $value;
    }

    private function isRedacted(object $object): bool
    {
        foreach ($this->redactedTypes as $redactedType) {
            if ($object instanceof $redactedType) {
                return true;
            }
        }

        return false;
    }

    private function redactedRepresentation(object $object): array
    {
        $representation = ['__class' => get_class($object)];
        try {
            if ($object instanceof \Stringable || is_callable([$object, '__toString'])) {
                $representation['__shape'] = StringShape::of((string)$object);
            } elseif ($object instanceof \JsonSerializable) {
                $representation['__shape'] = StringShape::of(json_encode($object, JSON_THROW_ON_ERROR));
            }
        } catch (\Throwable) {
            // The class alone says enough, and an error here must not replace the one being reported.
        }

        return $representation;
    }

    private function getClassSerializers(): array
    {
        return [
            RequestInterface::class => function (RequestInterface $request): array {
                return [
                    'method' => $request->getMethod(),
                    'uri' => (string)$request->getUri()
                ];
            },
            Response::class => function (Response $response): array {
                return [
                    'Status code' => $response->getStatusCode(),
                    'Headers' => $response->getHeaders()
                ];
            },
            ActionRequest::class => function (ActionRequest $request): array {
                return [
                    'Package' => $request->getControllerPackageKey(),
                    'Subpackage' => $request->getControllerSubpackageKey(),
                    'Controller' => $request->getControllerName(),
                    'Action' => $request->getControllerActionName(),
                ];
            },
            CliRequest::class => function (CliRequest $request): array {
                return [
                    'Controller' => $request->getControllerObjectName(),
                    'Command' => $request->getControllerCommandName()
                ];
            }
        ];
    }

}
