<?php

namespace PHPLRPM\Serialization;

class PHPSerializer implements Serializer
{
    public function serialize($data): string
    {
        return serialize($data);
    }

    /**
     * @throws SerializationException
     */
    public function deserialize(string $serializedData)
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            if ($errno === E_NOTICE) {
                throw new InternalSerializationException($errstr);
            }
            return true;
        });
        try {
            return unserialize($serializedData);
        } catch (InternalSerializationException $e) {
            throw new SerializationException($e->getMessage());
        } finally {
            restore_error_handler();
        }
    }
}
