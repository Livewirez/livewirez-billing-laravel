<?php

namespace Livewirez\Billing\Models;

use Illuminate\Support\Str;
use Livewirez\Billing\Billing;
use Illuminate\Database\Eloquent\Model;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Traits\HasScaledAmount;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Livewirez\Billing\Models\Casts\PaymentProviderCast;

class BillingPlanPaymentProviderInformation extends Model
{
    protected $fillable = [
        'billing_plan_price_id',
        'billing_plan_id',
        'payment_provider',
        'metadata',
        'payment_provider_plan_id',
        'status',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
        'payment_provider' => PaymentProviderCast::class,
    ];

    public function billing_plan(): BelongsTo 
    {
        return $this->belongsTo(
            Billing::$billingPlan,
            'billing_plan_id'
        );
    }

    public function billing_plan_price(): BelongsTo
    {
        return $this->belongsTo(
            Billing::$billingPlanPrice,
            'billing_plan_price_id'
        );
    }
}