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

class BillingCart extends Model
{
    protected static function booted(): void
    {
        static::creating(static function (self $cart) {
            $cart->billing_cart_id = Str::uuid();
        });
    }

    protected $fillable = [
        'currency',
        'billing_cart_id',
        'total',
        'subtotal',
        'tax', 
        'discount',
        'shipping',
        'shipping_discount',
        'handling',
        'insurance',
    ];

    public function billable(): MorphTo
    {
        return $this->morphTo('billable');
    }

    public function billing_cart_items(): HasMany
    {
        return $this->hasMany(Billing::$billingCartItem, 'billing_cart_id');
    }

    public function billing_order(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingOrder, 'billing_order_id');
    }
}