<?php

namespace craft\cloud;

class Cache extends \yii\redis\Cache
{
    /**
     * gzip compression level, null to disable
     */
    public ?int $gzipLevel = 9;

    public const GZIP_PREFIX = 'gz:';

    public function __construct($config = [])
    {
        parent::__construct($config + [
            'serializer' => [
                [$this, 'serializeValue'],
                [$this, 'unserializeValue'],
            ],
        ]);
    }

    private function serializeValue($value): string
    {
        $serialized = serialize($value);

        if ($this->gzipLevel === null) {
            return $serialized;
        }

        $compressed = gzencode($serialized, 9);

        return self::GZIP_PREFIX . $compressed;
    }

    private function unserializeValue($value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $prefixLength = strlen(self::GZIP_PREFIX);
        $isCompressed = strncmp($value, self::GZIP_PREFIX, $prefixLength) === 0;

        $decompressed = $isCompressed
            ? gzdecode(substr($value, $prefixLength))
            : $value;

        return unserialize($decompressed);
    }
}
