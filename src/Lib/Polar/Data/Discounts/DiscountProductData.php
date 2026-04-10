<?php 

namespace Livewirez\Billing\Lib\Polar\Data\Discounts;

use Livewirez\Billing\Lib\Polar\Data;


class DiscountProductData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $description,
        
        public readonly string $recurringInterval,
        /**
         * Available options: `fixed`, `percentage`
         */
        
        public readonly bool $isRecurring,
        /**
        * The ID of the object.
        */
    
        public readonly bool $isArchived,
        public readonly string $createdAt,
        public readonly ?string $modifiedAt,
        public readonly array $metadata = []
    ) {
    }
}