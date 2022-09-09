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
    protected function serializeObject($object, int $_depth = 0, array $hashes = [])
    {
        $serializedObject = parent::serializeObject($object, $_depth, $hashes);
        if ($serializedObject === []) {
            if ($object instanceof \Stringable || is_callable([$object, '__toString'])) {
                $serializedObject = [
                    'type' => get_class($object),
                    'value (as string)' => (string)$object
                ];
            } elseif ($object instanceof \JsonSerializable) {
                try {
                    $serializedObject = [
                        'type' => get_class($object),
                        'value (as JSON)' => json_encode($object, JSON_THROW_ON_ERROR)
                    ];
                } catch (\JsonException $e) {
                    $serializedObject = 'Object ' . get_class($object);
                }
            }

            return $serializedObject;
        }
    }
}
