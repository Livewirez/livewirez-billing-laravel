<?php 

namespace Livewirez\Billing\Models;

use Illuminate\Support\Str;
use Livewirez\Billing\Billing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingSubscriptionEvent extends Model
{
    protected static function booted(): void
    {
        static::creating(static function (self $event) {
            $event->billing_subscription_event_id = Str::uuid();
        });
    }

    protected $fillable = [
        'billing_subscription_id',
        'billing_subscription_transaction_id',
        'type',
        'description',
        'triggered_by',
        'details',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'details' => 'array',
    ];


    public function billing_subscription(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingSubscription, 'billing_subscription_id');
    }

    public function billing_subscription_transaction(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingSubscriptionTransaction, 'billing_subscription_transaction_id');
    }
}