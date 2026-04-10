<?php

namespace Livewirez\Billing\Lib;

use JsonSerializable;

class BillingPlanFeatures implements JsonSerializable
{
    public function __construct(protected array $attributes = [])
    {

    }

    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __get(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }
}