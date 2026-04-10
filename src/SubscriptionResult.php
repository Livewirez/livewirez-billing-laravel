<?php

namespace Livewirez\Billing;

use Tekord\Result\Result;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Lib\CheckoutDetails;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Models\BillingPaymentTransaction;

readonly class SubscriptionResult
{
    public CheckoutDetails $checkoutDetails;
    
    public function __construct(
        public bool $success,
        public string $billingSubscriptionId,
        public PaymentStatus $paymentStatus,
        public SubscriptionStatus $status,
        public Result $result,
        public ?string $checkoutUrl = null,
        public ?string $providerSubscriptionId = null,
        public ?string $providerCheckoutId = null,
        public ?string $providerOrderId = null,
        public ?string $providerTransactionId = null,
        public ?string $providerPlanId = null,
        public ?string $message = null,
        public ?array $metadata = null,
        public bool $throw = false
    ) {}

    public function getCheckoutUrl(): ?string 
    {
        return $this->checkoutUrl;
    }

    public function getCheckoutDetails(): CheckoutDetails
    {
        return $this->checkoutDetails;
    }

    public function setCheckoutDetails(CheckoutDetails $checkoutDetails): static
    {
        $this->checkoutDetails = $checkoutDetails;

        return $this;
    }

    public function getBillingSubscription(): BillingSubscription
    {
        return $this->checkoutDetails->getBillingSubscription();
    }

    public function getBillingPaymentTransaction(): BillingPaymentTransaction
    {
        return $this->checkoutDetails->getBillingPaymentTransaction();
    }

}