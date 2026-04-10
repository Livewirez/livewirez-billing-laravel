<?php

namespace Livewirez\Billing\Lib\PayPal\Traits;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Livewirez\Billing\Billing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Livewirez\Billing\Enums\OrderStatus;
use Livewirez\Billing\Lib\PayPal\PayPal;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Enums\RequestMethod;
use Livewirez\Billing\Models\BillingOrder;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingOrderItem;
use Symfony\Component\HttpFoundation\Response;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Livewirez\Billing\Events\SubscriptionRenewed;
use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Enums\SubscriptionHistoryType;
use Livewirez\Billing\Enums\PaymentTransactionStatus;
use Livewirez\Billing\Events\SubscriptionRenewalFailed;
use Livewirez\Billing\Enums\SubscriptionTransactionStatus;
use Livewirez\Billing\Enums\SubscriptionEvent as EventEnum;
use Livewirez\Billing\Jobs\CancelSubscription as CancelSubscriptionJob;
use Livewirez\Billing\Jobs\ExpireSubscription as ExpireSubscriptionJob;

trait HandlesWebhooks
{
    public function handleWebhook(Request $request): Response
    {
        $payload = $request->input();
        $headers = $request->headers->all();
        
        // Verify webhook signature
        $verified = $this->verifyWebhookSignature($request);
        
        if (!$verified) {
            \Illuminate\Support\Facades\Log::error('PayPal webhook signature verification failed');
            return response()->json(['message' => 'Bad Request'], 400);
        }

        \Illuminate\Support\Facades\Log::info('PayPal webhook received', [
            'payload' => $payload,
            'headers' => $headers,
        ]);

        
        switch ($payload['event_type']) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                $this->handlePaymentCompleted($payload);
                break;
            case 'PAYMENT.CAPTURE.DENIED':
                $this->handlePaymentFailed($payload);
                break;
            case 'CHECKOUT.ORDER.APPROVED':
                $this->handleCheckoutOrderApproved($payload);
                break;


            /**
             *  Subscriptions @source https://developer.paypal.com/api/rest/webhooks/event-names/#link-subscriptions
            */
            //  A subscription is cancelled. (Cancel subscription)
            case 'BILLING.SUBSCRIPTION.CANCELLED':
                $this->handleSubscriptionCanceled($payload);
                break;
            case 'PAYMENT.SALE.COMPLETED':
                $this->handlePaymentSaleCompleted($payload);
                break;
            case 'PAYMENT.SALE.REFUNDED':
                break;
            case 'PAYMENT.SALE.REVERSED':
                break;
            // A subscription is created (Create subscription)
            case 'BILLING.SUBSCRIPTION.CREATED':
                break;
            // A subscription is activated.	(Activate subscription)
            case 'BILLING.SUBSCRIPTION.ACTIVATED':
                $this->handleSubscriptionActivated($payload);	
                break;
             // A subscription is updated.	(Update subscription)
            case 'BILLING.SUBSCRIPTION.UPDATED':	
                break;
             // A subscription expires.	(Show subscription details)
            case 'BILLING.SUBSCRIPTION.EXPIRED':
                $this->handleSubscriptionExpired($payload);		
                break;
             // A subscription is suspended. (Suspend subscription)
            case 'BILLING.SUBSCRIPTION.SUSPENDED':
                $this->handleSubscriptionSuspended($payload);		
                break;
             // Payment failed on subscription.
            case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
                $this->handleSubscriptionPaymentFailed($payload);	
                break;

            /**
             * Payment Method Tokens
             * 
             * @source https://developer.paypal.com/api/rest/webhooks/event-names/#link-paymentmethodtokens
             * 
             */
            // A payment token is created to save a payment method.	Cards and PayPal
            case 'VAULT.PAYMENT-TOKEN.CREATED':
                break;
            // A payment token is deleted. The payer's payment method is no longer saved to the PayPal vault.	Cards and PayPal	
            case 'VAULT.PAYMENT-TOKEN.DELETED':
                break;
            // A request to delete a payment token has been submitted to the Payment Method Tokens API.
            case 'VAULT.PAYMENT-TOKEN.DELETION-INITIATED':
                break;	
        }

