<?php 

namespace Livewirez\Billing\Lib\Orders;

use DateTimeInterface;
use BadMethodCallException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewirez\Billing\Lib\Address;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Enums\ApiProductTypeKey;

#[\AllowDynamicProperties]
class InitializeOrderRequest
{
    public ?Address $billingAddress = null;

    public function __construct(
        public Billable $user,
        public string $billingOrderId,
        public string $billingPaymenTransactionId,
        public string $orderNumber,
        public int $amount,
        public string $currency,
        public array $metadata = [],
        public ?string $billingSubscriptionId = null,
        public ?string $billingSubscriptionTransactionId = null,
        public ?DateTimeInterface $subscriptionStart = null,
        public ?DateTimeInterface $subscriptionEnd = null,
        public ApiProductTypeKey $productType = ApiProductTypeKey::ONE_TIME,
    ) {
        if (
            $productType === ApiProductTypeKey::SUBSCRIPTION && ($billingSubscriptionId === null 
            || $subscriptionStart === null || $subscriptionEnd === null) 
        )
            throw new InvalidArgumentException(
                sprintf(
                    "'%s' or '%s' or '%s' must be set if 'productType' is subscription",
                    'billingSubscriptionId',
                    'subscriptionStart', 
                    'subscriptionEnd'
                )
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

    public function getAmount(): int 
    {
        return $this->amount;
    }

    public function getCurrency(): string 
    {
        return $this->currency;
    }

    public function getMetadata(): array 
    {
        return $this->metadata;
    }

    public function getBillingSubscriptionId(): ?string
    {
        return $this->billingSubscriptionId;
    }

    public function getBillingSubscriptionTransactionId(): ?string
    {
        return $this->billingSubscriptionTransactionId;
    }

    public function getSubscriptionStart(): ?DateTimeInterface 
    {
        return $this->subscriptionStart;
    }

    public function getSubscriptionEnd(): ?DateTimeInterface 
    {
        return $this->subscriptionStart;
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
            amount: $data['amount'],
            currency: $data['currency'],
            metadata: $data['data'] ?? $data['metadata'] ?? [],
            billingSubscriptionId: $data['billingSubscriptionId'] ?? $data['billing_subscription_id'] ?? null,
            billingSubscriptionTransactionId: $data['billingSubscriptionTransactionId'] ?? $data['billing_subscription_transaction_id'] ?? null,
            subscriptionStart: $data['subscriptionStart'] ?? $data['subscription_start'] ?? $data['starts_at'] ?? $data['start'] ?? null,
            subscriptionEnd: $data['subscriptionEnd'] ?? $data['subscription_end'] ?? $data['ends_at'] ?? $data['end'] ?? null,
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
            'orderNumber', 'order_number', 'amount', 'currency',
            'data', 'metadata', 'billingSubscriptionId', 'billing_subscription_id',
            'billingSubscriptionTransactionId', 'billing_subscription_transaction_id',
            'subscriptionStart', 'subscription_start', 'start', 'subscriptionEnd', 
            'subscription_end', 'end', 'productType','product_type'
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
        int $amount,
        string $currency,
        array $metadata = [],
        ?string $billingSubscriptionId = null,
        ?string $billingSubscriptionTransactionId = null,
        ?DateTimeInterface $subscriptionStart = null,
        ?DateTimeInterface $subscriptionEnd = null,
        ApiProductTypeKey $productType = ApiProductTypeKey::ONE_TIME,
    ): static {
        return new static(
            $user, $billingOrderId,
            $billingPaymenTransactionId, 
            $orderNumber, $amount, $currency,
            $metadata, $billingSubscriptionId,
            $billingSubscriptionTransactionId, 
            $subscriptionStart, $subscriptionEnd, 
            $productType
        );
    }
    
}