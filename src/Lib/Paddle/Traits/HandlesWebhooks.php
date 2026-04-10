<?php 

namespace Livewirez\Billing\Lib\Paddle\Traits;

use Exception;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Livewirez\Billing\Billing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Livewirez\Billing\Enums\OrderStatus;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Models\BillingOrder;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingOrderItem;
use Symfony\Component\HttpFoundation\Response;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Events\SubscriptionRenewed;
use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Enums\SubscriptionHistoryType;
use Livewirez\Billing\Enums\PaymentTransactionStatus;
use Livewirez\Billing\Lib\Paddle\Enums\WebhookEvents;
use Livewirez\Billing\Enums\SubscriptionTransactionStatus;
use Livewirez\Billing\Enums\SubscriptionEvent as EventEnum;
use Livewirez\Billing\Jobs\CancelSubscription as CancelSubscriptionJob;
use Livewirez\Billing\Jobs\ExpireSubscription as ExpireSubscriptionJob;

trait HandlesWebhooks
{
    public function __construct(protected array $config = [])
    {
        $this->config = $config !== [] ? $config : config('billing.providers.paddle');
    }

    public function handleWebhook(Request $request): Response
    {
        capture_request_vars($request);

        $type = $request->input('event_type');
        $payload = $request->input();

        match (WebhookEvents::tryFrom($type)) {
            WebhookEvents::SUBSCRIPTION_ACTIVATED     => $this->handleSubscriptionActivated($payload),
            WebhookEvents::SUBSCRIPTION_CANCELED      => $this->handleSubscriptionCanceled($payload),
            WebhookEvents::SUBSCRIPTION_CREATED       => $this->handleSubscriptionCreated($payload),
            WebhookEvents::SUBSCRIPTION_PAST_DUE      => $this->handleSubscriptionPastDue($payload),
            WebhookEvents::SUBSCRIPTION_PAUSED        => $this->handleSubscriptionPaused($payload),
            WebhookEvents::SUBSCRIPTION_RESUMED       => $this->handleSubscriptionResumed($payload),
            WebhookEvents::SUBSCRIPTION_TRIALING      => $this->handleSubscriptionTrialing($payload),
            WebhookEvents::SUBSCRIPTION_UPDATED       => $this->handleSubscriptionUpdated($payload),
            WebhookEvents::SUBSCRIPTION_IMPORTED      => $this->handleSubscriptionImported($payload),
            WebhookEvents::PAYMENT_METHOD_SAVED       => $this->handlePaymentMethodSaved($payload),
            WebhookEvents::PAYMENT_METHOD_DELETED     => $this->handlePaymentMethodDeleted($payload),
            WebhookEvents::TRANSACTION_BILLED         => $this->handleTransactionBilled($payload),
            WebhookEvents::TRANSACTION_CANCELED       => $this->handleTransactionCanceled($payload),
            WebhookEvents::TRANSACTION_COMPLETED      => $this->handleTransactionCompleted($payload),
            WebhookEvents::TRANSACTION_CREATED        => $this->handleTransactionCreated($payload),
            WebhookEvents::TRANSACTION_PAID           => $this->handleTransactionPaid($payload),
            WebhookEvents::TRANSACTION_PAST_DUE       => $this->handleTransactionPastDue($payload),
            WebhookEvents::TRANSACTION_PAYMENT_FAILED => $this->handleTransactionPaymentFailed($payload),
            WebhookEvents::TRANSACTION_READY          => $this->handleTransactionReady($payload),
            WebhookEvents::TRANSACTION_UPDATED        => $this->handleTransactionUpdated($payload),
            WebhookEvents::TRANSACTION_REVISED        => $this->handleTransactionRevised($payload),
            default                                   => Log::info("Unknown event type: $type"),
        };

        return response()->json(['message' => 'Success']);
    }

