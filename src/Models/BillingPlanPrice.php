<?php

namespace Livewirez\Billing\Models;

use Illuminate\Support\Str;
use Livewirez\Billing\Money;
use Livewirez\Billing\Billing;
use Illuminate\Database\Eloquent\Model;
use Livewirez\Billing\Enums\CurrencyType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPlanPrice extends Model
{
    protected static function booted(): void
    {
        parent::booted();

        static::creating(static function (self $model) {
            $model->billing_plan_price_id = Str::uuid();
        });
    }

    protected $attributes = [
        'interval' => SubscriptionInterval::MONTHLY
    ];

    protected $fillable = [
        'billing_plan_price_id',
        'interval',
        'custom_interval_count',
        'amount',
        'scale',
        'currency',
        'discount', 
        'discount_scale',
        'tax', 'tax_scale',
        'tax_type',
        'tax_model'
    ];

    protected $casts = [
        'tax' => 'integer',
        'discount' => 'integer',
        'amount' => 'integer',
        'interval' => SubscriptionInterval::class
    ];

    protected $appends = [
        'formatted_amount',
        'currency_code',
    ];

    public function calculateDiscountedPrice(?BillingDiscountCode $discount = null): int
    {
        if (!$discount || !$discount->isValid()) {
            return $this->amount;
        }

        if ($discount->type === 'percentage') {
            return $this->amount * (1 - ($discount->value / 100));
        }

        return max(0, $this->amount - $discount->value);
    }

    /**
     * Interact with the user's first name.
     */
    protected function currencyCode(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->currency,
        );
    }
    
    protected function formattedAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => Money::formatAmountUsingCurrency($this->amount, $this->currency),
        );
    }

    public function billing_plan(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingPlan, 'billing_plan_id');
    }

    public function billing_discount_code(): BelongsTo
    {
        return $this->belongsTo(Billing::$billingDiscountCode, 'billing_discount_code_id');
    }

    public function billing_subscriptions(): HasMany
    {
        return $this->hasMany(
            Billing::$billingSubscription,
            'billing_plan_price_id'
        );
    }

    public function billing_subscription_transactions(): HasMany
    {
        return $this->hasMany(
            Billing::$billingSubscriptionTransaction,
            'billing_plan_price_id'
        );
    }

    public function from_billing_subscription_transactions(): HasMany
    {
        return $this->hasMany(
            Billing::$billingSubscriptionTransaction,
            'from_billing_plan_price_id'
        );
    }

    public function billing_plan_payment_provider_information(): HasMany
    {
        return $this->hasMany(
            Billing::$billingPlanPaymentProviderInformation,
            'billing_plan_price_id',
        );
    }

    public function billing_plan_price_payment_provider_information(): HasMany
    {
        return $this->hasMany(Billing::$billingPlanPricePaymentProviderInformation, 'billing_plan_price_id');
    }

    public function billing_discount_code_payment_provider_information(): HasOne
    { 
        return $this->hasOne(Billing::$billingDiscountCodePaymentProviderInformation, 'billing_discount_code_id');
    }

    public function billing_order_items(): HasMany
    {
        return $this->hasMany(Billing::$billingOrderItem, 'billing_plan_price_id');
    }
}