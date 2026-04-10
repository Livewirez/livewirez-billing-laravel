<?php

namespace Livewirez\Billing\Lib\Polar\Traits;

use Carbon\Carbon;
use DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Livewirez\Billing\Billing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Models\BillingOrder;
use Livewirez\Billing\Enums\DeliveryStatus;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Enums\ApiProductTypeKey;
use Livewirez\Billing\Enums\FulfillmentStatus;
use Livewirez\Billing\Models\BillingOrderItem;
use Symfony\Component\HttpFoundation\Response;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Lib\Polar\Events\OrderPaid;
use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Lib\Polar\Enums\OrderStatus;
use Livewirez\Billing\Enums\SubscriptionHistoryType;
use Livewirez\Billing\Lib\Polar\Enums\WebhookEvents;
use Livewirez\Billing\Lib\Polar\Events\OrderCreated;
use Livewirez\Billing\Enums\PaymentTransactionStatus;
use Livewirez\Billing\Lib\Polar\Events\PaymentCreated;
use Livewirez\Billing\Lib\Polar\Events\PaymentUpdated;
use Livewirez\Billing\Lib\Polar\Events\WebhookHandled;
use Livewirez\Billing\Lib\Polar\Events\PaymentRefunded;
use Livewirez\Billing\Lib\Polar\Events\WebhookReceived;
use Livewirez\Billing\Models\BillingPaymentTransaction;
use Livewirez\Billing\Lib\Polar\Events\PaymentCompleted;
use Livewirez\Billing\Enums\SubscriptionTransactionStatus;
use Livewirez\Billing\Lib\Polar\Events\SubscriptionActive;
use Livewirez\Billing\Enums\OrderStatus as MainOrderStatus;
use Livewirez\Billing\Lib\Polar\Events\BenefitGrantCreated;
use Livewirez\Billing\Lib\Polar\Events\BenefitGrantRevoked;
use Livewirez\Billing\Lib\Polar\Events\BenefitGrantUpdated;
use Livewirez\Billing\Lib\Polar\Events\SubscriptionCreated;
use Livewirez\Billing\Lib\Polar\Events\SubscriptionRevoked;
use Livewirez\Billing\Lib\Polar\Events\SubscriptionUpdated;
use Livewirez\Billing\Lib\Polar\Events\SubscriptionCanceled;
use Livewirez\Billing\Enums\SubscriptionEvent as EventEnum;
use Livewirez\Billing\Lib\Polar\Exceptions\InvalidMetadataPayload;
use Livewirez\Billing\Jobs\CancelSubscription as CancelSubscriptionJob;
use Livewirez\Billing\Jobs\ExpireSubscription as ExpireSubscriptionJob;

trait HandlesWebhooks
{
    public function handleWebhook(Request $request): Response
    {
        capture_request_vars($request, __METHOD__);

        $type = $request->input('type');
        $data = $request->input('data');

        WebhookReceived::dispatch($request->input());

        match (WebhookEvents::tryFrom($type)) {
            WebhookEvents::Checkout_Created => $this->handleCheckoutCreated($data),
            WebhookEvents::Order_Created => $this->handleOrderCreated($data),
            WebhookEvents::Order_Paid => $this->handleOrderPaid($data),
            WebhookEvents::Order_Updated => $this->handleOrderUpdated($data),
            WebhookEvents::Subscription_Created => $this->handleSubscriptionCreated($data),
            WebhookEvents::Subscription_Updated  => $this->handleSubscriptionUpdated($data),
            WebhookEvents::Subscription_Active => $this->handleSubscriptionActive($data),
            WebhookEvents::Subscription_Canceled => $this->handleSubscriptionCanceled($data),
            WebhookEvents::Subscription_Revoked => $this->handleSubscriptionRevoked($data),
            WebhookEvents::Benefit_Grant_Created => $this->handleBenefitGrantCreated($data),
            WebhookEvents::Benefit_Grant_Updated => $this->handleBenefitGrantUpdated($data),
            WebhookEvents::Benefit_Grant_Revoked => $this->handleBenefitGrantRevoked($data),
            default => Log::info("Unknown event type: $type"),
        };

        WebhookHandled::dispatch($request->input());
       
        return response()->json(['message' => 'Received']);
    }

