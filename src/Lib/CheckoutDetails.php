<?php

namespace Livewirez\Billing\Lib;

use Illuminate\Support\Collection;
use Livewirez\Billing\Models\BillingOrder;
use Livewirez\Billing\Models\BillingOrderItem;
use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Models\BillingPaymentTransaction;

class CheckoutDetails
{

    public ?SubscriptionContext $subscriptionContext = null;

    public function __construct(
        public BillingPaymentTransaction $billingPaymentTransaction,
        public ?BillingOrder $billingOrder = null,
        /** @var Collection<BillingOrderItem>  */public ?Collection $billingOrderItems = null,
        public ?BillingSubscription $billingSubscription = null,
        public ?string $checkoutUrl = null
    ) {}

    public static function make(
        BillingPaymentTransaction $billingPaymentTransaction,
        ?BillingOrder $billingOrder = null,
        ?Collection $billingOrderItems = null,
        ?BillingSubscription $billingSubscription = null,
        ?string $checkoutUrl = null
    ): static 
    {
        return new static(
            $billingPaymentTransaction,
            $billingOrder,
            $billingOrderItems,
            $billingSubscription,
            $checkoutUrl
        );
    }

    public static function makeFromSpread(mixed ...$args): static
    {
        return new static(...$args);
    } 

    public function getCheckoutUrl(): ?string 
    {
        return $this->checkoutUrl;
    }

    public function setCheckoutUrl(?string $url = null): static
    {
        $this->checkoutUrl = $url;
        
        return $this;
    }

    public function getBillingPaymentTransaction(): BillingPaymentTransaction
    {
        return $this->billingPaymentTransaction;
    }

    public function setBillingPaymentTransaction(BillingPaymentTransaction $billingPaymentTransaction): static
    {
        $this->billingPaymentTransaction = $billingPaymentTransaction;
        
        return $this;
    }
    
    public function getBillingOrder(): BillingOrder
    {
        throw_if(
            $this->billingOrder === null, 
            'InvalidArgumentException', 
            'Parameter "billingOrder" is not set'
        );

        return $this->billingOrder;
    }

    public function setBillingOrder(BillingOrder $billingOrder): static
    {
        $this->billingOrder = $billingOrder;
        
        return $this;
    }

    public function getBillingOrderItems(): Collection
    {
        throw_if(
            $this->billingOrderItems === null, 
            'InvalidArgumentException', 
            'Parameter "billingOrderItems" is not set'
        );

        return $this->billingOrderItems;
    }

    public function setBillingOrderItems(Collection $billingOrderItems): static
    {
        $this->billingOrderItems = $billingOrderItems;
        
        return $this;
    }

    public function getBillingSubscription(): BillingSubscription
    {
        throw_if(
            $this->billingSubscription === null, 
            'InvalidArgumentException', 
            'Parameter "billingSubscription" is not set'
        );

        return $this->billingSubscription;
    }

    public function setBillingSubscription(BillingSubscription $billingSubscription): static
    {
        $this->billingSubscription = $billingSubscription;
        
        return $this;
    }

    public function getSubscriptionContext(): SubscriptionContext
    {
        throw_if(
            ! $this->subscriptionContext,
            'InvalidArgumentException', 
            'Parameter "subscriptionContext" is not set'
        );

        return $this->subscriptionContext;
    }

    public function setSubscriptionContext(SubscriptionContext $context): static
    {
        $this->subscriptionContext = $context;

        return $this;
    }
}