        return response()->json(['message' => 'Received']);
    }


    private function verifyWebhookSignature(Request $request): bool
    {
        // Implementation for PayPal webhook signature verification
        // This is a simplified version - implement proper verification
        return hash_equals(
            hash_hmac(
                'sha256', 
                 config('billing.providers.paypal.paypal_webhook_secret_value'),
                sha1(config('billing.providers.paypal.paypal_webhook_secret_key'))
            ), 
            (string) $request->query('signature')
        );
    }

    private function handleCheckoutOrderApproved(array $payload) 
    {
        DB::transaction(function () use ($payload) { 
            $resource = $payload['resource'];
            $order_id = $resource['id'];

            switch ($resource['intent']) {
                case 'CAPTURE':
                    $order = Billing::$billingOrder::with([
                        'billing_order_items',
                        'billing_payment_transaction'
                    ])
                    ->where([
                        'payment_provider_order_id' => $order_id,
                        'payment_provider' => PaymentProvider::PayPal
                    ])->first();

                    if (! $order) return;

                    $order?->update([
                        'payment_provider_checkout_id' => $order_id,
                        'payment_status' => PaymentStatus::APPROVED, 
                        'status' => OrderStatus::AWAITING_PROCESSING,
                        'processed_at' => now(),
                        'metadata' => array_merge($order->metadata, ['approved_payment_webhook' => $resource])
                    ]);

                    $captureLink = array_find(
                        $resource['links'] ?? [], 
                        fn (array $link) => $link['rel'] === 'capture'
                    );

                    if ($captureLink) {

                        $response = PayPal::makeRequestFromUrl(
                            $captureLink['href'],
                            [
                                'application_context' => [
                                    "return_url" => $this->config['payment_return_url'] ?? route($this->config['payment_return_url_name']),
                                    "cancel_url" => $this->config['payment_cancel_url'] ?? route($this->config['payment_cancel_url_name']),
                                ]
                                ],
                            method: RequestMethod::from($captureLink['method'])
                        );
                    }
                    return;
                default:
                    return;
            }

        });
    }


    private function handlePaymentCompleted(array $payload)
    {
        DB::transaction(function () use ($payload) {    
            $resource = $payload['resource'];
            $order_id = $resource['supplementary_data']['related_ids']['order_id'];

            $order = Billing::$billingOrder::with([
                'billing_order_items',
                'billing_payment_transaction'
            ])
            ->where([
                'payment_provider_order_id' => $order_id,
                'payment_provider' => PaymentProvider::PayPal
            ])->first();

            if (! $order) return;
        
            $order->update([
                'payment_provider_transaction_id' => $resource['id'],
                'payment_provider_checkout_id' => $order_id,
                'payment_status' => PaymentStatus::COMPLETED, 
                'status' => OrderStatus::AWAITING_PROCESSING,
                'processed_at' => now(),
                'metadata' => array_merge($order->metadata, ['complete_payment_webhook' => $resource])
            ]);

            $order->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->update([
                'processed_at' => now(),
                'status' => OrderStatus::AWAITING_PROCESSING,
                'payment_status' => PaymentTransactionStatus::COMPLETED,
                'metadata' => array_merge($orderItem->metadata ?? [], ['complete_payment_webhook' => $resource])
            ]));

            $order->billing_payment_transaction->update([
                'payment_provider_transaction_id' => $resource['id'],
                'payment_provider_checkout_id' => $order_id,
                'status' => PaymentTransactionStatus::COMPLETED,
                'transacted_at' => now(),
                'metadata' => array_merge($order->billing_payment_transaction->metadata ?? [], ['complete_payment_webhook' => $resource])
            ]);

            $order->billing_payment_transaction->billing_transaction_data()->create([
                'event' => $payload['event_type'],
                'transaction_summary' => $payload['summary'],
                'transaction_ref' => $order_id,
                'payment_provider_transaction_id' => $resource['id'],
                'status' => $resource['status'],
                'resource_id' => $resource['id'],
                'webhook_id' => $payload['id'],
                'receipt_number' => $resource['id'],
                'payment_provider' => PaymentProvider::PayPal,
                'payment_response' => $order->billing_payment_transaction->metadata ?? null,
                'webhook_response' => $payload
            ]);
        });
    }

    private function handlePaymentFailed(array $payload)
    {
        DB::transaction(function () use ($payload) {    
            $resource = $payload['resource'];
            $order_id = $resource['supplementary_data']['related_ids']['order_id'];
    
            $order = Billing::$billingOrder::with([
                'billing_order_items',
                'billing_payment_transaction'
            ])->where([
                'payment_provider_order_id' => $order_id,
                'payment_provider' => PaymentProvider::PayPal
            ])->first();
    
            $order->update([
                'processed_at' => now(),
                'payment_provider_transaction_id' => $resource['id'],
                'status' => OrderStatus::FAILED,
                'payment_status' => PaymentStatus::FAILED,
                'metadata' => array_merge($order->metadata ?? [], ['complete_payment_webhook' => $resource])
            ]);
    
            $order->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->update([
                'processed_at' => now(),
                'status' => OrderStatus::FAILED,
                'payment_status' => PaymentStatus::FAILED,
                'metadata' => array_merge($orderItem->metadata ?? [], ['complete_payment_webhook' => $resource])
            ]));
    
            $order->billing_payment_transaction->update([
                'status' => PaymentTransactionStatus::FAILED,
                'transacted_at' => now(),
                'metadata' => array_merge($order->billing_payment_transaction->metadata ?? [], ['complete_payment_webhook' => $resource])
            ]);

            $order->billing_payment_transaction->billing_transaction_data()->create([
                'event' => $payload['event_type'],
                'transaction_summary' => $payload['summary'],
                'transaction_ref' => $order_id,
                'payment_provider_transaction_id' => $resource['id'],
                'status' => $resource['status'],
                'resource_id' => $resource['id'],
                'webhook_id' => $payload['id'],
                'receipt_number' => $resource['id'],
                'payment_provider' => PaymentProvider::PayPal,
                'payment_response' => $order->billing_payment_transaction->metadata ?? null,
                'webhook_response' => $payload
            ]);
        });
    }

    protected function handleSubscriptionCanceled(array $payload)
    {
        DB::transaction(function () use ($payload) { 

            $resource = $payload['resource'];

            $subscription = Billing::$billingSubscription::where([
                'payment_provider_subscription_id' => $resource['id'],
                'payment_provider' => PaymentProvider::PayPal
            ])->first();
            
            $subscription?->update([
                'status' => SubscriptionStatus::CANCELLATION_PENDING,
                'metatdata' => array_merge($subscription->metadata, ['cancel_subscription_webhook' => $payload]), 
                'canceled_at' => now(),
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
        });
    }


    protected function handleSubscriptionRenewed(BillingSubscription $subscription, ?string $paymentProviderSubscriptionId = null): void
    {
        $subscription->loadMissing([
            'billing_plan',
            'billing_plan_price'
        ]);

        $paymentProviderSubscriptionId ??= $subscription->payment_provider_subscription_id;

        $previous_end = $subscription->ends_at;

        $start = now();

        /** @var SubscriptionInterval */
        $interval = $subscription->billing_plan_price->interval;
        $count = $subscription->billing_plan_price->billing_interval_count;

        $end = $interval->calculateNextInterval($start, $count);

        $subscription->update([
            'status' => SubscriptionStatus::ACTIVE,
            'starts_at' => $start,
            'ends_at' => $end,
            'ended_at' => $previous_end,
            'expired_at' => null,
            'paused_at' => null,
            'canceled_at' => null,
            'renewed_at' => null,
        ]);

        $subscription_event = $subscription->billing_subscription_events()->create( [
            'type' => EventEnum::Renewal,
            'triggered_by' => 'WEBHOOK',
            'description' => 'Subscription Cancellation Request from Webhook',
        ]);

        event(new SubscriptionRenewed($subscription));

    } 

    public function handleOrderReplication(BillingSubscription $subscription, BillingOrder $order, array $payload): void
    {
        DB::transaction(function () use ($subscription, $order, $payload) {
            $resource = $payload['resource'];
            $subscription_id = $resource['billing_agreement_id'];
            $order_id = $resource['id'];

            $newOrder = $order->replicate(['billing_order_id']);

            $order->loadMissing([ 
                'billing_order_items',
                'billing_payment_transaction'
            ]);

            $newOrder?->fill([
                'billing_order_id' => Str::uuid(),
                'payment_provider_transaction_id' => $order_id,
                'payment_provider_order_id' => $order_id,
                'payment_status' => PaymentStatus::COMPLETED, 
                'status' => OrderStatus::COMPLETED,
                'processed_at' => now(),
                'metadata' => array_merge($newOrder->metadata ?? [], ['complete_payment_webhook' => $resource])
            ]);

            $newOrder->save();

            $newOrderItems = $order->billing_order_items->map(
                fn (BillingOrderItem $orderItem) => $orderItem->replicate(['billing_order_item_id'])
            );

            $newOrderItems->each(function (BillingOrderItem $newOrderItem) use ($newOrder, $resource) {

                $newOrderItem->fill([
                    'billing_order_item_id' => Str::uuid(),
                    'processed_at' => now(),
                    'status' => OrderStatus::COMPLETED,
                    'payment_status' => PaymentTransactionStatus::COMPLETED,
                    'metadata' => array_merge($newOrderItem->metadata ?? [], ['complete_payment_webhook' => $resource])
                ]);

                $newOrderItem->billing_order()->associate($newOrder);
                $newOrderItem->save();
            }); 

            $newPaymentTransaction = $order?->billing_payment_transaction->replicate(['billing_payment_transaction_id']);


            $newPaymentTransaction->fill([
                'billing_payment_transaction_id' => Str::uuid(),
                'payment_provider_checkout_id' => $resource['id'],
                'payment_provider_transaction_id' => $order_id,
                'status' => PaymentTransactionStatus::COMPLETED,
                'transacted_at' => now(),
                'metadata' => array_merge($newPaymentTransaction->metadata ?? [], ['complete_payment_webhook' => $resource])
            ]);

            $newPaymentTransaction->billing_order()->associate($newOrder);
            $newPaymentTransaction->save();

            $newPaymentTransaction->billing_transaction_data()->create([
                'event' => $payload['event_type'],
                'transaction_summary' => $payload['summary'],
                'transaction_ref' => $subscription_id,
                'payment_provider_transaction_id' => $order_id,
                'status' => $resource['status'],
                'resource_id' => $order_id,
                'webhook_id' => $payload['id'],
                'receipt_number' => $order_id,
                'payment_provider' => PaymentProvider::PayPal,
                'payment_response' => $newPaymentTransaction->metadata ?? null,
                'webhook_response' => $payload
            ]);

            $this->handleSubscriptionRenewed($subscription, $subscription_id);
        });

    } 

    /**
     * @after 'BILLING.SUBSCRIPTION.CREATED'
     * @before 'BILLING.SUBSCRIPTION.ACTIVE'
     */
    public function handlePaymentSaleCompleted(array $payload)
    {
        DB::transaction(function () use ($payload) { 
            $resource = $payload['resource'];
            $subscription_id = $resource['billing_agreement_id'];
            $order_id = $resource['id'];

            $subscription = Billing::$billingSubscription::where([
                'payment_provider_subscription_id' => $subscription_id,
                'payment_provider' => PaymentProvider::PayPal
            ])->first();

            if (! $subscription) return;

            $order = $subscription->billing_orders()->with([
                'billing_order_items',
                'billing_payment_transaction'
            ])
            ->where([
                'payment_provider_checkout_id' => $subscription_id,
                'payment_provider' => PaymentProvider::PayPal
            ])->first();

            if (! $order) return;

            $startTime = CarbonImmutable::parse($resource['create_time']);

            if (
                $startTime->greaterThan($subCreationDate = $subscription->created_at)
                && $startTime->diffInDays($subCreationDate) >= 15
            ) {
                return $this->handleOrderReplication($subscription, $order, $payload);
            }
        
            $order?->update([
                'payment_provider_transaction_id' => $order_id,
                'payment_provider_order_id' => $order_id,
                'payment_status' => PaymentStatus::COMPLETED, 
                'status' => OrderStatus::COMPLETED,
                'processed_at' => now(),
                'metadata' => array_merge($order->metadata, ['complete_payment_webhook' => $resource])
            ]);

            $order?->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->update([
                'processed_at' => now(),
                'status' => OrderStatus::COMPLETED,
                'payment_status' => PaymentTransactionStatus::COMPLETED,
                'metadata' => array_merge($orderItem->metadata ?? [], ['complete_payment_webhook' => $resource])
            ]));

            $order?->billing_payment_transaction->update([
                'payment_provider_checkout_id' => $subscription_id,
                'payment_provider_transaction_id' => $order_id,
                'status' => PaymentTransactionStatus::COMPLETED,
                'transacted_at' => now(),
                'metadata' => array_merge($order->billing_payment_transaction->metadata ?? [], ['complete_payment_webhook' => $resource])
            ]);

            $order?->billing_payment_transaction->billing_transaction_data()->create([
                'event' => $payload['event_type'],
                'transaction_summary' => $payload['summary'],
                'transaction_ref' => $subscription_id,
                'payment_provider_transaction_id' => $order_id,
                'status' => $resource['status'] ?? $resource['state'] ?? 'completed',
                'resource_id' => $order_id,
                'webhook_id' => $payload['id'],
                'receipt_number' => $order_id,
                'payment_provider' => PaymentProvider::PayPal,
                'payment_response' => $order->billing_payment_transaction->metadata ?? null,
                'webhook_response' => $payload
            ]);

            $key = 'paypal_subscription_transaction_' . $subscription->id;

            $subscription_transaction_id = data_get(Cache::get($key, []), 'billing_subscription_transaction_id');

            if ($subscription_transaction_id) {

                $subscription_transaction = Billing::$billingSubscriptionTransaction::find($subscription_transaction_id);

                $subscription_transaction->update([
                    'payment_provider_checkout_id' => $order_id,
                    'status' => SubscriptionTransactionStatus::COMPLETED,
                    'payment_status' => PaymentStatus::PAID,
                    'payment_provider_status' => $resource['status'] ?? $resource['state'] ?? 'completed',
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

                Cache::forget($key);
            }
        });

    }

    protected function handleSubscriptionActivated(array $payload)
    {
        DB::transaction(function () use ($payload) { 
            $resource = $payload['resource'];

            $subscription = Billing::$billingSubscription::where([
                'payment_provider_subscription_id' => $resource['id'],
                'payment_provider' => PaymentProvider::PayPal
            ])->first();

            $subscription?->update([
                'status' => SubscriptionStatus::ACTIVE,
                'payment_provider_status' => $resource['status'] ?? 'DEFAULT',
                'metadata' => array_merge($subscription->metadata ?? [], ['complete_payment_webhook' => $resource])
            ]);
        });
    }

    protected function handleSubscriptionExpired(array $payload)
    {
        DB::transaction(function () use ($payload) { 
            $resource = $payload['resource'];

            $subscription = Billing::$billingSubscription::where([
                'payment_provider_subscription_id' => $resource['id'],
                'payment_provider' => PaymentProvider::PayPal
            ])->first();

            $subscription->update([
                'status' => SubscriptionStatus::EXPIRED,
                'metatdata' => array_merge($subscription->metadata, ['expired_subscription_webhook' => $payload]), 
                'canceled_at' => null,
                'paused_at' => null,
                'resumed_at' => null,
                'expired_at' => now(),
                'processed_at' => now(),
            ]);

            $subscription_event = $subscription->billing_subscription_events()->create( [
                'type' => EventEnum::Expiration,
                'triggered_by' => 'WEBHOOK',
                'description' => 'Subscription Expired Request from Webhook',
                'metadata' => $payload
            ]);
            
        });
    }


    protected function handleSubscriptionSuspended(array $payload)
    {
        DB::transaction(function () use ($payload) { 
            $resource = $payload['resource'];

            $subscription = Billing::$billingSubscription::where([
                'payment_provider_subscription_id' => $resource['id'],
                'payment_provider' => PaymentProvider::PayPal
            ])->first();

            $subscription->update([
                'status' => SubscriptionStatus::PAUSED,
                'metatdata' => array_merge($subscription->metadata, ['paused_subscription_webhook' => $payload]), 
                'canceled_at' => null,
                'paused_at' => now(),
                'expired_at' => null,
                'resumed_at' => null,
                'processed_at' => now(),
            ]);

            $subscription_event = $subscription->billing_subscription_events()->create( [
                'type' => EventEnum::Pause,
                'triggered_by' => 'WEBHOOK',
                'description' => 'Subscription Pause Request from Webhook',
                'metadata' => $payload
            ]);
        });
    }

    public function handleSubscriptionPaymentFailed(array $payload)
    {
        DB::transaction(function () use ($payload) { 
            $resource = $payload['resource'];
            $subscription_id = $resource['billing_agreement_id'];
            $order_id = $resource['id'];

            $subscription = Billing::$billingSubscription::where([
                'payment_provider_subscription_id' => $subscription_id,
                'payment_provider' => PaymentProvider::PayPal
            ])->first();

            $order = $subscription->billing_orders()->with([
                'billing_order_items',
                'billing_payment_transaction'
            ])
            ->where([
                'payment_provider_checkout_id' => $subscription_id,
                'payment_provider' => PaymentProvider::PayPal
            ])->first();

            if (! $order) return;

            $startTime = CarbonImmutable::parse($resource['create_time']);

            if (
                $startTime->greaterThan($subCreationDate = $subscription->created_at)
                && $startTime->diffInDays($subCreationDate) >= 15
            ) {
                $newOrder = $order->replicate(['billing_order_id']);

                $order->loadMissing([ 
                    'billing_order_items',
                    'billing_payment_transaction'
                ]);

                $newOrder?->fill([
                    'billing_order_id' => Str::uuid(),
                    'payment_provider_transaction_id' => $order_id,
                    'payment_provider_order_id' => $order_id,
                    'payment_status' => PaymentStatus::FAILED, 
                    'status' => OrderStatus::FAILED,
                    'processed_at' => now(),
                    'metadata' => array_merge($newOrder->metadata ?? [], ['complete_payment_webhook' => $resource])
                ]);

                $newOrder->save();

                $newOrderItems = $order->billing_order_items->map(
                    fn (BillingOrderItem $orderItem) => $orderItem->replicate(['billing_order_item_id'])
                );

                $newOrderItems->each(function (BillingOrderItem $newOrderItem) use ($newOrder, $resource) {

                    $newOrderItem->fill([
                        'billing_order_item_id' => Str::uuid(),
                        'processed_at' => now(),
                        'status' => OrderStatus::FAILED,
                        'payment_status' => PaymentTransactionStatus::FAILED,
                        'metadata' => array_merge($newOrderItem->metadata ?? [], ['complete_payment_webhook' => $resource])
                    ]);

                    $newOrderItem->billing_order()->associate($newOrder);
                    $newOrderItem->save();
                }); 

                $newPaymentTransaction = $order?->billing_payment_transaction->replicate(['billing_payment_transaction_id']);


                $newPaymentTransaction->fill([
                    'billing_payment_transaction_id' => Str::uuid(),
                    'payment_provider_checkout_id' => $resource['id'],
                    'payment_provider_transaction_id' => $order_id,
                    'status' => PaymentTransactionStatus::FAILED,
                    'transacted_at' => now(),
                    'metadata' => array_merge($newPaymentTransaction->metadata ?? [], ['complete_payment_webhook' => $resource])
                ]);

                $newPaymentTransaction->billing_order()->associate($newOrder);
                $newPaymentTransaction->save();

                $newPaymentTransaction->billing_transaction_data()->create([
                    'event' => $payload['event_type'],
                    'transaction_summary' => $payload['summary'],
                    'transaction_ref' => $subscription_id,
                    'payment_provider_transaction_id' => $order_id,
                    'status' => $resource['status'],
                    'resource_id' => $order_id,
                    'webhook_id' => $payload['id'],
                    'receipt_number' => $order_id,
                    'payment_provider' => PaymentProvider::PayPal,
                    'payment_response' => $newPaymentTransaction->metadata ?? null,
                    'webhook_response' => $payload
                ]);


                $subscription->loadMissing([
                    'billing_plan',
                    'billing_plan_price'
                ]);

                $previous_end = $subscription->ends_at;

                $start = now();

                /** @var SubscriptionInterval */
                $interval = $subscription->billing_plan_price->interval;
                $count = $subscription->billing_plan_price->billing_interval_count;

                $end = $interval->calculateNextInterval($start, $count);

                $subscription->update([
                    'status' => SubscriptionStatus::FAILED,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'ended_at' => $previous_end,
                    'expired_at' => null,
                    'paused_at' => null,
                    'canceled_at' => null,
                    'renewed_at' => null,
                ]);

                $key = 'paypal_subscription_transaction_' . $subscription->id;

                $subscription_transaction_id = data_get(Cache::get($key, []), 'billing_subscription_transaction_id');

                if ($subscription_transaction_id) {

                    $subscription_transaction = Billing::$billingSubscriptionTransaction::find($subscription_transaction_id);

                    $subscription_transaction->update([
                        'status' => SubscriptionTransactionStatus::FAILED,
                        'payment_status' => PaymentStatus::FAILED,
                        'payment_provider_status' => $resource['status'] ?? 'paid',
                        'paid_at' => now() ,
                        'resource_id' => $payload['id'],
                        'webhook_response' => $payload,
                    ]);

                    $subscription_transaction->billing_subscription_events()->create([
                        'billing_subscription_id' => $subscription_transaction->billing_subscription_id,
                        'type' => EventEnum::TransactionFailure,
                        'triggered_by' => 'WEBHOOK',
                        'metadata' => $payload
                    ]);

                    Cache::forget($key);
                }

                event(new SubscriptionRenewalFailed($subscription));

                return;
            }
        
            $order?->update([
                'payment_provider_transaction_id' => $order_id,
                'payment_provider_order_id' => $order_id,
                'payment_status' => PaymentStatus::FAILED, 
                'status' => OrderStatus::FAILED,
                'processed_at' => now(),
                'metadata' => array_merge($order->metadata, ['complete_payment_webhook' => $resource])
            ]);

            $order?->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->update([
                'processed_at' => now(),
                'status' => OrderStatus::FAILED,
                'payment_status' => PaymentTransactionStatus::FAILED,
                'metadata' => array_merge($orderItem->metadata ?? [], ['complete_payment_webhook' => $resource])
            ]));

            $order?->billing_payment_transaction->update([
                'payment_provider_checkout_id' => $subscription_id,
                'payment_provider_transaction_id' => $order_id,
                'status' => PaymentTransactionStatus::FAILED,
                'transacted_at' => now(),
                'metadata' => array_merge($order->billing_payment_transaction->metadata ?? [], ['complete_payment_webhook' => $resource])
            ]);

            $order?->billing_payment_transaction->billing_transaction_data()->create([
                'event' => $payload['event_type'],
                'transaction_summary' => $payload['summary'],
                'transaction_ref' => $subscription_id,
                'payment_provider_transaction_id' => $order_id,
                'status' => $resource['status'],
                'resource_id' => $order_id,
                'webhook_id' => $payload['id'],
                'receipt_number' => $order_id,
                'payment_provider' => PaymentProvider::PayPal,
                'payment_response' => $order->billing_payment_transaction->metadata ?? null,
                'webhook_response' => $payload
            ]);

            $key = 'paypal_subscription_transaction_' . $subscription->id;

            $subscription_transaction_id = data_get(Cache::get($key, []), 'billing_subscription_transaction_id');

            if ($subscription_transaction_id) {

                $subscription_transaction = Billing::$billingSubscriptionTransaction::find($subscription_transaction_id);

                $subscription_transaction->update([
                    'payment_provider_checkout_id' => $order_id,
                    'status' => SubscriptionTransactionStatus::FAILED,
                    'payment_status' => PaymentStatus::FAILED,
                    'payment_provider_status' => $resource['status'] ?? 'paid',
                    'paid_at' => now() ,
                    'resource_id' => $payload['id'],
                    'webhook_response' => $payload,
                ]);

                $subscription_transaction->billing_subscription_events()->create([
                    'billing_subscription_id' => $subscription_transaction->billing_subscription_id,
                    'type' => EventEnum::TransactionFailure,
                    'triggered_by' => 'WEBHOOK',
                    'metadata' => $payload
                ]);

                Cache::forget($key);
            }
        });

    }
}