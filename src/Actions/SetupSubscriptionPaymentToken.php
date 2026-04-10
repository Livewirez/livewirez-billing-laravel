<?php 

namespace Livewirez\Billing\Actions;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\SubscriptionsManager;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingPlanPrice;

class SetupSubscriptionPaymentToken
{
    public function __construct(protected SubscriptionsManager $subscriptionsManager) {}

    public function handle(
        Billable $user, 
        PaymentProvider|string $paymentProvider,
        BillingPlanPrice $planPrice, 
        array $metadata = []
    ): array 
    {
       return $this->subscriptionsManager->setupSubscriptionPaymentToken($user, $paymentProvider, $planPrice, $metadata);
    }
}