    private function handleCheckoutCreated(array $payload): void
    {
        // $billable = $this->resolveBillable($payload);

        Log::info(__METHOD__, [
            'payload' => $payload,
        ]);
    }

    /**
     * Handle the order created event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleOrderCreated(array $payload): void
    {
        Log::info(__METHOD__, [
            'payload' => $payload,
        ]);

        if (isset($payload['metadata'], $payload['metadata']['billing_order_id'], $payload['metadata']['billing_payment_transaction_id'])) {

            $billable = $this->resolveBillable($payload);
            $billingOrderId = $payload['metadata']['billing_order_id'];
            $billingPaymentTransactionId = $payload['metadata']['billing_payment_transaction_id'];

            $order = Billing::$billingOrder::where(['billing_order_id' => $billingOrderId, 'payment_provider' => PaymentProvider::Polar])->first();
            $payment_transaction = Billing::$billingPaymentTransaction::where([
                'billing_payment_transaction_id' => $billingPaymentTransactionId,
                'payment_provider' => PaymentProvider::Polar
            ])->first();

            $order->update([
               'payment_provider_order_id' => $payload['id'],
            ]);


            /** 
             * @see https://docs.polar.sh/api-reference/webhooks/order.created 
             * @see https://docs.polar.sh/api-reference/webhooks/subscription.updated
            */
            switch ($payload['billing_reason']) {
                case 'purchase': // A customer purchases a one-time product. In this case, billing_reason is set to purchase.
                    break;
                case 'subscription_create': // A customer starts a subscription. In this case, billing_reason is set to subscription_create.
                    break;
                case 'subscription_cycle': // A subscription is renewed. In this case, billing_reason is set to subscription_cycle.
                    
                    break;
                case 'subscription_update': // A subscription is upgraded or downgraded with an immediate proration invoice. In this case, billing_reason is set to subscription_update.
                    break;
                default:
                    break;

            }

