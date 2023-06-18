<?php

namespace PHPLRPM\Serialization;

class JSONSerializer implements Serializer
{
    public function serialize($data): string
    {
        return json_encode($data);
    }

    /**
     * @throws SerializationException
     */
    public function deserialize(string $serializedData)
    {
        $result = json_decode($serializedData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new SerializationException(json_last_error_msg());
        }
        return $result;
    }
}
