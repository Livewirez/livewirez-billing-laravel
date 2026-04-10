<?php

namespace Livewirez\Billing\Lib\Polar\Data\Products;



class ProductPriceFixedData extends ProductPriceData
{
    public function __construct(
        /**
         * Allowed value: `"fixed"`
         */
        public readonly string $amountType,
        /**
         * The currency.
         */
        public readonly string $priceCurrency,
        /**
         * The price in cents.
         */
        public readonly int $priceAmount,

        /**
         * The ID of the price.
         */
        string $id,
        /**
         * Whether the price is archived and no longer available.
         */
        bool $isArchived,
        /**
         * The ID of the product owning the price.
         */
        string $productId,
        /**
         * The type of the price.
         *
         * Allowed value: `"one_time"`, `"recurring"`
         */
        string $type,

        /**
         * Creation timestamp of the object.
         */
        string $createdAt,

        bool $legacy = false,
        /**
         * Last modification timestamp of the object.
         */
        ?string $modifiedAt = null,
        /**
         * The recurring interval of the price.
         *
         * Available options: `month`, `year`
         *
         * @deprecated message
         */
        ?string $recurringInterval = null,
    ) {
        parent::__construct(
            createdAt: $createdAt,
            modifiedAt: $modifiedAt,
            id: $id,
            isArchived: $isArchived,
            productId: $productId,
            type: $type,
            recurringInterval: $recurringInterval,
            legacy: $legacy
        );
    }
}
