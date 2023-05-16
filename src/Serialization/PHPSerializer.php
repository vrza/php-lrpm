<?php

namespace PHPLRPM\Serialization;

class PHPSerializer implements Serializer
{
    public function serialize($data): string
    {
        return serialize($data);
    }

    public function deserialize(string $serializedData)
    {
        return unserialize($serializedData);
    }
}
