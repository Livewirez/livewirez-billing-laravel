<?php 

namespace Livewirez\Billing\Interfaces;

use Illuminate\Http\Request;
use Livewirez\Billing\Lib\Cart;
use Livewirez\Billing\PaymentResult;
use Livewirez\Billing\Lib\Orders\InitializeOrderRequest;
use Livewirez\Billing\Models\BillingPlan;
use Livewirez\Billing\SubscriptionResult;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Models\BillingPlanPrice;
use Symfony\Component\HttpFoundation\Response;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Lib\Orders\CompleteOrderRequest;

interface PaymentProviderInterface
{
    public const string PROVIDER_TYPE = 'CUSTOM'; // 'CUSTOM', 'PACKAGE'

    public const string CURRENCY_TYPE = 'FIAT'; // 'FIAT', 'CRYPTO'

    public function getTokenPaymentProvider(): TokenizedPaymentProviderInterface;

    public function initializePayment(CartInterface|ProductInterface $cart, InitializeOrderRequest $request): PaymentResult;

    public function completePayment(CompleteOrderRequest $request): ?PaymentResult;

    public function refundPayment(string $billingOrderId, string $providerOrderId): bool;
    
    public function getPaymentStatus(string $providerOrderId): PaymentStatus;

    public function initiateSubscription(BillingPlan $plan, BillingPlanPrice $planPrice, InitializeOrderRequest $request): SubscriptionResult;

    public function startSubscription(CompleteOrderRequest $request): SubscriptionResult;

    public function updateSubscription(
        string $billingSubscriptionId, 
        string $providerSubscriptionId, 
        BillingPlanPrice $newPlanPrice, 
        array $data = []
    ): SubscriptionResult;

    public function getSubscription(string $providerSubscriptionId): array;

    public function listSubscriptions(): array;

    public function cancelSubscription(string $providerSubscriptionId): bool;

    public function pauseSubscription(string $providerSubscriptionId): bool;

    public function resumeSubscription(string $providerSubscriptionId): bool;

    public function getSubscriptionStatus(string $providerSubscriptionId): SubscriptionStatus;

    public function handleWebhook(Request $request): Response; 
}