<?php

namespace Livewirez\Billing\Lib\Polar\Data\Products;



class ProductPriceCustomData extends ProductPriceData
{
    public function __construct(
        /**
         * Allowed value: `"custom"`
         */
        public readonly string $amountType,
        /**
         * The minimum amount the customer can pay.
         */
        public readonly ?int $minimumAmount,
        /**
         * The maximum amount the customer can pay.
         */
        public readonly ?int $maximumAmount,
        /**
         * The initial amount shown to the customer.
         */
        public readonly ?int $presetAmount,
    ) {}
}
