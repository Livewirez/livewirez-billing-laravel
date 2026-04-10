<?php 

namespace Livewirez\Billing\Traits;

use function count;
use Livewirez\Billing\Billing;
use Livewirez\Billing\Models\BillingCart;
use Livewirez\Billing\Models\BillingPlan;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Models\BillingOrder;
use Livewirez\Billing\Models\BillableAddress;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Models\BillingSubscription;

use Livewirez\Billing\Models\BillablePaymentMethod;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Livewirez\Billing\Models\BillingPaymentTransaction;
use Livewirez\Billing\Models\BillablePaymentProviderInformation;

trait IsBillable
{
    public const int FIRST_NAME = 1;
    public const int LAST_NAME = 2;
    
    public function getName(int $nameType = self::FIRST_NAME): string
    {
        $tryGetLastName = function (): ?string {
            $name = preg_split('/\s+/', $this->first_name ?? $this->name ?? '');

            if(count($name) === 1) {
                return null;
            }

            if(count($name) === 2) {
                return $name[1];
            }

            if(count($name) === 3) {
                if(count($name) > 2) {
                    return "{$name[1]} {$name[2]}";
                }

                return $name[2];
            }

            return null;
        };


        return match ($nameType) {
            self::FIRST_NAME => $this->first_name ?? $this->username ?? $this->name ?? $this->email,
            self::LAST_NAME => $this->last_name ?? $tryGetLastName() ?? '',
            default => $this->first_name ?? $this->username ?? $this->name ?? $this->email,
        };
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getMobileNumber(): ?string
    {
        return null;
    }

    /**
     * @return MorphOne<BillingCart, static>
    */
    public function billing_cart(): MorphOne
    {
        return $this->morphOne(Billing::$billingCart, 'billable');
    }

    /**
     * @return MorphMany<BillableAddress, static>
    */
    public function billable_addresses(): MorphMany
    {
        return $this->morphMany(Billing::$billableAddress, 'billable');
    }

    /**
     * @return MorphMany<BillablePaymentMethod, static>
    */
    public function billable_payment_methods(): MorphMany
    {
        return $this->morphMany(Billing::$billablePaymentMethod, 'billable');
    }

    /**
     * @return MorphMany<BillablePaymentProviderInformation, static>
    */
    public function billable_payment_provider_information(): MorphMany
    {
        return $this->morphMany(Billing::$billablePaymentProviderInformation, 'billable');
    }

    /**
     * @return MorphMany<BillingPaymentTransaction, static>
    */
    public function billing_payment_transactions(): MorphMany
    {
        return $this->morphMany(Billing::$billingPaymentTransaction, 'billable');
    }

    /**
     * @return MorphMany<BillingOrder, static>
    */
    public function billing_orders(): MorphMany
    {
        return $this->morphMany(Billing::$billingOrder, 'billable');
    }

    /**
     * @return MorphOne<BillingSubscription, static>
    */
    public function billing_subscription(): MorphOne
    {
        return $this->morphOne(Billing::$billingSubscription, 'billable');
    }

    /**
     * @return MorphOne<BillingSubscription, static>
    */
    public function activeBillingSubscription(): MorphOne
    {
        return $this->billing_subscription()->where('status', SubscriptionStatus::ACTIVE);
    }

    public function hasActiveBillingSubscription(): bool
    {
        return $this->activeBillingSubscription()->exists();
    }

    public function hasActiveBillingSubscriptionWithSamePlan(int $planId, int $planPriceId): bool
    {
        return $this->billing_subscription()->where([
            'status' => SubscriptionStatus::ACTIVE,
            'billing_plan_id' => $planId,
            'billing_plan_price_id' => $planPriceId,
        ])->exists();
    }

    public function getBillingSubscriptionByPlan(string $planId): ?BillingSubscription
    {
        return $this->billing_subscription()
            ->where('billing_plan_id', $planId)
            ->where('status', SubscriptionStatus::ACTIVE)
            ->first();
    }

    public function getTotalSpent(): float
    {
        return $this->billing_orders()
            ->where('status',  PaymentStatus::COMPLETED)
            ->sum('amount');
    }
}