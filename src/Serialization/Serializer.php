<?php

namespace PHPLRPM\Serialization;

interface Serializer
{
    public function serialize($data): string;

    public function deserialize(string $serializedData);
}
