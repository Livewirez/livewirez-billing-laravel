<?php

namespace Livewirez\Billing;

use Tekord\Result\Result;
use Illuminate\Support\Collection;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Lib\CheckoutDetails;
use Livewirez\Billing\Models\BillingOrder;
use Livewirez\Billing\Models\BillingPaymentTransaction;

readonly class PaymentResult
{
    public CheckoutDetails $checkoutDetails;

    public function __construct(
        public bool $success,
        public string $billingOrderId,
        public PaymentStatus $status,
        public Result $result,
        public ?string $checkoutUrl = null,
        public ?string $providerOrderId = null,
        public ?string $providerPaymentId = null,
        public ?string $providerCheckoutId = null,
        public ?string $providerTransactionId = null,
        public ?string $message = null,
        public ?array $metadata = null,
        public bool $throw = false
    ) {}

    public function getCheckoutUrl(): ?string 
    {
        return $this->checkoutUrl;
    }

    public function getCheckoutDetails(): ?CheckoutDetails
    {
        return $this->checkoutDetails;
    }

    public function setCheckoutDetails(CheckoutDetails $checkoutDetails): static
    {
        $this->checkoutDetails = $checkoutDetails;

        return $this;
    }

    public function getBillingOrder(): BillingOrder
    {
        return $this->checkoutDetails->getBillingOrder();
    }

    public function getBillingPaymentTransaction(): BillingPaymentTransaction
    {
        return $this->checkoutDetails->getBillingPaymentTransaction();
    }

    public function getBillingOrderItems(): Collection
    {
        return $this->checkoutDetails->getBillingOrderItems();
    }
}