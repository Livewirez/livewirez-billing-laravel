<?php 

namespace Livewirez\Billing\Models;

use Illuminate\Support\Str;
use Livewirez\Billing\Money;
use Illuminate\Support\Carbon;
use Livewirez\Billing\Billing;
use Illuminate\Foundation\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class BillingDiscountCode extends Model
{
    protected static function booted(): void
    {
        static::creating(static function (self $dc) {
            $dc->billing_discount_code_id = Str::uuid();
        });
    }

    protected $fillable = [
        'code', 'name', 'type', 'billing_type', 'currency',
        'billing_discount_code_id', 'value', 'max_uses', 'used_count', 
        'max_uses_per_customer', 'starts_at', 'expires_at', 'applicable_plans', 
        'extends_trial_days', 'is_active', 'metadata'
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'metadata' => 'array'
    ];

    public function billing_subscription_discounts(): HasMany
    {
        return $this->hasMany(Billing::$billingSubscriptionDiscount, 'billing_discount_code_id');
    }

    public function billing_discount_code_payment_provider_information(): HasMany
    { 
        return $this->hasMany(Billing::$billingDiscountCodePaymentProviderInformation, 'billing_discount_code_id');
    }

    public function billing_plan_payment_provider_information(): HasManyThrough
    { 
        // return $this->hasManyThrough(
        //     Billing::$billingPlanPaymentProviderInformation, 
        //     Billing::$billingPlanPrice, 
        //     'billing_discount_code_id',
        //     'billing_plan_price_id',
        // );

        return $this->through('billing_plan_prices')->has('billing_plan_payment_provider_information');
    }

    public function billing_products(): HasMany
    {
        return $this->hasMany(Billing::$billingProduct, 'billing_discount_code_id');
    }

    public function billing_plan_prices(): HasMany
    {
        return $this->hasMany(Billing::$billingPlanPrice, 'billing_discount_code_id');
    }

    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->starts_at && Carbon::now()->isBefore($this->starts_at)) {
            return false;
        }

        if ($this->expires_at && Carbon::now()->isAfter($this->expires_at)) {
            return false;
        }

        if ($this->max_uses && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    public function isApplicableToPlan(BillingPlan $plan): bool
    {
        // If there are no specific plan restrictions (i.e., no prices tied), it's universally applicable
        if (! $this->billing_plan_prices()->exists()) {
            return true;
        }

        // Otherwise, check if this discount has been applied to that specific plan
        return $this->billing_plan_prices()
            ->where('billing_plan_id', $plan->id)
            ->exists();
    }

    public function canBeUsedByCustomer(Billable $user): bool
    {
        if (!$this->max_uses_per_customer) {
            return true;
        }

        $usageCount = $this->billing_subscription_discounts()
            ->whereHas('billing_subscription', function ($query) use ($user) {
                $query->where([
                    'billable_id' => $user->getKey(),
                    'billable_type' => get_class($user)
                ]);
            })
            ->count();

        return $usageCount < $this->max_uses_per_customer;
    }
}