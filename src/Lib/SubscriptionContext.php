<?php 

namespace Livewirez\Billing\Lib;

use Livewirez\Billing\Models\BillingPlan;
use Livewirez\Billing\Models\BillingPlanPrice;
use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Enums\SubscriptionTransactionType;
use Livewirez\Billing\Models\BillingSubscriptionTransaction;

class SubscriptionContext
{
    public function __construct(
        public SubscriptionTransactionType $type,
        public ?BillingSubscription $existingSubscription = null,
        public ?BillingSubscriptionTransaction $subscriptionTransaction = null,
        public ?BillingPlan $plan = null,
        public ?BillingPlanPrice $price = null,
        public ?BillingPlan  $currentPlan = null,
        public ?BillingPlanPrice $currentPlanPrice = null,
    )
    {

    }

    public static function make(
        SubscriptionTransactionType $type,
        ?BillingSubscription $existingSubscription = null,
        ?BillingSubscriptionTransaction $subscriptionTransaction = null,
        ?BillingPlan $plan = null,
        ?BillingPlanPrice $price = null,
        ?BillingPlan  $currentPlan = null,
        ?BillingPlanPrice $currentPlanPrice = null,
    ): self
    {
        return new static(
            $type, $existingSubscription,
            $subscriptionTransaction, $plan, $price,
            $currentPlan, $currentPlanPrice
        );
    }

    public function setSubcriptionTransaction(BillingSubscriptionTransaction $subscriptionTransaction): static
    {
        $this->subscriptionTransaction = $subscriptionTransaction;

        return $this;
    }
}