<?php

namespace PHPLRPM;

class Serialization
{
    public static function serialize($data): string
    {
        return json_encode($data);
    }

    public static function deserialize(string $serializedData)
    {
        return json_decode($serializedData, true);
    }
}
