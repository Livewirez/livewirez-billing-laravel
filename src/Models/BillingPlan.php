<?php

namespace Livewirez\Billing\Models;

use Illuminate\Support\Str;
use Livewirez\Billing\Billing;
use Livewirez\Billing\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPlan extends Model
{
    protected static function booted(): void
    {
        parent::booted();

        static::creating(static function (self $model) {
            $model->billing_plan_id = Str::uuid();
        });
    }

    protected $fillable = [
        'billing_plan_id',
        'name',
        'description',
        'ranking',
        'type',
        'is_active',
        'features',
        'metadata',
        'trial_days',
        'thumbnail',
        'url',
    ];

    protected $casts = [
        'features' => 'json',
        'metadata' => 'array',
    ];

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? []);
    }

    public function billing_product(): BelongsTo
    {
        return $this->belongsTo(Billing::$billingProduct, 'billing_product_id');
    }

    public function billing_prices(): HasMany
    {
        return $this->hasMany(Billing::$billingPlanPrice, 'billing_plan_id');
    }

    public function billing_subscriptions(): HasMany
    {
        return $this->hasMany(Billing::$billingSubscription, 'billing_plan_id');
    }

    public function billing_subscription_transactions(): HasMany
    {
        return $this->hasMany(
            Billing::$billingSubscriptionTransaction,
            'billing_plan_id'
        );
    }

    public function from_billing_subscription_transactions(): HasMany
    {
        return $this->hasMany(
            Billing::$billingSubscriptionTransaction,
            'from_billing_plan_id'
        );
    }

    public function billing_plan_prices(): HasMany
    {
        return $this->billing_prices();
    }

    public function billing_plan_payment_provider_information(): HasMany
    {
        return $this->hasMany(
            Billing::$billingPlanPaymentProviderInformation,
            'billing_plan_id'
        );
    }

    public function billing_plan_price_payment_provider_information(): HasMany
    {
        return $this->hasMany(Billing::$billingPlanPricePaymentProviderInformation, 'billing_plan_id');
    }

    public function billing_order_items(): HasMany
    {
        return $this->hasMany(Billing::$billingOrderItem, 'billing_plan_id');
    }
}