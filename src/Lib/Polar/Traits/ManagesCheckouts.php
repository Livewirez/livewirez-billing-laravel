<?php 

namespace Livewirez\Billing\Lib\Polar\Traits;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Lib\Polar\Checkout;
use Livewirez\Billing\Lib\Polar\Data\Customers\CustomerBillingAddressData;

trait ManagesCheckouts // @phpstan-ignore-line trait.unused - ManagesCheckouts is used in Billable trait
{
    /**
     * Create a new checkout instance to sell a product.
     *
     * @param  Billable $user
     * @param  array<string>  $products
     * @param  array<string, string|int>|null  $options
     * @param  array<string, string|int|bool>|null  $customerMetadata
     * @param  array<string, string|int|bool>|null  $metadata
     */
    public function checkout(Billable $user, array $products, array $options = [], array $customerMetadata = [], array $metadata = []): Checkout
    {
        $key = $user->getKey();

        // We'll need a way to identify the user in any webhook we're catching so before
        // we make an API request we'll attach the authentication identifier to this
        // checkout so we can match it back to a user when handling Polar webhooks.
        $customerMetadata = array_merge($customerMetadata, [
            'billable_id' => (string) $key,
            'billable_type' => $user->getMorphClass(),
        ]);

        /** @var CustomerBillingAddressData|null */
        $billingAddress = null;
        if (isset($options['country'])) {
            \Illuminate\Support\Facades\Log::debug('Isset Country');
            $billingAddress = CustomerBillingAddressData::from([
                'country' => (string) $options['country'],
                'line1' => isset($options['line1']) ? (string) $options['line1'] : null,
                'line2' => isset($options['line2']) ? (string) $options['line2'] : null,
                'postalCode' => isset($options['zip']) ? (string) $options['zip'] : null,
                'city' => isset($options['city']) ? (string) $options['city'] : null,
                'state' => isset($options['state']) ? (string) $options['state'] : null,
            ]);
        }

        $checkout = Checkout::make($products)
            ->withCustomerName((string) ($options['customer_name'] ?? $user->getName() ?? ''))
            ->withCustomerEmail((string) ($options['customer_email'] ?? $user->getEmail() ?? ''))
            ->withCustomerBillingAddress($billingAddress)
            ->withCustomerMetadata($customerMetadata)
            ->withMetadata($metadata)
            ->withCustomFieldData($options['custom_fields'] ?? []);

        if (isset($options['success_url'])) {
            $checkout->withSuccessUrl($options['success_url']);
        }

        if (isset($options['tax_id'])) {
            $checkout->withCustomerTaxId((string) $options['tax_id']);
        }

        if (isset($options['discount_id'])) {
            $checkout->withDiscountId((string) $options['discount_id']);
        }

        if (isset($options['amount']) && is_numeric($options['amount'])) {
            $checkout->withAmount((int) $options['amount']);
        }

        \Illuminate\Support\Facades\Log::debug(
            collect([
                'user' => $user->getKey(), 'products' => $products, 'options' => $options, 'customerMetadata' => $customerMetadata, 'metadata' => $metadata
            ]),
            ['Polar Checkout']
        );

        return $checkout;
    }

    /**
     * Create a new checkout instance to sell a product with a custom price.
     *
     * @param  Billable $user
     * @param  array<string>  $products
     * @param  array<string, string|int>|null  $options
     * @param  array<string, string|int|bool>|null  $customerMetadata
     * @param  array<string, string|int|bool>|null  $metadata
     */
    public function charge(Billable $user, int $amount, array $products, array $options = [], array $customerMetadata = [], array $metadata = []): Checkout
    {
        return $this->checkout($user, $products, array_merge($options, [
            'amount' => $amount,
        ]), $customerMetadata, $metadata);
    }

    /**
     * Subscribe the customer to a new plan variant.
     *
     * @param  Billable $user
     * @param  array<string, string|int>|null  $options
     * @param  array<string, string|int|bool>|null  $customerMetadata
     * @param  array<string, string|int|bool>|null  $metadata
     */
    public function subscribe(Billable $user, string $productId, string $type = "default", array $options = [], array $customerMetadata = [], array $metadata = []): Checkout
    {
        return $this->checkout($user, [$productId], $options, array_merge($customerMetadata, [
            'subscription_type' => $type,
        ]), $metadata);
    }
}