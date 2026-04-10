<?php

namespace Livewirez\Billing\Lib\Polar\Data\Subscriptions;

use Livewirez\Billing\Lib\Polar\Data;
use Livewirez\Billing\Lib\Polar\Data\Users\UserData;
use Livewirez\Billing\Lib\Polar\Enums\RecurringInterval;
use Livewirez\Billing\Lib\Polar\Enums\SubscriptionStatus;
use Livewirez\Billing\Lib\Polar\Data\Products\ProductData;
use Livewirez\Billing\Lib\Polar\Data\Customers\CustomerData;
use Livewirez\Billing\Lib\Polar\Enums\CustomerCancellationReason;
use Livewirez\Billing\Lib\Polar\Data\Products\ProductPriceFreeData;
use Livewirez\Billing\Lib\Polar\Data\Products\ProductPriceFixedData;
use Livewirez\Billing\Lib\Polar\Data\Products\ProductPriceCustomData;
use Livewirez\Billing\Lib\Polar\Data\Products\LegacyRecurringProductPriceFreeData;
use Livewirez\Billing\Lib\Polar\Data\Products\LegacyRecurringProductPriceFixedData;
use Livewirez\Billing\Lib\Polar\Data\Products\LegacyRecurringProductPriceCustomData;
use Livewirez\Billing\Lib\Polar\Data\Discounts\CheckoutDiscountFixedRepeatDurationData;
use Livewirez\Billing\Lib\Polar\Data\Discounts\CheckoutDiscountFixedOnceForeverDurationData;
use Livewirez\Billing\Lib\Polar\Data\Discounts\CheckoutDiscountPercentageRepeatDurationData;
use Livewirez\Billing\Lib\Polar\Data\Discounts\CheckoutDiscountPercentageOnceForeverDurationData;


class SubscriptionData extends Data
{
    public function __construct(
        /**
         * Creation timestamp of the object.
         */
        public readonly string $createdAt,
        /**
         * Last modification timestamp of the object.
         */
        public readonly ?string $modifiedAt,
        /**
         * The ID of the subscription.
         */
        public readonly string $id,
        /**
         * The amount of the subscription.
         */
        public readonly ?int $amount,
        /**
         * The currency of the subscription.
         */
        public readonly ?string $currency,
        /**
         * The interval at which the subscription recurs.
         *
         * Available options: `month`, `year`
         */
        public readonly RecurringInterval $recurringInterval,
        /**
         * The status of the subscription.
         *
         * Available options: `incomplete`, `incomplete_expired`, `trialing`, `active`, `past_due`, `canceled`, `unpaid`
         */
        public readonly SubscriptionStatus $status,
        /**
         * The start timestamp of the current billing period.
         */
        public readonly string $currentPeriodStart,
        /**
         * The end timestamp of the current billing period.
         */
        public readonly ?string $currentPeriodEnd,
        /**
         * Whether the subscription will be canceled at the end of the current period.
         */
        public readonly bool $cancelAtPeriodEnd,
        /**
         * The timestamp when the subscription was canceled. The subscription might still be active if `cancel_at_period_end` is `true`.
         */
        public readonly ?string $canceledAt,
        /**
         * The timestamp when the subscription started.
         */
        public readonly ?string $startedAt,
        /**
         * The timestamp when the subscription will end.
         */
        public readonly ?string $endsAt,
        /**
         * The timestamp when the subscription ended.
         */
        public readonly ?string $endedAt,
        /**
         * The ID of the subscribed customer.
         */
        public readonly string $customerId,
        /**
         * The ID of the subscribed product.
         */
        public readonly string $productId,
        /**
         * The ID of the applied discount, if any.
         */
        public readonly ?string $discountId,
        public readonly ?string $checkoutId,
        /**
         * Available options: `customer_service`, `low_quality`, `missing_features`, `switched_service`, `too_complex`, `too_expensive`, `unused`, `other`
         */
        public readonly ?CustomerCancellationReason $customerCancellationReason,
        public readonly ?string $customerCancellationComment,
        /** @var array<string, string|int|bool> */
        public readonly array $metadata,
        public readonly CustomerData $customer,
        /** @deprecated */
        public readonly string $userId,
        public readonly UserData $user,
        /**
         * A product.
         */
        public readonly ProductData $product,
        /**
         * A recurring price for a product, i.e. a subscription.
         *
         * @deprecated The recurring interval should be set on the product itself.
         */
        public readonly LegacyRecurringProductPriceFixedData|LegacyRecurringProductPriceCustomData|LegacyRecurringProductPriceFreeData|ProductPriceFixedData|ProductPriceCustomData|ProductPriceFreeData $price,
        public readonly CheckoutDiscountFixedOnceForeverDurationData|CheckoutDiscountFixedRepeatDurationData|CheckoutDiscountPercentageOnceForeverDurationData|CheckoutDiscountPercentageRepeatDurationData|null $discount,
        /** @var array<string, string|int|bool|\DateTime|null> */
        public readonly array $customFieldData,
    ) {}
}
