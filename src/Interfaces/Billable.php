<?php

namespace Livewirez\Billing\Interfaces;

use Livewirez\Billing\Models\BillingCart;
use Livewirez\Billing\Models\BillingOrder;
use Livewirez\Billing\Models\BillableAddress;
use Illuminate\Contracts\Auth\Authenticatable;
use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Models\BillablePaymentMethod;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Livewirez\Billing\Models\BillingPaymentTransaction;
use Livewirez\Billing\Models\BillablePaymentProviderInformation;

interface Billable extends Authenticatable
{
    public const int FIRST_NAME = 1;
    public const int LAST_NAME = 2;


    public function getKey();

    public function getName(int $nameType = self::FIRST_NAME): string;

    public function getEmail(): string;
   
    public function getMobileNumber(): ?string;

    /**
     * Get the class name for polymorphic relations.
     *
     * @return string
    */
    public function getMorphClass();

    /**
     * @return MorphOne<BillingCart, static>
    */
    public function billing_cart(): MorphOne;
    
    /**
     * @return MorphMany<BillablePaymentMethod, static>
    */
    public function billable_payment_methods(): MorphMany;

    /**
     * @return MorphMany<BillablePaymentProviderInformation, static>
    */
    public function billable_payment_provider_information(): MorphMany;
    
    /**
     * @return MorphMany<BillableAddress, static>
    */
    public function billable_addresses(): MorphMany;

    /**
     * @return MorphMany<BillingPaymentTransaction, static>
    */
    public function billing_payment_transactions(): MorphMany;

    /**
     * @return MorphMany<BillingOrder, static>
    */
    public function billing_orders(): MorphMany;

    /**
     * @return MorphOne<BillingSubscription, static>
    */
    public function billing_subscription(): MorphOne;

    public function hasActiveBillingSubscription(): bool;

    public function hasActiveBillingSubscriptionWithSamePlan(int $planId, int $planPriceId): bool;
}