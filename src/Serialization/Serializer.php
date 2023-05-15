<?php

namespace PHPLRPM\Serialization;
interface Serializer
{
    public static function serialize($data): string;

    public static function deserialize(string $serializedData);
}
