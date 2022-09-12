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

use Sentry\Options;

/**
 * Serializes a value into a representation that should reasonably suggest
 * both the type and value, and be serializable into JSON.
 *
 * It kicks in if the parent's serializeObject() return an empty array, i.e.
 * no useful information could be extracted from simply iterating over the
 * object's properties…
 *
 * First is tries to use __toString(), then json_serialize(), if those are
 * supported.
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
}
