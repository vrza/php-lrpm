<?php

namespace PHPLRPM;

class Serialization
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
