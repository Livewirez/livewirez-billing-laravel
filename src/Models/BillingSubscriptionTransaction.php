<?php 

namespace Livewirez\Billing\Models;

use Illuminate\Support\Str;
use Livewirez\Billing\Money;
use Illuminate\Support\Carbon;
use Livewirez\Billing\Billing;
use Illuminate\Database\Eloquent\Model;
use Livewirez\Billing\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Livewirez\Billing\Models\Casts\PaymentProviderCast;
use Livewirez\Billing\Enums\SubscriptionTransactionType;
use Livewirez\Billing\Enums\SubscriptionTransactionStatus;

class BillingSubscriptionTransaction extends Model
{
    protected static function booted(): void
    {
        static::creating(static function (self $transaction) {
            $transaction->billing_subscription_transaction_id = Str::uuid();
        });
    }

    protected $fillable = [
        'from_billing_plan_id',
        'from_billing_plan_price_id',
        'billing_subscription_id',
        'billing_plan_id',
        'billing_plan_price_id',
        'billing_plan_name',
        'transaction_ref',
        'type',
        'payment_provider',
        'payment_provider_subscription_id',
        'payment_provider_checkout_id',
        'payment_provider_plan_id',
        'sub_payment_provider',
        'amount',
        'scale',
        'currency',
        'interval',
        'custom_interval_count',
        'status',
        'payment_status',
        'payment_provider_status',
        'applied_at',
        'due_date',
        'paid_at',
        'processed_at',
        'metadata',
        'resource_id',
        'payment_response',
        'webhook_response'
    ];

    protected $casts = [
        'payment_provider' => PaymentProviderCast::class,
        'metadata' => 'array',
        'payment_response' => 'array',
        'webhook_response' => 'array',
        'applied_at' =>  'datetime',
        'due_date' =>  'datetime',
        'paid_at' =>  'datetime',
        'payment_status' => PaymentStatus::class,
        'status' => SubscriptionTransactionStatus::class,
        'type' => SubscriptionTransactionType::class,
        'interval' => SubscriptionInterval::class
    ];

    protected $appends = [
        'formatted_amount'
    ];

    protected function formattedAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => Money::formatAmountUsingCurrency($this->amount, $this->currency),
        );
    }

    public function billing_subscription(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingSubscription, 'billing_subscription_id');
    }

    public function from_billing_plan(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingPlan, 'from_billing_plan_id');
    }

    public function from_billing_plan_price(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingPlanPrice, 'from_billing_plan_price_id');
    }

    public function billing_plan(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingPlan, 'billing_plan_id');
    }

    public function billing_plan_price(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingPlanPrice, 'billing_plan_price_id');
    }

    public function billing_subscription_events(): HasMany
    {
        return $this->hasMany(Billing::$billingSubscriptionEvent, 'billing_subscription_transaction_id');
    }
}