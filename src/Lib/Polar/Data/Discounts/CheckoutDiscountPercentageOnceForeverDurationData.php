<?php

namespace Livewirez\Billing\Lib\Polar\Data\Discounts;

use Livewirez\Billing\Lib\Polar\Data;


class CheckoutDiscountPercentageOnceForeverDurationData extends DiscountData
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $code,
        public readonly string $name,
        /**
         * Available options: `once`, `forever`, `repeating`
         */
        public readonly string $duration,
        /**
         * Available options: `fixed`, `percentage`
         */
        public readonly int $basisPoints,
        /**
        * The ID of the object.
        */
        public readonly string $createdAt,
        public readonly ?string $modifiedAt,
        public readonly ?string $endsAt,
        public readonly ?string $startsAt,
        public readonly ?int $maxRedemptions,
        public readonly int $redemptionsCount,
        /** @var array<DiscountProductData|array> */
        public readonly array $products = [],
        public readonly array $metadata = []
    ) {
        
    }
}
