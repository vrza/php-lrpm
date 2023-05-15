<?php

namespace PHPLRPM\Serialization;

class PHPSerializer implements Serializer
{
    public static function serialize($data): string
    {
        return serialize($data);
    }

    public static function deserialize(string $serializedData)
    {
        return unserialize($serializedData);
    }
}