    private function findOrder(string $transactionId): ?BillingOrder
    {
        return Billing::$billingOrder::firstWhere([
            'payment_provider_transaction_id', $transactionId,
            'payment_provider' => PaymentProvider::Paddle
        ]);
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
            'payment_provider' => PaymentProvider::Paddle,
            'payment_provider_user_id' => $customerId,
        ], [
            'payment_provider_user_id' => $customerId,
        ]);

        $billable->paddle_billable_provider_data = $billableProviderData;

        return $billable;
    }

    private function findSubscription(string $subscriptionId): ?BillingSubscription
    {
        return Billing::$billingSubscription::firstWhere([
            'payment_provider_subscription_id' =>  $subscriptionId,
            'payment_provider' => PaymentProvider::Paddle
        ]);
    }

    private function setSubscriptionCacheData(BillingSubscription $subscription, array $data, SubscriptionStatus $subscriptionStatus = SubscriptionStatus::PENDING) 
    {

        $user = $subscription->billable()->first();

        $key = $user->getMorphClass().'_'.$user->getKey().'_paddle_subscription_active';

        Cache::put($key, [
            'billable_id' => $user->getMorphClass(),
            'billable_type' => $user->getKey(),
            'payment_provider' => PaymentProvider::Paddle->value,
            'payment_provider_transaction_id' => $subscription->payment_provider_transaction_id,
            'payment_provider_subscription_id' => $data['id'],
            'status' => $subscriptionStatus->value,
            'metadata' => ['complete_subscription_webhook' => $data]
        ], now()->addMinutes(10));
    }

    private function handleSubscriptionActivated(array $payload): void
    {
        Log::info(__METHOD__, $payload);

        DB::transaction(function () use ($payload) {  

            $data = $payload['data'];
                
            $customer_id = data_get($data, 'customer_id'); 
            $billing_subscription_id = data_get($data, 'custom_data.metadata.billing_subscription_id');

            $subscription = Billing::$billingSubscription::where([
                'billing_subscription_id' => $billing_subscription_id,
                'payment_provider' => PaymentProvider::Paddle
            ])->sole();

            $this->setSubscriptionCacheData($subscription, $data, SubscriptionStatus::ACTIVE);

            $subscription->update([
                'status' => SubscriptionStatus::ACTIVE,
                'payment_provider_subscription_id' => $data['id'],
                'metadata' => array_merge_recursive(
                    $subscription->metadata,
                    ['complete_subscription_webhook' => [...$data, 'notification_id' => $payload['notification_id'], 'event_id' => $payload['event_id']]]
                ),
                ...isset($data['ends_at']) ? ['ends_at' => CarbonImmutable::parse($data['ends_at'])] : [],
            ]);

            $subscription->billing_subscription_transactions()->where([
                'payment_provider' => PaymentProvider::Paddle
            ])->update([
                'payment_provider_subscription_id' => $data['id'],
            ]);

            if ($customer_id) {
                $this->findOrCreateCustomer(
                    data_get($data, 'custom_data.metadata.billable_id'),
                    data_get($data, 'custom_data.metadata.billable_type'),
                    $customer_id
                );
            }
        });
    }

    private function handleSubscriptionCanceled(array $payload): void
    {
        Log::info(__METHOD__, $payload);

        DB::transaction(function () use ($payload) {  

            $data = $payload['data'];
                
            $billing_subscription_id = data_get($data, 'custom_data.metadata.billing_subscription_id');

            $subscription = Billing::$billingSubscription::where([
                'billing_subscription_id' => $billing_subscription_id,
                'payment_provider' => PaymentProvider::Paddle
            ])->sole();


            if (data_get($data, 'scheduled_change.action') === 'cancel' 
                && data_get($data, 'scheduled_change.effective_at') !== null
                && $subscription->ends_at->greaterThan(now())
            ) {
                $status = SubscriptionStatus::CANCELLATION_PENDING;
                ExpireSubscriptionJob::dispatch($subscription->id)
                    ->delay(CarbonImmutable::parse($subscription->ends_at));
            } else {
                $status = SubscriptionStatus::CANCELED;
            }

            $subscription->update([
                'payment_provider_subscription_id' => $data['id'],
                'canceled_at' => CarbonImmutable::parse($data['canceled_at'] ?? now()),
                'status' => $status,
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
            
        });

    }

    private function handleSubscriptionCreated(array $payload): void
    { 
        Log::info(__METHOD__, $payload);

        DB::transaction(function () use ($payload) {  

            $data = $payload['data'];
                
            $customer_id = data_get($data, 'customer_id'); 
            $billing_subscription_id = data_get($data, 'custom_data.metadata.billing_subscription_id');
            $billing_subscription_transaction_id = data_get($data, 'custom_data.metadata.billing_subscription_transaction_id');

            $subscription = Billing::$billingSubscription::where([
                'billing_subscription_id' => $billing_subscription_id,
                'payment_provider' => PaymentProvider::Paddle
            ])->sole();

            $subscription->update([
                'payment_provider_subscription_id' => $data['id'],
            ]);

            $subscription->billing_subscription_transactions()->where([
                'payment_provider' => PaymentProvider::Paddle,
                'billing_subscription_transaction_id' => $billing_subscription_transaction_id
            ])->update([
                'payment_provider_subscription_id' => $data['id'],
            ]);

            if ($customer_id) {
                $this->findOrCreateCustomer(
                    data_get($data, 'custom_data.metadata.billable_id'),
                    data_get($data, 'custom_data.metadata.billable_type'),
                    $customer_id
                );
            }

            $this->setSubscriptionCacheData($subscription, $data);
        });
    }


    private function handleSubscriptionPastDue(array $payload): void
    {
       

    }


    private function handleSubscriptionPaused(array $payload): void
    {
        Log::info(__METHOD__, $payload);

        DB::transaction(function () use ($payload) {  

            $data = $payload['data'];
                
            $billing_subscription_id = data_get($data, 'custom_data.metadata.billing_subscription_id');

            $subscription = Billing::$billingSubscription::where([
                'billing_subscription_id' => $billing_subscription_id,
                'payment_provider' => PaymentProvider::Paddle
            ])->sole();

            $subscription->update([
                'payment_provider_subscription_id' => $data['id'],
                'paused_at' => now(),
                'status' => SubscriptionStatus::PAUSED,
            ]);

            $subscription_event = $subscription->billing_subscription_events()->create( [
                'type' => EventEnum::Pause,
                'triggered_by' => 'WEBHOOK',
                'description' => 'Subscription Pause Request from Webhook',
                'metadata' => $payload
            ]);
        });
    }


    private function handleSubscriptionResumed(array $payload): void
    {
        DB::transaction(function () use ($payload) {  

            $data = $payload['data'];
                
            $billing_subscription_id = data_get($data, 'custom_data.metadata.billing_subscription_id');

            $starts_at = data_get($data, 'current_billing_period.starts_at');
            $ends_at = data_get($data, 'current_billing_period.ends_at');

            $subscription = Billing::$billingSubscription::where([
                'billing_subscription_id' => $billing_subscription_id,
                'payment_provider' => PaymentProvider::Paddle
            ])->sole();

            $subscription->update([
                'payment_provider_subscription_id' => $data['id'],
                'status' => SubscriptionStatus::ACTIVE,
                ...($starts_at !== null) ? ['starts_at' => CarbonImmutable::parse($starts_at)] : [],
                ...($ends_at !== null) ? [
                    'ends_at' => CarbonImmutable::parse($ends_at)
                ] : [],
                'resumed_at' => now()
            ]);

            $subscription_event = $subscription->billing_subscription_events()->create( [
                'type' => EventEnum::Resume,
                'triggered_by' => 'WEBHOOK',
                'description' => 'Subscription Resume Request from Webhook',
                'metadata' => $payload
            ]);
        });

    }

    private function handleSubscriptionTrialing(array $payload): void
    {
        Log::info(__METHOD__, $payload);

        DB::transaction(function () use ($payload) {  

            $data = $payload['data'];
                
            $billing_subscription_id = data_get($data, 'custom_data.metadata.billing_subscription_id');

            $starts_at = data_get($data, 'current_billing_period.starts_at');
            $ends_at = data_get($data, 'current_billing_period.ends_at');

            $subscription = Billing::$billingSubscription::where([
                'billing_subscription_id' => $billing_subscription_id,
                'payment_provider' => PaymentProvider::Paddle
            ])->sole();

            $subscription->update([
                'payment_provider_subscription_id' => $data['id'],
                'status' => SubscriptionStatus::TRIALING,
                ...($starts_at !== null) ? ['starts_at' => CarbonImmutable::parse($starts_at)] : [],
                ...($ends_at !== null) ? [
                    'ends_at' => CarbonImmutable::parse($ends_at), 
                ] : [],
            ]);

            
        });
    }

    private function handleSubscriptionUpdated(array $payload): void
    {
        DB::transaction(function () use ($payload) {  

            $data = $payload['data'];
            
            $customer_id = data_get($data, 'customer_id'); 
            $billing_subscription_id = data_get($data, 'custom_data.metadata.billing_subscription_id');

            $subscription = Billing::$billingSubscription::where([
                'billing_subscription_id' => $billing_subscription_id,
                'payment_provider' => PaymentProvider::Paddle
            ])->sole();

            $previous_start = $subscription->starts_at;
            $previous_end = $subscription->ends_at;

            $starts_at = data_get($data, 'current_billing_period.starts_at');
            $ends_at = data_get($data, 'current_billing_period.ends_at');


            $plan_id = data_get($data, 'items.0.price.custom_data.metadata.billing_plan');
            $plan_price_id = data_get($data, 'items.0.price.custom_data.metadata.billing_plan_price');
            $plan_name = data_get(
                $data, 'items.0.price.custom_data.metadata.billing_plan_name', 
                data_get($data, 'items.0.product.name')
            );

            if ($customer_id) {
                $this->findOrCreateCustomer(
                    data_get($data, 'custom_data.metadata.billable_id'),
                    data_get($data, 'custom_data.metadata.billable_type'),
                    $customer_id
                );
            }

            if ($starts_at && $ends_at) {

                $start = CarbonImmutable::parse($starts_at);
                $end = CarbonImmutable::parse($ends_at);

                if (
                    (
                        $subscription->billing_plan_id === $plan_id
                        && $subscription->billing_plan_price_id === $plan_price_id
                    ) && (
                        $start->equalTo($subscription->ends_at)
                        || $start->greaterThan($subscription->ends_at)
                    )
                ) {
                    $subscription->update([
                        'payment_provider_subscription_id' => $data['id'],
                        'status' => SubscriptionStatus::ACTIVE,
                        'starts_at' => $start,
                        'ends_at' => $end, 
                        'ended_at' => $previous_end,
                        'expired_at' => null,
                        'paused_at' => null,
                        'canceled_at' => null,
                        'renewed_at' => null,
                    ]);

                    
                    event(new SubscriptionRenewed($subscription));
                }

                if (
                    $subscription->billing_plan_id !== $plan_id
                    || $subscription->billing_plan_price_id !== $plan_price_id
                ) {
                    $subscription->update([
                        'billing_plan_id' => $plan_id,
                        'billing_plan_price_id' => $plan_price_id,
                        'billing_plan_name' => explode(' - ', $plan_name)[0]
                    ]);
                }
            }
        });

    }

    private function handleSubscriptionImported(array $payload): void
    {

    }

    private function handlePaymentMethodSaved(array $payload): void
    {
        Log::info(__METHOD__, $payload);

        DB::transaction(function () use ($payload) {    
            $data = $payload['data'];

            $customerId = $data['customer_id'];

            $paddleCustomer = Billing::$billablePaymentProviderInformation::where([
                'payment_provider' => PaymentProvider::Paddle,
                'payment_provider_user_id' => $customerId,
            ])->first(); 

            if ($paddleCustomer) {

                $user = $paddleCustomer->billable()->first();

                $billablePaymentMethod = $user->billable_payment_methods()->create([
                    'payment_provider' => PaymentProvider::Paddle,
                    'payment_provider_user_id' => $customerId,
                    'provider_payment_method_id' => $data['id'],
                ]);

                $billablePaymentMethod->billable_payment_provider_information()->associate($paddleCustomer);
                $billablePaymentMethod->save();
            }
        });

    }

    private function handlePaymentMethodDeleted(array $payload): void
    {

    }


    private function handleTransactionBilled(array $payload): void
    {
        if (
            ! isset($payload['data']['billing_period'], $payload['data']['billing_period']['starts_at'], $payload['data']['billing_period']['ends_at']) 
        ) return;

        DB::transaction(function () use ($payload) {    
            $data = $payload['data'];

            $billing_order_id = data_get($data, 'custom_data.metadata.billing_order_id');
            $order_number = data_get($data, 'custom_data.metadata.order_number');
            $billing_subscription_transaction_id = data_get($data, 'custom_data.metadata.billing_subscription_transaction_id');
            $customer_id = data_get($data, 'customer_id'); 

            $order = Billing::$billingOrder::with([
                'billing_order_items',
                'billing_payment_transaction'
            ])
            ->where([
                'billing_order_id' => $billing_order_id,
                'order_number' => $order_number,
                'payment_provider' => PaymentProvider::Paddle
            ])->first();

            if (! $order) return;

            $fee = data_get($data, 'details.totals.fee');
            $discount = data_get($data, 'details.totals.discount');
            $tax = data_get($data, 'details.totals.tax');
            $earnings = data_get($data, 'details.totals.earnings');
            $subtotal = data_get($data, 'details.totals.subtotal');
            $total_amount = data_get($data, 'details.totals.total');

            $newOrder = $order->replicate(['billing_order_id']);
        
            $newOrder->fill([
                'billing_order_id' => Str::uuid(),
                'payment_provider_transaction_id' => $data['id'],
                'payment_provider_checkout_id' => $data['id'],
                'payment_status' => PaymentStatus::COMPLETED, 
                'status' => OrderStatus::AWAITING_PROCESSING,
                'processed_at' => now(),
                'metadata' => array_merge($newOrder->metadata, ['complete_payment_webhook' => $data])
            ])->save();

            $newOrderItems = $order->billing_order_items->map(
                fn (BillingOrderItem $orderItem) => $orderItem->replicate(['billing_order_item_id'])
            );

            $newOrderItems->each(function (BillingOrderItem $newOrderItem) use ($newOrder, $data) {
                
                $newOrderItem->fill([
                    'billing_order_item_id' => Str::uuid(),
                    'processed_at' => now(),
                    'status' => OrderStatus::AWAITING_PROCESSING,
                    'payment_status' => PaymentTransactionStatus::COMPLETED,
                    'metadata' => array_merge($orderItem->metadata ?? [], ['complete_payment_webhook' => $data])
                ]);

                $newOrderItem->billing_order()->associate($newOrder);
                $newOrderItem->save();
            });

            $newPaymentTransaction =  $order->billing_payment_transaction->replicate(['billing_payment_transaction_id']);

            $newPaymentTransaction->fill([
                'billing_payment_transaction_id' => Str::uuid(),
                'payment_provider_transaction_id' => $data['id'],
                'payment_provider_checkout_id' => $data['id'],
                'status' => PaymentTransactionStatus::COMPLETED,
                'transacted_at' => now(),
                'metadata' => array_merge($order->billing_payment_transaction->metadata ?? [], ['complete_payment_webhook' => $data]),
                ...$total_amount ? [ 'total_amount' => (int) $total_amount ] : [],
                ...$subtotal ? ['subtotal' => (int) $subtotal] : [],
                ...$earnings ? ['earnings' => (int) $earnings] : [],
                ...$tax ? [] : ['tax' => (int) $tax],
                ...$discount ? ['discount' => (int) $discount] : [],
                ...$fee ? ['provider_fee' => (int) $fee] : [],
            ]);

            $newPaymentTransaction->billing_order()->associate($newOrder);
            $newPaymentTransaction->save();

            $newPaymentTransaction->billing_transaction_data()->create([
                'event' => $payload['event_type'],
                'transaction_summary' => $data['summary'] ?? '',
                'transaction_ref' => $data['id'],
                'payment_provider_transaction_id' => $data['id'],
                'status' => $data['status'],
                'resource_id' => $data['id'],
                'webhook_id' => $payload['event_id'],
                'receipt_number' => $data['id'],
                'payment_provider' => PaymentProvider::Paddle,
                'payment_response' => $newPaymentTransaction->metadata ?? null,
                'webhook_response' => $data
            ]);

            if ($customer_id) {
                $this->findOrCreateCustomer(
                    data_get($data, 'custom_data.metadata.billable_id'),
                    data_get($data, 'custom_data.metadata.billable_type'),
                    $customer_id
                );
            }

            if ($billing_subscription_transaction_id) {
                $subscription_transaction = Billing::$billingSubscriptionTransaction::where([
                    'billing_subscription_transaction_id' => $billing_subscription_transaction_id,
                    'payment_provider' => PaymentProvider::Paddle
                ])->first();

                $subscription_transaction->update([
                    'amount' => $order->total,
                    'status' => SubscriptionTransactionStatus::COMPLETED,
                    'payment_status' => PaymentStatus::PAID,
                    'payment_provider_status' => $data['status'] ?? 'paid',
                    'paid_at' => now() ,
                    'resource_id' => $payload['event_id'],
                    'webhook_response' => $payload,
                ]);

                $subscription_transaction->billing_subscription_events()->create([
                    'billing_subscription_id' => $subscription_transaction->billing_subscription_id,
                    'type' => EventEnum::TransactionCompleted,
                    'triggered_by' => 'WEBHOOK',
                    'metadata' => $payload
                ]);
            }
        });
    }

    private function handleTransactionCanceled(array $payload): void
    {
        Log::info(__METHOD__, $payload);

        DB::transaction(function () use ($payload) {
            
            $data = $payload['data'];
            
            $billing_order_id = data_get($data, 'custom_data.metadata.billing_order_id');
            $order_number = data_get($data, 'custom_data.metadata.order_number');
            $billing_subscription_transaction_id = data_get($data, 'custom_data.metadata.billing_subscription_transaction_id');

            $order = Billing::$billingOrder::with([
                'billing_order_items',
                'billing_payment_transaction'
            ])
            ->where([
                'billing_order_id' => $billing_order_id,
                'order_number' => $order_number,
                'payment_provider' => PaymentProvider::Paddle
            ])->first();

            if (! $order) return;

            $fee = data_get($data, 'details.totals.fee');
            $discount = data_get($data, 'details.totals.discount');
            $tax = data_get($data, 'details.totals.tax');
            $earnings = data_get($data, 'details.totals.earnings');
            $subtotal = data_get($data, 'details.totals.subtotal');
            $total_amount = data_get($data, 'details.totals.total');
        
            $order->update([
                'payment_provider_transaction_id' => $data['id'],
                'payment_provider_checkout_id' => $data['id'],
                'payment_status' => PaymentStatus::CANCELED, 
                'status' => OrderStatus::AWAITING_PROCESSING,
                'processed_at' => now(),
                'metadata' => array_merge($order->metadata, ['complete_payment_webhook' => $data])
            ]);

            $order->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->update([
                'processed_at' => now(),
                'status' => OrderStatus::AWAITING_PROCESSING,
                'payment_status' => PaymentTransactionStatus::CANCELED,
                'metadata' => array_merge($orderItem->metadata ?? [], ['complete_payment_webhook' => $data])
            ]));

            $order->billing_payment_transaction->update([
                'payment_provider_transaction_id' => $data['id'],
                'payment_provider_checkout_id' => $data['id'],
                'status' => PaymentTransactionStatus::CANCELED,
                'transacted_at' => now(),
                'metadata' => array_merge($order->billing_payment_transaction->metadata ?? [], ['complete_payment_webhook' => $data]),
                ...$total_amount ? [ 'total_amount' => (int) $total_amount ] : [],
                ...$subtotal ? ['subtotal' => (int) $subtotal] : [],
                ...$earnings ? ['earnings' => (int) $earnings] : [],
                ...$tax ? [] : ['tax' => (int) $tax],
                ...$discount ? ['discount' => (int) $discount] : [],
                ...$fee ? ['provider_fee' => (int) $fee] : [],
            ]);

            $order->billing_payment_transaction->billing_transaction_data()->create([
                'event' => $payload['event_type'],
                'transaction_summary' => $data['summary'] ?? '',
                'transaction_ref' => $data['id'],
                'payment_provider_transaction_id' => $data['id'],
                'status' => $data['status'],
                'resource_id' => $data['id'],
                'webhook_id' => $payload['event_id'],
                'receipt_number' => $data['id'],
                'payment_provider' => PaymentProvider::Paddle,
                'payment_response' => $order->billing_payment_transaction->metadata ?? null,
                'webhook_response' => $data
            ]);

            if ($billing_subscription_transaction_id) {
                $subscription_transaction = Billing::$billingSubscriptionTransaction::where([
                    'billing_subscription_transaction_id' => $billing_subscription_transaction_id,
                    'payment_provider' => PaymentProvider::Paddle
                ])->first();

                $subscription_transaction->update([
                    'payment_provider_checkout_id' => $data['id'],
                    'amount' => $order->total,
                    'status' => SubscriptionTransactionStatus::CANCELED,
                    'payment_status' => PaymentStatus::UNPAID,
                    'payment_provider_status' => $data['status'] ?? 'unpaid',
                    'paid_at' => now() ,
                    'resource_id' => $payload['event_id'],
                    'webhook_response' => $payload,
                ]);

                $subscription_transaction->billing_subscription_events()->create([
                    'billing_subscription_id' => $subscription_transaction->billing_subscription_id,
                    'type' => EventEnum::Cancellation,
                    'triggered_by' => 'WEBHOOK',
                    'metadata' => $payload
                ]);
            }
        });
    }

    private function handleTransactionCompleted(array $payload): void
    {
        Log::info(__METHOD__, $payload);

        DB::transaction(function () use ($payload) {    
            $data = $payload['data'];

            $billing_order_id = data_get($data, 'custom_data.metadata.billing_order_id');
            $order_number = data_get($data, 'custom_data.metadata.order_number');
            $billing_subscription_transaction_id = data_get($data, 'custom_data.metadata.billing_subscription_transaction_id');
            $customer_id = data_get($data, 'customer_id'); 

            $order = Billing::$billingOrder::with([
                'billing_order_items',
                'billing_payment_transaction'
            ])
            ->where([
                'billing_order_id' => $billing_order_id,
                'order_number' => $order_number,
                'payment_provider' => PaymentProvider::Paddle
            ])->first();

            if (! $order) return;

            $fee = data_get($data, 'details.totals.fee');
            $discount = data_get($data, 'details.totals.discount');
            $tax = data_get($data, 'details.totals.tax');
            $earnings = data_get($data, 'details.totals.earnings');
            $subtotal = data_get($data, 'details.totals.subtotal');
            $total_amount = data_get($data, 'details.totals.total');
        
            $order->update([
                'payment_provider_transaction_id' => $data['id'],
                'payment_provider_checkout_id' => $data['id'],
                'payment_status' => PaymentStatus::COMPLETED, 
                'status' => OrderStatus::AWAITING_PROCESSING,
                'processed_at' => now(),
                'metadata' => array_merge($order->metadata, ['complete_payment_webhook' => $data])
            ]);

            $order->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->update([
                'processed_at' => now(),
                'status' => OrderStatus::AWAITING_PROCESSING,
                'payment_status' => PaymentTransactionStatus::COMPLETED,
                'metadata' => array_merge($orderItem->metadata ?? [], ['complete_payment_webhook' => $data])
            ]));

            $order->billing_payment_transaction->update([
                'payment_provider_transaction_id' => $data['id'],
                'payment_provider_checkout_id' => $data['id'],
                'payment_provider_invoice_number' => $data['invoice_number'],
                'status' => PaymentTransactionStatus::COMPLETED,
                'transacted_at' => now(),
                'metadata' => array_merge($order->billing_payment_transaction->metadata ?? [], ['complete_payment_webhook' => $data]),
                ...$total_amount ? [ 'total_amount' => (int) $total_amount ] : [],
                ...$subtotal ? ['subtotal' => (int) $subtotal] : [],
                ...$earnings ? ['earnings' => (int) $earnings] : [],
                ...$tax ? [] : ['tax' => (int) $tax],
                ...$discount ? ['discount' => (int) $discount] : [],
                ...$fee ? ['provider_fee' => (int) $fee] : [],
            ]);

            $order->billing_payment_transaction->billing_transaction_data()->create([
                'event' => $payload['event_type'],
                'transaction_summary' => $data['summary'] ?? '',
                'transaction_ref' => $data['id'],
                'payment_provider_transaction_id' => $data['id'],
                'status' => $data['status'],
                'resource_id' => $data['id'],
                'webhook_id' => $payload['event_id'],
                'receipt_number' => $data['id'],
                'payment_provider' => PaymentProvider::Paddle,
                'payment_response' => $order->billing_payment_transaction->metadata ?? null,
                'webhook_response' => $data
            ]);

            if ($customer_id) {
                $this->findOrCreateCustomer(
                    data_get($data, 'custom_data.metadata.billable_id'),
                    data_get($data, 'custom_data.metadata.billable_type'),
                    $customer_id
                );
            }

            if ($billing_subscription_transaction_id) {
                $subscription_transaction = Billing::$billingSubscriptionTransaction::where([
                    'billing_subscription_transaction_id' => $billing_subscription_transaction_id,
                    'payment_provider' => PaymentProvider::Paddle
                ])->first();

                $subscription_transaction->update([
                    'payment_provider_checkout_id' => $data['id'],
                    'amount' => $order->total,
                    'status' => SubscriptionTransactionStatus::COMPLETED,
                    'payment_status' => PaymentStatus::PAID,
                    'payment_provider_status' => $data['status'] ?? 'paid',
                    'paid_at' => now() ,
                    'resource_id' => $payload['event_id'],
                    'webhook_response' => $payload,
                ]);

                $subscription_transaction->billing_subscription_events()->create([
                    'billing_subscription_id' => $subscription_transaction->billing_subscription_id,
                    'type' => EventEnum::TransactionCompleted,
                    'triggered_by' => 'WEBHOOK',
                    'metadata' => $payload
                ]);
            }
        });
    }

    private function handleTransactionCreated(array $payload): void
    {
        
    }

    private function handleTransactionPaid(array $payload): void
    {
        Log::info(__METHOD__, $payload);

        DB::transaction(function () use ($payload) {    
            $data = $payload['data'];

            $billing_order_id = data_get($data, 'custom_data.metadata.billing_order_id');
            $order_number = data_get($data, 'custom_data.metadata.order_number');
            $billing_subscription_transaction_id = data_get($data, 'custom_data.metadata.billing_subscription_transaction_id');
            $customer_id = data_get($data, 'customer_id'); 

            $order = Billing::$billingOrder::with([
                'billing_order_items',
                'billing_payment_transaction'
            ])
            ->where([
                'billing_order_id' => $billing_order_id,
                'order_number' => $order_number,
                'payment_provider' => PaymentProvider::Paddle
            ])->first();

            if (! $order) return;

            $fee = data_get($data, 'details.totals.fee');
            $discount = data_get($data, 'details.totals.discount');
            $tax = data_get($data, 'details.totals.tax');
            $earnings = data_get($data, 'details.totals.earnings');
            $subtotal = data_get($data, 'details.totals.subtotal');
            $total_amount = data_get($data, 'details.totals.total');
        
            $order->update([
                'payment_provider_transaction_id' => $data['id'],
                'payment_provider_checkout_id' => $data['id'],
                'payment_status' => PaymentStatus::PAID, 
                'status' => OrderStatus::AWAITING_PROCESSING,
                'processed_at' => now(),
                'metadata' => array_merge($order->metadata, ['complete_payment_webhook' => $data]),
                ...$total_amount ? [ 'total' => (int) $total_amount ] : [],
                ...$subtotal ? ['subtotal' => (int) $subtotal] : [],
                ...$earnings ? ['earnings' => (int) $earnings] : [],
                ...$tax ? [] : ['tax' => (int) $tax],
                ...$discount ? ['discount' => (int) $discount] : [],
                ...$fee ? ['provider_fee' => (int) $fee] : [],
            ]);

            $order->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->update([
                'processed_at' => now(),
                'status' => OrderStatus::AWAITING_PROCESSING,
                'payment_status' => PaymentTransactionStatus::PAID,
                'metadata' => array_merge($orderItem->metadata ?? [], ['complete_payment_webhook' => $data])
            ]));

            $order->billing_payment_transaction->update([
                'payment_provider_transaction_id' => $data['id'],
                'payment_provider_checkout_id' => $data['id'],
                'payment_provider_invoice_number' => $data['invoice_number'] ?? null,
                'status' => PaymentTransactionStatus::PAID,
                'transacted_at' => now(),
                'metadata' => array_merge($order->billing_payment_transaction->metadata ?? [], ['complete_payment_webhook' => $data]),
                ...$total_amount ? [ 'total_amount' => (int) $total_amount ] : [],
                ...$subtotal ? ['subtotal' => (int) $subtotal] : [],
                ...$earnings ? ['earnings' => (int) $earnings] : [],
                ...$tax ? [] : ['tax' => (int) $tax],
                ...$discount ? ['discount' => (int) $discount] : [],
                ...$fee ? ['provider_fee' => (int) $fee] : [],
            ]);

            $order->billing_payment_transaction->billing_transaction_data()->create([
                'event' => $payload['event_type'],
                'transaction_summary' => $data['summary'] ?? '',
                'transaction_ref' => $data['id'],
                'payment_provider_transaction_id' => $data['id'],
                'status' => $data['status'],
                'resource_id' => $data['id'],
                'webhook_id' => $payload['event_id'],
                'receipt_number' => $data['id'],
                'payment_provider' => PaymentProvider::Paddle,
                'payment_response' => $order->billing_payment_transaction->metadata ?? null,
                'webhook_response' => $data
            ]);

            if ($customer_id) {
                $this->findOrCreateCustomer(
                    data_get($data, 'custom_data.metadata.billable_id'),
                    data_get($data, 'custom_data.metadata.billable_type'),
                    $customer_id
                );
            }

            if ($billing_subscription_transaction_id) {
                $subscription_transaction = Billing::$billingSubscriptionTransaction::where([
                    'billing_subscription_transaction_id' => $billing_subscription_transaction_id,
                    'payment_provider' => PaymentProvider::Paddle
                ])->first();

                $subscription_transaction->update([
                    'payment_provider_checkout_id' => $data['id'],
                    'amount' => $order->total,
                    'status' => SubscriptionTransactionStatus::COMPLETED,
                    'payment_status' => PaymentStatus::PAID,
                    'payment_provider_status' => $data['status'] ?? 'paid',
                    'paid_at' => now() ,
                    'resource_id' => $payload['event_id'],
                    'webhook_response' => $payload,
                ]);

                $subscription_transaction->billing_subscription_events()->create([
                    'billing_subscription_id' => $subscription_transaction->billing_subscription_id,
                    'type' => EventEnum::TransactionCompleted,
                    'triggered_by' => 'WEBHOOK',
                    'metadata' => $payload
                ]);
            }
        });
    }

    private function handleTransactionPastDue(array $payload): void
    {
             
    }

    private function handleTransactionPaymentFailed(array $payload): void
    {
        Log::info(__METHOD__, $payload);

        DB::transaction(function () use ($payload) { 
            $data = $payload['data'];   

            $billing_order_id = data_get($data, 'custom_data.metadata.billing_order_id');
            $order_number = data_get($data, 'custom_data.metadata.order_number');
            $billing_subscription_transaction_id = data_get($data, 'custom_data.metadata.billing_subscription_transaction_id');

            $order = Billing::$billingOrder::with([
                'billing_order_items',
                'billing_payment_transaction'
            ])
            ->where([
                'billing_order_id' => $billing_order_id,
                'order_number' => $order_number,
                'payment_provider' => PaymentProvider::Paddle
            ])->first();

            if (! $order) return;

            $fee = data_get($data, 'details.totals.fee');
            $discount = data_get($data, 'details.totals.discount');
            $tax = data_get($data, 'details.totals.tax');
            $earnings = data_get($data, 'details.totals.earnings');
            $subtotal = data_get($data, 'details.totals.subtotal');
            $total_amount = data_get($data, 'details.totals.total');
        
            $order->update([
                'payment_provider_transaction_id' => $data['id'],
                'payment_provider_checkout_id' => $data['id'],
                'payment_status' => PaymentStatus::FAILED, 
                'status' => OrderStatus::AWAITING_PROCESSING,
                'processed_at' => now(),
                'metadata' => array_merge($order->metadata, ['complete_payment_webhook' => $data]),
                ...$total_amount ? [ 'total' => (int) $total_amount ] : [],
                ...$subtotal ? ['subtotal' => (int) $subtotal] : [],
                ...$earnings ? ['earnings' => (int) $earnings] : [],
                ...$tax ? [] : ['tax' => (int) $tax],
                ...$discount ? ['discount' => (int) $discount] : [],
                ...$fee ? ['provider_fee' => (int) $fee] : [],
            ]);

            $order->billing_order_items->each(fn (BillingOrderItem $orderItem) => $orderItem->update([
                'processed_at' => now(),
                'status' => OrderStatus::AWAITING_PROCESSING,
                'payment_status' => PaymentTransactionStatus::FAILED,
                'metadata' => array_merge($orderItem->metadata ?? [], ['complete_payment_webhook' => $data])
            ]));

            $order->billing_payment_transaction->update([
                'payment_provider_transaction_id' => $data['id'],
                'payment_provider_checkout_id' => $data['id'],
                'status' => PaymentTransactionStatus::FAILED,
                'transacted_at' => now(),
                'metadata' => array_merge($order->billing_payment_transaction->metadata ?? [], ['complete_payment_webhook' => $data]),
                ...$total_amount ? [ 'total_amount' => (int) $total_amount ] : [],
                ...$subtotal ? ['subtotal' => (int) $subtotal] : [],
                ...$earnings ? ['earnings' => (int) $earnings] : [],
                ...$tax ? [] : ['tax' => (int) $tax],
                ...$discount ? ['discount' => (int) $discount] : [],
                ...$fee ? ['provider_fee' => (int) $fee] : [],
            ]);

            $order->billing_payment_transaction->billing_transaction_data()->create([
                'event' => $payload['event_type'],
                'transaction_summary' => $data['summary'] ?? '',
                'transaction_ref' => $data['id'],
                'payment_provider_transaction_id' => $data['id'],
                'status' => $data['status'],
                'resource_id' => $data['id'],
                'webhook_id' => $payload['event_id'],
                'receipt_number' => $data['id'],
                'payment_provider' => PaymentProvider::Paddle,
                'payment_response' => $order->billing_payment_transaction->metadata ?? null,
                'webhook_response' => $data
            ]);

            if ($billing_subscription_transaction_id) {
                $subscription_transaction = Billing::$billingSubscriptionTransaction::where([
                    'billing_subscription_transaction_id' => $billing_subscription_transaction_id,
                    'payment_provider' => PaymentProvider::Paddle
                ])->first();

                $subscription_transaction->update([
                    'payment_provider_checkout_id' => $data['id'],
                    'amount' => $order->total,
                    'status' => SubscriptionTransactionStatus::FAILED,
                    'payment_status' => PaymentStatus::UNPAID,
                    'payment_provider_status' => $data['status'] ?? 'failed',
                    'resource_id' => $payload['event_id'],
                    'webhook_response' => $payload,
                ]);

                $subscription_transaction->billing_subscription_events()->create([
                    'billing_subscription_id' => $subscription_transaction->billing_subscription_id,
                    'type' => EventEnum::TransactionFailure,
                    'triggered_by' => 'WEBHOOK',
                    'metadata' => $payload
                ]);
            }
        });
    }

    private function handleTransactionReady(array $payload): void
    {

    }

    private function handleTransactionUpdated(array $payload): void
    {

    }

    private function handleTransactionRevised(array $payload): void
    {

    }

    
}