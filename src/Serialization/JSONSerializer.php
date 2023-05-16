<?php

namespace PHPLRPM\Serialization;

class JSONSerializer implements Serializer
{
    public function serialize($data): string
    {
        return json_encode($data);
    }

    public function deserialize(string $serializedData)
    {
        return json_decode($serializedData, true);
    }
}
