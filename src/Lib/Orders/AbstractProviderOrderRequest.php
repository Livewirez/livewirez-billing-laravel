<?php 

namespace Livewirez\Billing\Lib\Orders;

use DateTimeInterface;
use BadMethodCallException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewirez\Billing\Lib\Address;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Enums\ApiProductTypeKey;

use function count;
use function is_array;
use function in_array;

#[\AllowDynamicProperties]
abstract class AbstractProviderOrderRequest
{
    public ?Address $billingAddress = null;

    public function __construct(
        public Billable $user,
        public string $billingOrderId,
        public string $billingPaymenTransactionId,
        public string $orderNumber,
        public ?string $providerOrderId = null,
        public ?string $providerCheckoutId = null,
        public ?string $providerTransactionId = null,
        public ?string $billingSubscriptionId = null,
        public ?string $providerSubscriptionId = null,
        public ?string $billingSubscriptionTransactionId = null,
        public array $metadata = [],
        public ApiProductTypeKey $productType = ApiProductTypeKey::ONE_TIME,
    ) {
        if (
            $billingSubscriptionId === null && $productType === ApiProductTypeKey::SUBSCRIPTION
        ) throw new InvalidArgumentException(
            "'billingSubscriptionId' must be set if 'productType' is subscription"
        );
    }

    public function getUser(): Billable 
    {
        return $this->user;
    }

    public function getBillingOrderId(): string 
    {
        return $this->billingOrderId;
    }

    public function getBillingPaymenTransactionId(): string 
    {
        return $this->billingPaymenTransactionId;
    }

    public function getOrderNumber(): string 
    {
        return $this->orderNumber;
    }

    public function getProviderOrderId(): ?string
    {
        return $this->providerOrderId;
    }

    public function getProviderCheckoutId(): ?string 
    {
        return $this->providerCheckoutId;
    }

    public function getProviderTransactionId(): ?string 
    {
        return $this->providerTransactionId;
    }

    public function getMetadata(): array 
    {
        return $this->metadata;
    }

    public function getBillingSubscriptionId(): ?string
    {
        return $this->billingSubscriptionId;
    }

    public function getProviderSubscriptionId(): ?string 
    {
        return $this->providerSubscriptionId;
    }

    public function getBillingSubscriptionTransactionId(): ?string
    {
        return $this->billingSubscriptionTransactionId;
    }

    public function getProductType(): ApiProductTypeKey
    {
        return $this->productType;
    }

    public function getBillingAddress(): ?Address
    {
        return $this->billingAddress;
    }

    public function setBillingAddress(Address $address): static
    {
        $this->billingAddress = $address;

        return $this;
    }

    public function __call(
        string $method, array $parameters
    ) {

        if (str_starts_with($method, 'get')) {
            $prefix = substr($method, 0, 3); // 'get'
            $rest = substr($method, 3);
            
            $property = Str::camel($rest);

            if (property_exists($this, $property)) {
                return $this->{$property};
            }
        }

        throw new BadMethodCallException(
            "Method {$method} is not defined"
        );
    }


    public static function makeFromSpread(mixed ...$args): static
    {
        return new static(...$args);
    } 

    public static function from(mixed ...$args): static
    {
        if (count($args) === 1 && is_array($args[0])) {
            return static::fromArray($args[0]);
        }

        return static::makeFromSpread(...$args);
    }

    public static function fromArray(array $data): static
    {
        $instance = new static(
            user: $data['billable'] ?? $data['user'],
            billingOrderId: $data['billingOrderId'] ?? $data['billing_order_id'],
            billingPaymenTransactionId: $data['billingPaymenTransactionId'] ?? $data['billing_payment_transaction_id'],
            orderNumber: $data['orderNumber'] ?? $data['order_number'],
            providerOrderId: $data['providerOrderId'] ?? $data['provider_order_id'] ?? $data['payment_provider_order_id'] ?? null,
            providerCheckoutId: $data['providerCheckoutId'] ?? $data['provider_checkout_id'] ?? $data['payment_provider_checkout_id'] ?? null,
            providerTransactionId: $data['providerTransactionId'] ?? $data['provider_transaction_id'] ?? $data['payment_provider_transaction_id'] ?? null,
            metadata: $data['data'] ?? $data['metadata'] ?? [],
            billingSubscriptionId: $data['billingSubscriptionId'] ?? $data['billing_subscription_id'] ?? null,
            providerSubscriptionId: $data['providerSubscriptionId'] ?? $data['provider_subscription_id'] ?? $data['payment_provider_subscription_id'] ?? null,
            billingSubscriptionTransactionId: $data['billingSubscriptionTransactionId'] ?? $data['billing_subscription_transaction_id'] ?? null,
            productType: isset($data['productType']) ? (
                is_string($data['productType']) ? ApiProductTypeKey::from($data['productType']) : $data['productType']
            ) : (
                isset($data['product_type']) ? (
                    is_string($data['product_type']) ? ApiProductTypeKey::from($data['product_type']) : $data['product_type']
                ) : ApiProductTypeKey::ONE_TIME
            )
        );

        $constructorArrayKeys = [
            'billable', 'user',
            'billingOrderId', 'billing_order_id',
            'billingPaymenTransactionId', 'billing_payment_transaction_id',
            'orderNumber', 'order_number', 'providerOrderId', 'provider_order_id',
            'payment_provider_order_id', 'providerCheckoutId', 'provider_checkout_id',
            'payment_provider_checkout_id', 'providerTransactionId', 'provider_transaction_id',
            'payment_provider_transaction_id', 'data', 'metadata', 'billingSubscriptionId', 
            'billing_subscription_id', 'providerSubscriptionId', 'provider_subscription_id',
            'payment_provider_subscription_id', 'billingSubscriptionTransactionId',
            'billing_subscription_transaction_id', 'productType', 'product_type'
        ];

        $result = array_filter(
            $data,
            fn(string $key) => !in_array($key, $constructorArrayKeys),
            ARRAY_FILTER_USE_KEY
        );

        foreach ($result as $key => $value) {
            $instance->{$key} = $value;
        }

        return $instance;
    }

    public static function make(
        Billable $user,
        string $billingOrderId,
        string $billingPaymenTransactionId,
        string $orderNumber,
        ?string $providerOrderId = null,
        ?string $providerCheckoutId = null,
        ?string $providerTransactionId = null,
        ?string $billingSubscriptionId = null,
        ?string $providerSubscriptionId = null,
        ?string $billingSubscriptionTransactionId = null,
        array $metadata = [],
        ApiProductTypeKey $productType = ApiProductTypeKey::ONE_TIME,
    ): static {
        return new static(
            $user, $billingOrderId,
            $billingPaymenTransactionId, 
            $orderNumber, $providerOrderId,
            $providerCheckoutId, $providerTransactionId,
            $billingSubscriptionId, $providerSubscriptionId, 
            $billingSubscriptionTransactionId, $metadata, $productType
        );
    }
}