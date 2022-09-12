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
use Sentry\Options;

/**
 * Serializes a value into a representation that should reasonably suggest
 * both the type and value, and be serializable into JSON.
 *
 * First is tries to use specialized serializers (see getClassSerializers),
 * then __toString(), then json_serialize(), if those are supported. As a
 * last resort foreach is used to iterate over object properties.
 */
class RepresentationSerializer extends \Sentry\Serializer\RepresentationSerializer
{
    private int $maxDepth;

    public function __construct(Options $options, int $maxDepth = 3, ?string $mbDetectOrder = null)
    {
        parent::__construct($options, $maxDepth, $mbDetectOrder);
        $this->maxDepth = $maxDepth;
    }

    protected function serializeObject($object, int $_depth = 0, array $hashes = []): array|float|bool|int|string|null
    {
        if ($_depth >= $this->maxDepth || \in_array(spl_object_hash($object), $hashes, true)) {
            return $this->serializeValue($object);
        }

        // Try each serializer until there is none left or the serializer returned data
        $classSerializers = $this->getClassSerializers();
        foreach ($classSerializers as $targetType => $classSerializer) {
            if ($object instanceof $targetType) {
                return [
                    'class' => get_class($object),
                    'data' => $this->serializeRecursively($classSerializer($object), $_depth + 1),
                ];
            }
        }

        if ($object instanceof \Stringable || is_callable([$object, '__toString'])) {
            $serializedObject = [
                'class' => get_class($object),
                'data' => (string)$object
            ];
        } elseif ($object instanceof \JsonSerializable) {
            try {
                $serializedObject = [
                    'class' => get_class($object),
                    'data' => json_encode($object, JSON_THROW_ON_ERROR)
                ];
            } catch (\JsonException $e) {
                $serializedObject = [
                    'class' => get_class($object),
                    'serialization error' => $e->getMessage()
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

            $serializedObject = [
                'class ' => get_class($object),
                'data' => $data
            ];
        }

        return $serializedObject;
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
