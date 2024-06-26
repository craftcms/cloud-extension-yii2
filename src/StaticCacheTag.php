<?php

namespace craft\cloud;

class StaticCacheTag implements \Stringable
{
    public readonly string $originalValue;
    private bool $minify = true;

    public function __construct(
        private string $value,
    ) {
        $this->originalValue = $value;
    }

    public static function create(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->getValue();
    }

    public function getValue(): string
    {
        $this->removeInvalidCharacters();

        if ($this->minify) {
            return $this
                ->hash()
                ->withPrefix(Module::getInstance()->getConfig()->getShortEnvironmentId())
                ->value;
        }

        return $this->value;
    }

    public function withPrefix(string $prefix): self
    {
        $this->value = $prefix . $this->value;

        return $this;
    }

    public function minify(bool $minify = true): self
    {
        $this->minify = $minify;

        return $this;
    }

    private function removeInvalidCharacters(): self
    {
        // Filter non-ASCII characters and asterisks, as these will tags end up in headers.
        // Asterisks should be valid, but Lambda mysteriously dies
        // with a 502 if they're present in the value of a response header.
        $this->value = preg_replace('/[^\x00-\x7F]|\*/', '', $this->value);

        return $this;
    }

    private function hash(): self
    {
        $this->value = sprintf('%x', crc32($this->value));

        return $this;
    }
}
