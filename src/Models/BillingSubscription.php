<?php 

namespace Livewirez\Billing\Models;

use Livewirez\Billing\Actions\CancelSubscription;
use Livewirez\Billing\Money;
use Illuminate\Support\Carbon;
use Livewirez\Billing\Billing;
use Illuminate\Foundation\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Livewirez\Billing\Models\Casts\PaymentProviderCast;
use Livewirez\Billing\SubscriptionsManager;

class BillingSubscription extends Model
{
    protected static function booted(): void
    {
        static::saving(static function (self $subscription) {
            $subscription->billable_key = "{$subscription->billable_type}:{$subscription->billable_id}";
        });
    }

    protected $fillable = [
        'billing_subscription_id',
        'billing_plan_id',
        'billing_plan_price_id',
        'billing_plan_name',
        'payment_provider',
        'payment_provider_subscription_id',
        'payment_provider_checkout_id',
        'payment_provider_plan_id',
        'sub_payment_provider',
        'interval',
        'custom_interval_count',
        'is_active',
        'status',
        'trial_starts_at',
        'trial_ends_at',
        'starts_at',
        'ends_at',
        'canceled_at',
        'ended_at',
        'paused_at',
        'resumed_at',
        'expired_at',
        'processed_at',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
        'trial_starts_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'starts' => 'datetime',
        'ends_at' =>  'datetime',
        'canceled_at' => 'datetime',
        'processed_at' => 'datetime',
        'expired_at' =>  'datetime',
        'paused_at' =>  'datetime',
        'resumed_at' =>  'datetime',
        'ended_at' =>  'datetime',
        'next_billing_at' =>  'datetime',
        'status' => SubscriptionStatus::class,
        'interval' => SubscriptionInterval::class
    ];

    /**
     * Get the passkey_user model that the access token belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function billable(): MorphTo
    {
        return $this->morphTo('billable');
    }

    public function billing_orders(): HasMany
    {
        return $this->hasMany(Billing::$billingOrder, 'billing_subscription_id');
    }

    public function billing_subscription_discounts(): HasMany
    {
        return $this->hasMany(Billing::$billingSubscriptionDiscount, 'billing_subscription_id');
    }

    public function billing_plan(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingPlan, 'billing_plan_id');
    }

    /**
     * @return BelongsTo<BillingPlanPrice, BillingSubscription>
     */
    public function billing_plan_price(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingPlanPrice, 'billing_plan_price_id');
    }

    /**
     * @return HasMany<BillingSubscriptionTransaction, BillingSubscription>
     */
    public function billing_subscription_transactions(): HasMany
    {
        return $this->hasMany(Billing::$billingSubscriptionTransaction, 'billing_subscription_id');
    }

    /**
     * @return HasMany<BillingSubscriptionEvent, static>
     */
    public function billing_subscription_events(): HasMany
    {
        return $this->hasMany(Billing::$billingSubscriptionEvent, 'billing_subscription_id');
    }

    /**
     * @return HasMany<BillingPaymentTransaction, static>
     */
    public function billing_payment_transactions(): HasMany
    {
        return $this->hasMany(
            Billing::$billingPaymentTransaction,
            'billing_subscription_id'
        );
    }

    public function cancel(): bool
    {
        $handler = new CancelSubscription(
            new SubscriptionsManager
        );

        return $handler->handle($this);
    }

    public function expire(): bool
    {
        return $this->update([
            'status' => SubscriptionStatus::EXPIRED,
            'is_active' => false,
            'expired_at' => now()
        ]);
    }

    public function pause(): bool
    {
        return $this->update([
            'status' => SubscriptionStatus::PAUSED,
            'paused_at' => now()
        ]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            SubscriptionStatus::ACTIVE, SubscriptionStatus::TRIALING,
            SubscriptionStatus::CANCELLATION_PENDING,
            SubscriptionStatus::PAUSED,
        ]);
    }

    public function isCanceled(): bool
    {
        return in_array($this->status, [SubscriptionStatus::CANCELED, SubscriptionStatus::CANCELLED]);
    }

    public function isExpired(): bool
    {
        return $this->status === SubscriptionStatus::EXPIRED;
    }

    public function isPaused(): bool
    {
        return $this->status === SubscriptionStatus::PAUSED;
    }

    public function isOnTrial(): bool
    {
        return $this->status === SubscriptionStatus::TRIALING && 
               $this->trial_ends_at && 
               Carbon::now()->isBefore($this->trial_ends_at);
    }

    public function trialDaysRemaining(): int
    {
        if (!$this->isOnTrial()) {
            return 0;
        }

        return Carbon::now()->diffInDays($this->trial_ends_at, false);
    }

    public function hasAccess(): bool
    {
        if ($this->isActive()) {
            return true;
        }

        // Grace period for past_due subscriptions
        if ($this->status === SubscriptionStatus::PAST_DUE) {
            return Carbon::now()->isBefore($this->ends_at->addDays(7));
        }

        return false;
    }
}