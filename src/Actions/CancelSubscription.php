<?php 

namespace Livewirez\Billing\Actions;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Models\BillingPlanPrice;
use Livewirez\Billing\SubscriptionResult;
use Livewirez\Billing\SubscriptionsManager;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Interfaces\ProductInterface;
use Livewirez\Billing\Models\BillingSubscription;

class CancelSubscription
{
    public function __construct(protected SubscriptionsManager $subscriptionsManager) {}


    public function handle(
        BillingSubscription|string $subscription
    ): bool 
    {
        return $this->subscriptionsManager->cancelSubscription(
           $subscription
        );
    }
}