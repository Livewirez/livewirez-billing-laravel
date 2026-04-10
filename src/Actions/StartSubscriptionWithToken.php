<?php 

namespace Livewirez\Billing\Actions;

use Livewirez\Billing\SubscriptionResult;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\SubscriptionsManager;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingPlanPrice;
use Livewirez\Billing\Models\BillablePaymentMethod;

class StartSubscriptionWithToken
{
    public function __construct(protected SubscriptionsManager $subscriptionsManager) {}

    public function handle(
        Billable $user, 
        PaymentProvider|string $paymentProvider,
        BillingPlanPrice $planPrice,
        BillablePaymentMethod | string $token, 
        array $metadata = []
    ): SubscriptionResult 
    {
        return $this->subscriptionsManager->startSubscriptionWithToken(
            $user, $paymentProvider, $planPrice, $token, $metadata
        );
    }
}
