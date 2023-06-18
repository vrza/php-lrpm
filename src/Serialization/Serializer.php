<?php

namespace PHPLRPM\Serialization;

interface Serializer
{
    public function serialize($data): string;

    /**
     * @throws SerializationException
     */
    public function deserialize(string $serializedData);
}