            PaymentCreated::dispatch($billable, $payment_transaction, $payload); // @phpstan-ignore-line argument.type - Billable is a instance of a model
            OrderCreated::dispatch($billable, $order, $payload);
        }
    }

    /**
     * Handle the order created event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleOrderPaid(array $payload): void
    {
        Log::info(__METHOD__, [
            'payload' => $payload,
        ]);

        if (isset($payload['metadata'], $payload['metadata']['product_type'], $payload['metadata']['billing_order_id'], $payload['metadata']['billing_payment_transaction_id'])) {
            $billable = $this->resolveBillable($payload);
            $billingOrderId = $payload['metadata']['billing_order_id'];
            $billingPaymentTransactionId = $payload['metadata']['billing_payment_transaction_id'];
            $billingSubscriptionTransactionId = data_get($payload, 'metadata.billing_subscription_transaction_id');

            $order = Billing::$billingOrder::with(['billing_order_items'])
                ->where(['billing_order_id' => $billingOrderId, 'payment_provider' => PaymentProvider::Polar])
                ->first();
            $payment_transaction = Billing::$billingPaymentTransaction::where([
                'billing_payment_transaction_id' => $billingPaymentTransactionId,
                'payment_provider' => PaymentProvider::Polar
            ])->first();

             \Illuminate\Support\Facades\Log::debug(__METHOD__, [
                'metadata' => $payload['metadata']
             ]);


            $subscription_transaction = $billingSubscriptionTransactionId !== null ? Billing::$billingSubscriptionTransaction::where([
                'billing_subscription_transaction_id' => $billingSubscriptionTransactionId,
                'payment_provider_checkout_id' => $payload['checkout_id'],
                'payment_provider' => PaymentProvider::Polar
            ])->first() : null;



            switch (ApiProductTypeKey::tryFrom($payload['metadata']['product_type'])) {
                case ApiProductTypeKey::ONE_TIME:
                    if ($order && $payment_transaction) {
                        // Handle the order paid event
                        switch ($payload['status']) {
                            case 'pending':
                                break;
                            case 'paid':
                                if (isset($payload['paid'])) {
        
                                    if ($payload['paid'] === true) {
        
                                        DB::transaction(function () use ($billable, $order, $payment_transaction, $payload) {
                                            $order->update([
                                                'payment_provider_order_id' => $payload['id'],
                                                'payment_provider_transaction_id' => $payload['id'],
                                                'status' => MainOrderStatus::AWAITING_PROCESSING,
                                                'payment_status' => PaymentTransactionStatus::COMPLETED,
                                                'processed_at' => now(),
                                                'metadata' => array_merge($order->metadata ?? [], ['complete_payment_webhook' => $payload])
                                            ]);
        
                                            $order->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->update([
                                                'processed_at' => now(),
                                                'status' => MainOrderStatus::AWAITING_PROCESSING,
                                                'payment_status' => PaymentTransactionStatus::COMPLETED,
                                                'metadata' => array_merge($orderItem->metadata ?? [], ['complete_payment_webhook' => $payload])
                                            ]));
                            
                                            $payment_transaction->update([
                                                'payment_provider_transaction_id' => $payload['id'],
                                                'status' => PaymentTransactionStatus::COMPLETED,
                                                'transacted_at' => now(),
                                                'metadata' => array_merge($payment_transaction->metadata ?? [], ['complete_payment_webhook' => $payload])
                                            ]);
        
                                            $payment_transaction->billing_transaction_data()->create([
                                                'event' => 'order.paid',
                                                'transaction_ref' => $payload['id'],
                                                'payment_provider_transaction_id' => $payload['id'],
                                                'status' => $payload['status'],
                                                'resource_id' => $payload['checkout_id'],
                                                'webhook_id' => $payload['id'],
                                                'receipt_number' => $payload['checkout_id'],
                                                'payment_provider' => PaymentProvider::Polar,
                                                'payment_response' => $payment_transaction->metadata ?? null,
                                                'webhook_response' => $payload
                                            ]);
        
                                            PaymentCompleted::dispatch($billable, $payment_transaction, $payload);
                                            OrderPaid::dispatch($billable, $order, $payload);
                                        });
                                    } else {
                                        DB::transaction(function () use ($order, $payment_transaction, $payload) {
                                            $order->update([
                                                'payment_provider_order_id' => $payload['id'],
                                                'status' => MainOrderStatus::FAILED,
                                                'payment_status' => PaymentStatus::FAILED,
                                                'processed_at' => now(),
                                                'metadata' => array_merge($order->metadata ?? [], ['complete_payment_webhook' => $payload])
                                            ]);
        
                                            $order->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->update([
                                                'processed_at' => now(),
                                                'status' => MainOrderStatus::FAILED,
                                                'payment_status' => PaymentTransactionStatus::FAILED,
                                                'metadata' => array_merge($orderItem->metadata ?? [], ['complete_payment_webhook' => $payload])
                                            ]));
        
                                            $payment_transaction->update([
                                                'status' => PaymentTransactionStatus::FAILED,
                                                'transacted_at' => now(),
                                                'metadata' => array_merge($payment_transaction->metadata ?? [], ['complete_payment_webhook' => $payload])
                                            ]);
                                        });
                                    }
                                }
                
                                // event(new PaymentCompleted($payment));
                                break;
                            case 'refunded':
                            case 'partially_refunded':
                            default:
                                break;
                        }
                    }
                    break;
                case ApiProductTypeKey::SUBSCRIPTION:
                    if ($order && $payment_transaction) {
                        // Handle the order paid event
                        switch ($payload['status']) {
                            case 'pending':
                                break;
                            case 'paid':
                                if (isset($payload['paid'])) {
        
                                    if ($payload['paid'] === true) {
        
                                        DB::transaction(function () use ($billable, $order, $payment_transaction, $payload, $subscription_transaction) {

                                            if (isset($payload['discount_id'], $payload['discount'])) {
                                                $discount = Billing::$billingDiscountCode::find($id =  $payload['discount']['metadata']['billing_discount_code']);

                                                if ($discount) {
                                                    $order->fill([
                                                        'subtotal' => $payload['subtotal_amount'] ?? $order->subtotal,
                                                        'discount' => $payload['discount_amount'] ?? $order->discount,
                                                        'total' => $payload['total_amount'] ?? $order->total,
                                                    ]);

                                                    $order->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->fill([
                                                        'subtotal' => $payload['subtotal_amount'] ?? $orderItem->subtotal,
                                                        'discount' => $payload['discount_amount'] ?? $orderItem->discount,
                                                        'total' => $payload['total_amount'] ?? $orderItem->total,
                                                    ]));
                                                }
                                            }

                                            $order->fill([
                                                'payment_provider_order_id' => $payload['id'],
                                                'payment_provider_transaction_id' => $payload['id'],
                                                'status' => MainOrderStatus::COMPLETED,
                                                'delivery_status' => DeliveryStatus::DELIVERED,
                                                'fulfillment_status' => FulfillmentStatus::FULFILLED,
                                                'payment_status' => PaymentTransactionStatus::COMPLETED,
                                                'processed_at' => now(),
                                                'metadata' => array_merge($order->metadata ?? [], ['complete_subscription_webhook' => $payload])
                                            ]);

                                            $order->save();
        
                                            $order->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->fill([
                                                'processed_at' => now(),
                                                'delivery_status' => DeliveryStatus::DELIVERED,
                                                'fulfillment_status' => FulfillmentStatus::FULFILLED,
                                                'status' => MainOrderStatus::COMPLETED,
                                                'payment_status' => PaymentTransactionStatus::COMPLETED,
                                                'metadata' => array_merge($orderItem->metadata ?? [], ['complete_subscription_webhook' => $payload])
                                            ])->save());
                            
                                            $payment_transaction->update([
                                                'status' => PaymentTransactionStatus::COMPLETED,
                                                'payment_provider_transaction_id' => $payload['id'],
                                                'transacted_at' => now(),
                                                'metadata' => array_merge($payment_transaction->metadata ?? [], ['complete_subscription_webhook' => $payload])
                                            ]);
        
                                            $payment_transaction->billing_transaction_data()->create([
                                                'event' => 'order.paid',
                                                'transaction_ref' => $payload['id'],
                                                'payment_provider_transaction_id' => $payload['id'],
                                                'status' => $payload['status'],
                                                'resource_id' => $payload['checkout_id'],
                                                'webhook_id' => $payload['id'],
                                                'receipt_number' => $order->order_number,
                                                'payment_provider' => PaymentProvider::Polar,
                                                'payment_response' => $payment_transaction->metadata ?? null,
                                                'webhook_response' => $payload
                                            ]);

                                            if ($subscription_transaction) {
                                                $subscription_transaction->update([
                                                    'amount' =>  $payload['total_amount'] ?? $order->total,
                                                    'status' => SubscriptionTransactionStatus::COMPLETED,
                                                    'payment_status' => PaymentStatus::PAID,
                                                    'payment_provider_status' => $payload['status'] ?? 'paid',
                                                    'paid_at' => now() ,
                                                    'resource_id' => $payload['id'],
                                                    'webhook_response' => $payload,
                                                ]);

                                                $subscription_transaction->billing_subscription_events()->create([
                                                    'billing_subscription_id' => $subscription_transaction->billing_subscription_id,
                                                    'type' => EventEnum::TransactionCompleted,
                                                    'triggered_by' => 'WEBHOOK',
                                                    'metadata' => $payload
                                                ]);
                                            }

                                            PaymentCompleted::dispatch($billable, $payment_transaction, $payload);
                                            OrderPaid::dispatch($billable, $order, $payload);
                                        });
                                    } else {
                                        DB::transaction(function () use ($order, $payment_transaction, $payload, $subscription_transaction) {
                                            
                                            if (isset($payload['discount_id'], $payload['discount'])) {
                                                $discount = Billing::$billingDiscountCode::find($id =  $payload['discount']['metadata']['billing_discount_code']);

                                                if ($discount) {
                                                    $order->fill([
                                                        'subtotal' => $payload['subtotal_amount'] ?? $order->subtotal,
                                                        'discount' => $payload['discount_amount'] ?? $order->discount,
                                                        'total' => $payload['total_amount'] ?? $order->total,
                                                    ]);

                                                    $order->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->fill([
                                                        'subtotal' => $payload['subtotal_amount'] ?? $orderItem->subtotal,
                                                        'discount' => $payload['discount_amount'] ?? $orderItem->discount,
                                                        'total' => $payload['total_amount'] ?? $orderItem->total,
                                                    ]));
                                                }
                                            }
                                            
                                            $order->fill([
                                                'payment_provider_order_id' => $payload['id'],
                                                'status' => MainOrderStatus::FAILED,
                                                'payment_status' => PaymentStatus::FAILED,
                                                'delivery_status' => DeliveryStatus::FAILED,
                                                'fulfillment_status' => FulfillmentStatus::UNFULFILLED,
                                                'processed_at' => now(),
                                                'metadata' => array_merge($order->metadata ?? [], ['complete_subscription_webhook' => $payload])
                                            ]);

                                            $order->save();
        
                                            $order->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->update([
                                                'processed_at' => now(),
                                                'status' => MainOrderStatus::FAILED,
                                                'delivery_status' => DeliveryStatus::FAILED,
                                                'fulfillment_status' => FulfillmentStatus::UNFULFILLED,
                                                'payment_status' => PaymentTransactionStatus::FAILED,
                                                'metadata' => array_merge($orderItem->metadata ?? [], ['complete_subscription_webhook' => $payload])
                                            ]));
        
                                            $payment_transaction->update([
                                                'status' => PaymentTransactionStatus::FAILED,
                                                'transacted_at' => now(),
                                                'metadata' => array_merge($payment_transaction->metadata ?? [], ['complete_subscription_webhook' => $payload])
                                            ]);

                                            $payment_transaction->billing_transaction_data()->create([
                                                'event' => 'order.paid',
                                                'transaction_ref' => $payload['id'],
                                                'payment_provider_transaction_id' => $payload['id'],
                                                'status' => $payload['status'],
                                                'resource_id' => $payload['checkout_id'],
                                                'webhook_id' => $payload['id'],
                                                'receipt_number' => $order->order_number,
                                                'payment_provider' => PaymentProvider::Polar,
                                                'payment_response' => $payment_transaction->metadata ?? null,
                                                'webhook_response' => $payload
                                            ]);

                                            if ($subscription_transaction) {
                                                $subscription_transaction->update([
                                                    'amount' => $payload['total_amount'] ?? $order->total,
                                                    'status' => SubscriptionTransactionStatus::FAILED,
                                                    'payment_status' => PaymentStatus::UNPAID,
                                                    'payment_provider_status' => $payload['status'],
                                                    'resource_id' => $payload['id'],
                                                    'webhook_response' => $payload,
                                                ]);

                                                $subscription_transaction->billing_subscription_events()->create([
                                                    'type' => EventEnum::TransactionFailure,
                                                    'billing_subscription_id' => $subscription_transaction->billing_subscription_id,
                                                    'triggered_by' => 'WEBHOOK',
                                                    'metadata' => $payload
                                                ]);
                                            }
                                        });
                                    }
                                }
                                break;
                            case 'refunded':
                                DB::transaction(function () use ($billable, $order, $payment_transaction, $payload, $subscription_transaction) {

                                    if (isset($payload['discount_id'], $payload['discount'])) {
                                        $discount = Billing::$billingDiscountCode::find($id =  $payload['discount']['metadata']['billing_discount_code']);

                                        if ($discount) {
                                            $order->fill([
                                                'subtotal' => $payload['subtotal_amount'] ?? $order->subtotal,
                                                'discount' => $payload['discount_amount'] ?? $order->discount,
                                                'total' => $payload['total_amount'] ?? $order->total,
                                            ]);

                                            $order->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->fill([
                                                'subtotal' => $payload['subtotal_amount'] ?? $orderItem->subtotal,
                                                'discount' => $payload['discount_amount'] ?? $orderItem->discount,
                                                'total' => $payload['total_amount'] ?? $orderItem->total,
                                            ]));
                                        }
                                    }

                                    $order->fill([
                                        'payment_provider_order_id' => $payload['id'],
                                        'status' => MainOrderStatus::REFUNDED,
                                        'delivery_status' => DeliveryStatus::AWAITING_RETURN,
                                        'fulfillment_status' => FulfillmentStatus::FULFILLED,
                                        'payment_status' => PaymentTransactionStatus::REFUNDED,
                                        'processed_at' => now(),
                                        'metadata' => array_merge($order->metadata ?? [], ['complete_subscription_webhook' => $payload])
                                    ]);

                                    $order->save();

                                    $order->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->fill([
                                        'processed_at' => now(),
                                        'status' => MainOrderStatus::REFUNDED,
                                        'delivery_status' => DeliveryStatus::AWAITING_RETURN,
                                        'fulfillment_status' => FulfillmentStatus::FULFILLED,
                                        'payment_status' => PaymentTransactionStatus::REFUNDED,
                                        'metadata' => array_merge($orderItem->metadata ?? [], ['complete_subscription_webhook' => $payload])
                                    ])->save());
                    
                                    $payment_transaction->update([
                                        'status' => PaymentTransactionStatus::REFUNDED,
                                        'payment_provider_transaction_id' => $payload['id'],
                                        'transacted_at' => now(),
                                        'metadata' => array_merge($payment_transaction->metadata ?? [], ['complete_subscription_webhook' => $payload])
                                    ]);

                                    if ($subscription_transaction) {
                                        $subscription_transaction->update([
                                            'amount' =>  $payload['total_amount'] ?? $order->total,
                                            'status' => SubscriptionTransactionStatus::REFUNDED,
                                            'payment_status' => PaymentStatus::REFUNDED,
                                            'payment_provider_status' => $payload['status'],
                                            'resource_id' => $payload['id'],
                                            'webhook_response' => $payload,
                                        ]);

                                        $subscription_transaction->billing_subscription_events()->create([
                                            'billing_subscription_id' => $subscription_transaction->billing_subscription_id,
                                            'type' => EventEnum::TransactionRefunded,
                                            'triggered_by' => 'WEBHOOK',
                                            'metadata' => $payload
                                        ]);
                                    }

                                    event(new PaymentRefunded($billable, $payment_transaction, $payload));
                                });
                                        
                            case 'partially_refunded':
                            default:
                                break;
                        }
                    }
                    
                    break;
                default:
                    return;
            }

        }
    }

    /**
     * Handle the order updated event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleOrderUpdated(array $payload): void
    {
     
        Log::info(__METHOD__, [
            'payload' => $payload,
        ]);

        //PaymentUpdated::dispatch($billable, $order, $payload, $isRefunded); // @phpstan-ignore-line argument.type - Billable is a instance of a model
    }

    /**
     * Handle the subscription created event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleSubscriptionCreated(array $payload): void
    {
        $customerMetadata = $payload['customer']['metadata'];
        $billable = $this->resolveBillable($payload);

        Log::info(__METHOD__, [
            'payload' => $payload,
            'billable' => $billable,
            'customerMetadata' => $customerMetadata
        ]);

        $subscription = $billable->billing_subscription()->where([
            'payment_provider_checkout_id' => $payload['checkout_id'],
            'payment_provider' => PaymentProvider::Polar
        ])->sole();

        $subscription->update([
            'payment_provider_subscription_id' => $payload['id'],
        ]);

        $subscription->billing_subscription_transactions()->where([
            'payment_provider_checkout_id' => $payload['checkout_id'],
            'payment_provider' => PaymentProvider::Polar
        ])->update([
            'payment_provider_subscription_id' => $payload['id'],
        ]);

        if ($billable && $billable->polar_billable_provider_data->provider_user_id === null) { // @phpstan-ignore-line property.notFound - the property is found in the billable model
            $billable->polar_billable_provider_data->update(['payment_provider_user_id' => $payload['customer_id']]); // @phpstan-ignore-line property.notFound - the property is found in the billable model
        }

        SubscriptionCreated::dispatch($billable, $subscription, $payload); // @phpstan-ignore-line argument.type - Billable is a instance of a model
    }

    /**
     * Handle the subscription updated event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleSubscriptionUpdated(array $payload): void
    {
        if (!($subscription = $this->findSubscription($payload['id'])) instanceof Billing::$billingSubscription) {
            return;
        }

        // $subscription->sync($payload);
        Log::info(__METHOD__, [
            'payload' => $payload,
        ]);

        if ($payload['status'] === 'canceled') {
            $this->handleSubscriptionCanceled($payload);
        }

        

        SubscriptionUpdated::dispatch($subscription->billable, $subscription, $payload); // @phpstan-ignore-line argument.type - Billable is a instance of a model
    }

    /**
     * Handle the subscription active event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleSubscriptionActive(array $payload): void
    {
        $customerMetadata = $payload['customer']['metadata'];
        $billable = $this->resolveBillable($payload);

        $subscription = $this->findSubscription($payload['id']) ?? $billable->billing_subscription()->where([
            'payment_provider_checkout_id' => $payload['checkout_id'],
            'payment_provider' => PaymentProvider::Polar
        ])->sole();


        if (! $subscription instanceof BillingSubscription) {
            return;
        }

        // $subscription->sync($payload);
        Log::info(__METHOD__, [
            'payload' => $payload,
        ]);

        $subscription->update([
            'payment_provider_subscription_id' => $payload['id'],
            'metadata' => array_merge_recursive(
                $subscription->metadata,
                ['complete_subscription_webhook' => $payload]
            ),
            ...isset($payload['current_period_start']) ? ['starts_at' => CarbonImmutable::parse($payload['current_period_start'])] : [],
            ...isset($payload['current_period_end']) ? ['ends_at' => CarbonImmutable::parse($payload['current_period_end'])] : [],
        ]);

        $key = class_basename($customerMetadata['billable_type']).'_'.$customerMetadata['billable_id'].'_polar_subscription_active';

        Cache::put($key, [
            'billable_id' => $customerMetadata['billable_id'],
            'billable_type' => $customerMetadata['billable_type'],
            'payment_provider' => PaymentProvider::Polar->value,
            'payment_provider_checkout_id' => $payload['checkout_id'],
            'payment_provider_subscription_id' => $payload['id'],
            'status' => SubscriptionStatus::ACTIVE->value,
            'metadata' => ['complete_subscription' => $payload]
        ], now()->addMinutes(5));

        $subscription->billing_subscription_transactions()->where([
            'payment_provider_checkout_id' => $payload['checkout_id'],
            'payment_provider' => PaymentProvider::Polar
        ])->update([
            'payment_provider_subscription_id' => $payload['id'],
        ]);

        if (isset($payload['discount_id'], $payload['discount'])) {

            $discount = Billing::$billingDiscountCode::find($id =  $payload['discount']['metadata']['billing_discount_code']);

            if ($discount) {
                $subscription->billing_subscription_discounts()->create([
                    'billing_discount_code_id' => $id,
                    'discount_amount' => $payload['amount'],
                ]);
    
                // Increment usage count
                $discount->increment('used_count');
            }
        }

        if ($billable && $billable->polar_billable_provider_data->provider_user_id === null) { // @phpstan-ignore-line property.notFound - the property is found in the billable model
            $billable->polar_billable_provider_data->update(['payment_provider_user_id' => $payload['customer_id']]); // @phpstan-ignore-line property.notFound - the property is found in the billable model
        }


        SubscriptionActive::dispatch($subscription->billable, $subscription, $payload); // @phpstan-ignore-line argument.type - Billable is a instance of a model
    }

    /**
     * Handle the subscription canceled event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleSubscriptionCanceled(array $payload): void
    {
        if (!($subscription = $this->findSubscription($payload['id'])) instanceof BillingSubscription) {
            return;
        }

        // $subscription->sync($payload);
        Log::info(__METHOD__, [
            'payload' => $payload,
        ]);

        switch ($payload['status']) {
            case 'active':
                if (isset($payload['cancel_at_period_end']) && $payload['cancel_at_period_end'] === true) {

                    $subscription->update([
                        'status' => SubscriptionStatus::CANCELLATION_PENDING,
                        'canceled_at' => CarbonImmutable::parse($payload['canceled_at']),
                        ...isset($payload['ends_at']) ? ['ends_at' => CarbonImmutable::parse($payload['ends_at'])] : [],
                        ...isset($payload['ended_at']) ? ['ended_at' => CarbonImmutable::parse($payload['ended_at'])] : [],
                        'paused_at' => null,
                        'expired_at' => null,
                        'resumed_at' => null,
                        'processed_at' => now(),
                    ]);

                    ExpireSubscriptionJob::dispatch($subscription->id)
                        ->delay(CarbonImmutable::parse($subscription->ends_at));

                    $subscription_event = $subscription->billing_subscription_events()->create( [
                        'type' => EventEnum::Cancellation,
                        'triggered_by' => 'WEBHOOK',
                        'description' => 'Subscription Cancellation Request from Webhook',
                        'metadata' => $payload
                    ]);
                }

                break;
            case 'canceled':
                if (isset($payload['cancel_at_period_end']) && $payload['cancel_at_period_end'] === false) {
                    $subscription->update([
                        'canceled_at' => CarbonImmutable::parse($payload['canceled_at']),
                        ...isset($payload['ends_at']) ? ['ends_at' => CarbonImmutable::parse($payload['ends_at'])] : [],
                        ...isset($payload['ended_at']) ? ['ended_at' => CarbonImmutable::parse($payload['ended_at'])] : [],
                        'status' => SubscriptionStatus::CANCELED,
                        'is_active' => false,
                        'paused_at' => null,
                        'expired_at' => null,
                        'resumed_at' => null,
                        'processed_at' => now(),
                    ]);

                    $subscription_event = $subscription->billing_subscription_events()->create( [
                        'type' => EventEnum::Cancellation,
                        'triggered_by' => 'WEBHOOK',
                        'description' => 'Subscription Cancellation Request from Webhook',
                        'metadata' => $payload
                    ]);
                }
                break;
            default:
                break;
        }

        SubscriptionCanceled::dispatch($subscription->billable, $subscription, $payload); // @phpstan-ignore-line argument.type - Billable is a instance of a model
    }

    /**
     * Handle the subscription revoked event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleSubscriptionRevoked(array $payload): void
    {
        if (!($subscription = $this->findSubscription($payload['id'])) instanceof Billing::$billingSubscription) {
            return;
        }

        // $subscription->sync($payload);
        Log::info(__METHOD__, [
            'payload' => $payload,
        ]);

        SubscriptionRevoked::dispatch($subscription->billable, $subscription, $payload); // @phpstan-ignore-line argument.type - Billable is a instance of a model
    }

    /**
     * Handle the benefit grant created event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleBenefitGrantCreated(array $payload): void
    {
        $billable = $this->resolveBillable($payload);

        BenefitGrantCreated::dispatch($billable, $payload); // @phpstan-ignore-line argument.type - Billable is a instance of a model
    }

    /**
     * Handle the benefit grant updated event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleBenefitGrantUpdated(array $payload): void
    {
        $billable = $this->resolveBillable($payload);

        BenefitGrantUpdated::dispatch($billable, $payload); // @phpstan-ignore-line argument.type - Billable is a instance of a model
    }

    /**
     * Handle the benefit grant revoked event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleBenefitGrantRevoked(array $payload): void
    {
        $billable = $this->resolveBillable($payload);

        BenefitGrantRevoked::dispatch($billable, $payload); // @phpstan-ignore-line argument.type - Billable is a instance of a model
    }

    /**
     * Resolve the billable from the payload.
     *
     * @param  array<string, mixed>  $payload
     * @return ?Billable
     *
     * @throws DomainException
     */
    private function resolveBillable(array $payload) // @phpstan-ignore-line return.trait - Billable is used in the user final code
    {
        $customerData = $payload['customer'] ?? null;
        $customerMetadata = $payload['customer']['metadata'] ?? null;

        if (!isset($customerData) || !isset($customerData['id']) || !isset($customerMetadata) || !is_array($customerMetadata) || !isset($customerMetadata['billable_id'], $customerMetadata['billable_type'])) {
           // throw new DomainException('Invalid Metadata');

           return;
        }

        return $this->findOrCreateCustomer(
            $customerMetadata['billable_id'],
            (string) $customerMetadata['billable_type'],
            (string) $customerData['id'],
        );
    }

    /**
     * Find or create a customer.
     *
     * @return Billable
     */
    private function findOrCreateCustomer(int|string $billableId, string $billableType, string $customerId) // @phpstan-ignore-line return.trait - Billable is used in the user final code
    {
        $billable = $billableType::find((int) $billableId);

        $billableProviderData = $billable->billable_payment_provider_information()->firstOrCreate([
            'payment_provider' => PaymentProvider::Polar,
            'payment_provider_user_id' => $customerId,
        ], [
            'payment_provider_user_id' => $customerId,
        ]);

        $billable->polar_billable_provider_data = $billableProviderData;

        return $billable;
    }

    private function findSubscription(string $subscriptionId): ?BillingSubscription
    {
        return Billing::$billingSubscription::firstWhere([
            'payment_provider_subscription_id' =>  $subscriptionId,
            'payment_provider' => PaymentProvider::Polar
        ]);
    }

    private function findOrder(string $orderId): ?BillingOrder
    {
        return Billing::$billingOrder::firstWhere([
            'payment_provider_order_id', $orderId,
            'payment_provider' => PaymentProvider::Polar
        ]);
    }
}
