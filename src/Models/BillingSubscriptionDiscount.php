<?php 

namespace Livewirez\Billing\Models;

use Illuminate\Support\Str;
use Livewirez\Billing\Money;
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

class BillingSubscriptionDiscount extends Model
{
    protected static function booted(): void
    {
        static::creating(static function (self $sd) {
            $sd->billing_subscription_discount_id = Str::uuid();
        });
    }

    protected $fillable = [
        'billing_subscription_id', 'billing_discount_code_id', 'discount_amount'
    ];

    protected $casts = [
        'applied_at' => 'datetime'
    ];

    public function billing_subscription(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingSubscription, 'billing_subscription_id');
    }

    public function billing_discount_code(): BelongsTo
    {
        return $this->belongsTo(Billing::$billingDiscountCode, 'billing_discount_code_id');
    } 
}