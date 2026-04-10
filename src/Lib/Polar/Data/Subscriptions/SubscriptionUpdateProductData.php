<?php

namespace Livewirez\Billing\Lib\Polar\Data\Subscriptions;

use Livewirez\Billing\Lib\Polar\Data;
use Livewirez\Billing\Lib\Polar\Enums\ProrationBehavior;

class SubscriptionUpdateProductData extends Data
{
    public function __construct(
        /**
         * Update subscription to another product.
         */
        public readonly string $productId,
        /**
         * Determine how to handle the proration billing. If not provided, will use the default organization setting.
         *
         * Available options: `invoice`, `prorate`
         */
        public readonly ?ProrationBehavior $prorationBehavior,
    ) {}
}
