<?php 

namespace Livewirez\Billing\Actions;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Lib\CheckoutDetails;
use Livewirez\Billing\Models\BillingPlanPrice;
use Livewirez\Billing\SubscriptionResult;
use Livewirez\Billing\SubscriptionsManager;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Interfaces\ProductInterface;

class StartSubscription
{
    public function __construct(protected SubscriptionsManager $subscriptionsManager) {}

    public function handle(
        Billable $payer, 
        PaymentProvider|string $provider,
        string $providerSubscriptionId,
        BillingPlanPrice $planPrice,
        ?CheckoutDetails $checkoutDetails = null, 
        array $metadata = []
    ): SubscriptionResult 
    {
        return $checkoutDetails !== null ? $this->subscriptionsManager->startSubscriptionUsingCheckoutDetails(
            $payer,
            $provider,
            $checkoutDetails,
            $providerSubscriptionId,
            $planPrice,
            $metadata
        ) : $this->subscriptionsManager->startSubscription(
            $payer,
            $provider,
            $providerSubscriptionId,
            $planPrice,
            $metadata
        );
    }
}