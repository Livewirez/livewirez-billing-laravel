<?php

namespace Livewirez\Billing\Lib\Polar\Data\Discounts;

use Livewirez\Billing\Lib\Polar\Data;


abstract class DiscountData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $code,
        public readonly array $metadata = []
    ) {}
}