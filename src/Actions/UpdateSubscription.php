<?php

namespace Livewirez\Billing\Actions;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Models\BillingPlanPrice;
use Livewirez\Billing\SubscriptionResult;
use Livewirez\Billing\SubscriptionsManager;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Interfaces\ProductInterface;

class UpdateSubscription
{
    public function __construct(protected SubscriptionsManager $subscriptionsManager) {}

    public function handle(
        Billable $payer, 
        PaymentProvider|string $provider,
        BillingPlanPrice $planPrice, 
        array $updates = [],
        array $metadata = []
    ): SubscriptionResult 
    {
        return $this->subscriptionsManager->updateSubscription(
            $payer,
            $provider,
            $planPrice,
            $updates,
            $metadata
        );
    }
}