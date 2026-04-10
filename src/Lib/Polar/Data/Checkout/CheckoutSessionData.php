<?php

namespace Livewirez\Billing\Lib\Polar\Data\Checkout;

use DateTime;
use Livewirez\Billing\Lib\Polar\Data;
use Livewirez\Billing\Lib\Polar\Data\Products\ProductData;
use Livewirez\Billing\Lib\Polar\Data\Products\ProductPriceFreeData;
use Livewirez\Billing\Lib\Polar\Data\Products\ProductPriceFixedData;
use Livewirez\Billing\Lib\Polar\Data\Customers\CustomerBillingAddressData;
use Livewirez\Billing\Lib\Polar\Data\Products\ProductPriceCustomData;
use Livewirez\Billing\Lib\Polar\Data\Products\LegacyRecurringProductPriceFreeData;
use Livewirez\Billing\Lib\Polar\Data\Products\LegacyRecurringProductPriceFixedData;
use Livewirez\Billing\Lib\Polar\Data\Products\LegacyRecurringProductPriceCustomData;
use Livewirez\Billing\Lib\Polar\Data\Discounts\CheckoutDiscountFixedRepeatDurationData;
use Livewirez\Billing\Lib\Polar\Data\Discounts\CheckoutDiscountFixedOnceForeverDurationData;
use Livewirez\Billing\Lib\Polar\Data\Discounts\CheckoutDiscountPercentageRepeatDurationData;
use Livewirez\Billing\Lib\Polar\Data\Discounts\CheckoutDiscountPercentageOnceForeverDurationData;

class CheckoutSessionData extends Data
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
         * The ID of the object.
         */
        public readonly string $id,
        /**
         * Payment processor used.
         *
         * Available options: `stripe`
         */
        public readonly string $paymentProcessor,
        /**
         * Status of the checkout session.
         *
         * Available options: `open`, `expired`, `confirmed`, `succeeded`, `failed`
         */
        public readonly string $status,
        /**
         * Client secret used to update and complete the checkout session from the client.
         */
        public readonly string $clientSecret,
        /**
         * URL where the customer can access the checkout session.
         */
        public readonly string $url,
        /**
         * Expiration date and time of the checkout session.
         */
        public readonly string $expiresAt,
        /**
         * URL where the customer will be redirected after a successful payment.
         */
        public readonly string $successUrl,
        /**
         * When checkout is embedded, represents the Origin of the page embedding the checkout.
         * Used as a security measure to send messages only to the embedding page.
         */
        public readonly ?string $embedOrigin,
        /**
         * Amount to pay in cents. Only useful for custom prices,
         * it'll be ignored for fixed and free prices.
         *
         * Required range: `50 <= x <= 99999999`
         */
        public readonly ?int $amount,
        /**
         * Computed tax amount to pay in cents.
         */
        public readonly ?int $taxAmount,
        /**
         * Currency code of the checkout session.
         */
        public readonly ?string $currency,
        /**
         * Subtotal amount in cents, including discounts and before tax.
         */
        public readonly ?int $subtotalAmount,
        /**
         * Total amount to pay in cents, including discounts and after tax.
         */
        public readonly ?int $totalAmount,
        /**
         * ID of the product to checkout.
         */
        public readonly ?string $productId,
        /**
         * ID of the discount applied to the checkout.
         */
        public readonly ?string $discountId,
        /**
         * Whether to allow the customer to apply discount codes.
         * If you apply a discount through discount_id, it'll still be applied,
         * but the customer won't be able to change it.
         */
        public readonly bool $allowDiscountCodes,
        /**
         * Whether the discount is applicable to the checkout.
         * Typically, free and custom prices are not discountable.
         */
        public readonly bool $isDiscountApplicable,
        /**
         * Whether the product price is free, regardless of discounts.
         */
        public readonly ?bool $isFreeProductPrice,
        /**
         * Whether the checkout requires payment, e.g. in case of free products or discounts that cover the total amount.
         */
        public readonly bool $isPaymentRequired,
        /**
         * Whether the checkout requires setting up a payment method, regardless of the amount, e.g. subscriptions that have first free cycles.
         */
        public readonly bool $isPaymentSetupRequired,
        /**
         * Whether the checkout requires a payment form, whether because of a payment or payment method setup.
         */
        public readonly bool $isPaymentFormRequired,
        /**
         * ID of an existing customer in the organization.
         */
        public readonly ?string $customerId,
        /**
         * ID of the customer in your system.
         */
        public readonly ?string $customerExternalId,
        /**
         * Name of the customer.
         */
        public readonly ?string $customerName,
        /**
         * Email of the customer.
         */
        public readonly ?string $customerEmail,
        /**
         * IP address of the customer.
         */
        public readonly ?string $customerIpAddress,
        
        /**
         * Tax ID of the customer.
         */
        public readonly ?string $customerTaxId,
        /** @var array<string, string> */
        public readonly array $paymentProcessorMetadata,
        /**
         * Key-value object allowing you to store additional information.
         *
         * The key must be a string with a maximum length of **40 characters**. The value must be either:
         *
         * - A string with a maximum length of **500 characters**
         * - An integer
         * - A boolean
         *
         * You can store up to **50 key-value pairs**.
         *
         * @var array<string, string|int>|null
         */
        public readonly ?array $metadata,
        /**
         * List of products available to select.
         *
         * Product data for a checkout session.
         *
         * @var array<ProductData>
         */
        public readonly array $products,
        /**
         * Product selected to checkout.
         */
        public readonly ProductData $product,
       
        public readonly ?string $subscriptionId,
        /**
         * Schema of a custom field attached to a resource.
         *
         * @var array<AttachedCustomFieldData>
         */
        public readonly array $attachedCustomFields,
        /** @var array<string, string|int|bool> */
        public readonly array $customerMetadata,
        /** @var array<string, string|int|bool|\DateTime|null> */
        public readonly ?array $customFieldData,
        /**
         * Billing address of the customer.
         */
        public readonly ?CustomerBillingAddressData $customerBillingAddress = null,
         /**
         * Price of the selected product.
         */
        public readonly LegacyRecurringProductPriceFixedData|LegacyRecurringProductPriceCustomData|LegacyRecurringProductPriceFreeData|ProductPriceFixedData|ProductPriceCustomData|ProductPriceFreeData|null $productPrice = null,
        /**
         * Schema for a percentage discount that is applied once or forever.
         */
        public readonly CheckoutDiscountFixedOnceForeverDurationData|CheckoutDiscountFixedRepeatDurationData|CheckoutDiscountPercentageOnceForeverDurationData|CheckoutDiscountPercentageRepeatDurationData|null $discount = null,
    ) {
        //
    }
}