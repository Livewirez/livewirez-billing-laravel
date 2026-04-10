<?php 

namespace Livewirez\Billing\Actions;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Models\BillingPlanPrice;
use Livewirez\Billing\SubscriptionResult;
use Livewirez\Billing\SubscriptionsManager;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Interfaces\ProductInterface;

class InitializeSubscription
{
    public function __construct(protected SubscriptionsManager $subscriptionsManager) {}


    public function handle(
        Billable $payer, 
        PaymentProvider|string $provider,
        BillingPlanPrice $planPrice, 
        array $subscriptionData = [],
        array $metadata = []
    ): SubscriptionResult 
    {
        return $this->subscriptionsManager->initiateSubscription(
            $payer,
            $provider,
            $planPrice,
            $subscriptionData,
            $metadata
        );
    }
}