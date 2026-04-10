<?php

namespace Livewirez\Billing\Models;

use Livewirez\Billing\Billing;
use Livewirez\Billing\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Livewirez\Billing\Models\Casts\PaymentProviderCast;

class BillingTransactionData extends Model
{
    protected $fillable = [
        'billing_payment_transaction_id',
        'transaction_ref',
        'payment_provider_transaction_id',
        'status',
        'resource_id',
        'webhook_id',
        'receipt_number',
        'payment_provider',  
        'sub_payment_provider',
        'payment_response',
        'webhook_response',
        'event',
        'transaction_summary'
    ];

    public function casts()
    {
        return [
            'payment_provider' => PaymentProviderCast::class, 
            'payment_response' => 'json',
            'webhook_response' => 'json',
        ];
    }
    public function billing_payment_transaction()
    {
        return $this->belongsTo(Billing::$billingPaymentTransaction, 'billing_payment_transaction_id');
    }
}