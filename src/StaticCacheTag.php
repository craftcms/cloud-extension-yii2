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
        if ($this->minify && !Module::getInstance()->getConfig()->getDevMode()) {
            return $this
                ->removeNonPrintableChars()
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

    private function removeNonPrintableChars(): self
    {
        $this->value = preg_replace('/[^[:print:]]/', '', $this->value);

        return $this;
    }

    private function hash(): self
    {
        $this->value = sprintf('%x', crc32($this->value));

        return $this;
    }
}
