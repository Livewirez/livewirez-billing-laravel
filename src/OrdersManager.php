<?php 

namespace Livewirez\Billing;

use App\Models\User;
use RuntimeException;
use Illuminate\Support\Str;
use Livewirez\Billing\Lib\Cart;
use Doctrine\Common\Lexer\Token;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Connection;
use Livewirez\Billing\Lib\CartItem;
use Livewirez\Billing\Models\Product;
use Livewirez\Billing\Enums\ActionType;
use Livewirez\Billing\Enums\OrderStatus;
use Livewirez\Billing\Enums\ProductType;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Models\BillingPlan;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Lib\CheckoutDetails;
use Livewirez\Billing\Models\BillingOrder;
use Livewirez\Billing\Enums\DeliveryStatus;
use Livewirez\Billing\Events\OrderCanceled;
use Livewirez\Billing\Events\PaymentFailed;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Events\PaymentApproved;
use Livewirez\Billing\Events\PaymentCanceled;
use Livewirez\Billing\Events\PaymentCaptured;
use Livewirez\Billing\Enums\ApiProductTypeKey;
use Livewirez\Billing\Enums\FulfillmentStatus;
use Livewirez\Billing\Events\PaymentCompleted;
use Livewirez\Billing\Events\PaymentInitiated;
use Livewirez\Billing\Models\BillingOrderItem;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Interfaces\CartInterface;
use Livewirez\Billing\Providers\PayPalProvider;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Livewirez\Billing\Enums\PaymentTransactionType;
use Livewirez\Billing\Events\OrderAwaitingDelivery;
use Livewirez\Billing\Interfaces\CartItemInterface;
use Livewirez\Billing\Models\BillablePaymentMethod;
use Livewirez\Billing\Providers\PayPalHttpProvider;
use Livewirez\Billing\Enums\PaymentTransactionStatus;
use Livewirez\Billing\Events\OrderAwaitingProcessing;
use Livewirez\Billing\Lib\Orders\CompleteOrderRequest;
use Livewirez\Billing\Lib\Orders\InitializeOrderRequest;
use Livewirez\Billing\Interfaces\ResolvesPaymentProvider;
use Livewirez\Billing\Interfaces\PaymentProviderInterface;
use Livewirez\Billing\Exceptions\PaymentInitiationException;
use Livewirez\Billing\Interfaces\OrderCalculatableInterface;
use Livewirez\Billing\Traits\HandlesPaymentProviderResolution;
use Livewirez\Billing\Interfaces\TokenizedPaymentProviderInterface;

class OrdersManager implements ResolvesPaymentProvider
{
    use HandlesPaymentProviderResolution;
    
    public function setupPaymentToken(
        Billable $user, 
        PaymentProvider|string $paymentProvider,
        array $metadata = []
    ): array
    {
        $paymentProviderValue = $this->resolveProviderValue($paymentProvider);

        $provider = $this->provider($paymentProviderValue);
            
        $tokenPaymentProvider = $provider->getTokenPaymentProvider();

        return $tokenPaymentProvider->setupPaymentToken([...$metadata, 'user' => $user]);
    }

    public function completePaymentWithToken(
        Billable $user,
        PaymentProvider|string $paymentProvider,
        CartInterface $cart,
        BillablePaymentMethod | string $token,
        array $paymentData = [],
        array $metadata = [],
    ): PaymentResult
    {
        return DB::transaction(function () use ($user, $paymentProvider, $cart, $token, $metadata, $paymentData): PaymentResult {

            $paymentProviderValue = $this->resolveProviderValue($paymentProvider);

            $provider = $this->provider($paymentProviderValue);
            
            $tokenPaymentProvider = $provider->getTokenPaymentProvider();
    
            $order = $user->billing_orders()->create([
                'billing_order_id' => Str::uuid(),
                'order_number' => BillingOrder::generateOrderNumber(),
                'status' => OrderStatus::PENDING,
                'currency' => $currency = $cart->getCurrencyCode(),
                'subtotal' => $cart->getItemTotals(true),
                'discount' => $cart->getDiscountTotal(),
                'tax' => $cart->getItemTaxTotals(),
                'shipping' => $cart->getShippingTotal() + $cart->getHandlingTotal(),
                'total' => $total_amount = $cart->getGrandTotalFromExtraTax(
                   true,
                   config("billing.providers.{$paymentProviderValue}.extra_tax", 0)
                ),
                'type' => 'one_time',
                'payment_status' => PaymentStatus::UNPAID,
                'payment_provider'  => $paymentProvider, // 'paypal', 'stripe', 'mpesa', etc.
                'sub_payment_provider' => 'vault_token',
                // 'payment_provider_order_id', // external payment reference
                // 'payment_provider_checkout_id', // external payment reference
                // 'payment_provider_transaction_id',
                'delivery_status' => DeliveryStatus::AWAITING_PROCESSING,
                'fulfillment_status' => FulfillmentStatus::AWAITING_PROCESSING,
                'metadata' => $order_metadata = [
                   'cart' => $cart->toArray(),
                   'initiate_payment' => $metadata 
                ]
            ]);

            $order_items = collect($cart->all())->map(function (CartItemInterface&OrderCalculatableInterface $cartItem) use ($order, $paymentProviderValue, $order_metadata) {

                return $order->billing_order_items()->create([
                    'billing_product_id' => $cartItem->getProduct()->getId(),
                    'name' => $cartItem->getProduct()->getName(),
                    'price' => $cartItem->getProduct()->getListedPrice(),
                    'thumbnail' => $cartItem->getProduct()->getImageUrl(),
                    'url' => $cartItem->getProduct()->getUrl(),
                    'quantity' => $cartItem->getQuantity(),
                    'currency' => $cartItem->getCurrencyCode(),
                    'subtotal' => $cartItem->getItemTotals(true), // before tax & shipping
                    'discount' => $cartItem->getDiscountTotal(),
                    'tax' => $cartItem->getItemTaxTotals(),
                    'shipping' => $cartItem->getShippingTotal() + $cartItem->getHandlingTotal(),
                    'total' => $total_amount = $cartItem->getGrandTotalFromExtraTax(
                        true,
                        config("billing.providers.{$paymentProviderValue}.extra_tax", 0)
                    ),
                    'type' => $cartItem->getProduct()->getProductType() === ProductType::SERVICE ? 'recurring' : 'one-time',
                    'status' => OrderStatus::PENDING,
                    'payment_status' => PaymentStatus::UNPAID,
                    'delivery_status' => DeliveryStatus::AWAITING_PROCESSING,
                    'fulfillment_status' => FulfillmentStatus::AWAITING_PROCESSING,
                    'metadata' => $order_metadata,
                ]); 

            });
   
   
            $payment_transaction = $user->billing_payment_transactions()->create([
               'billing_payment_transaction_id' => Str::uuid(),
               'action_type' => ActionType::CREATE,
               'type' => PaymentTransactionType::PAYMENT,
               'status' => PaymentTransactionStatus::PENDING,
               'total_amount' => $total_amount, 
               'currency' => $currency,
               'payment_provider' => $paymentProvider,
               'sub_payment_provider' => 'vault_token',
               'metadata' => [
                   'cart' => $cart->toArray(),
                   'initiate_payment' => $metadata 
                ],
            ]);
   
           
            $payment_transaction->billing_order()->associate($order);
            $payment_transaction->save();
   
            \Illuminate\Support\Facades\Log::debug('Saved Payment Transaction', [
               'payment_transaction_id' => $payment_transaction->billing_payment_transaction_id,
               'order_id' => $order->billing_order_id,
               'user_id' => $user->getKey(),
            ]);
   
            $syncData = collect($cart->all())
               ->mapWithKeys(fn (CartItemInterface $item) => [
                   $item->getProduct()->getId() => ['quantity' => $item->getQuantity()]
                ])->toArray();
   
            $order->billing_products()->sync($syncData, false);

            event(new PaymentInitiated($order, $payment_transaction));
   
            $providerData = array_merge($paymentData, [
               'user' => $user,
               'billing_order_id' => $order->billing_order_id,
               'billing_payment_transaction_id' => $payment_transaction->billing_payment_transaction_id,
               'product_type' => ApiProductTypeKey::ONE_TIME->value,
               'order_number' => $order->order_number,
               'amount' => $total_amount,
               'currency' => $currency,
               'metadata' => $metadata
            ]);
               
            $result = is_string($token) ? $tokenPaymentProvider->completePaymentWithToken(
                $cart, $token, $providerData
            ) : $tokenPaymentProvider->completePaymentWithSavedToken($cart, $token, $providerData);
   
            $order->update([
               'payment_provider_checkout_id' => $result->providerCheckoutId,
               'payment_provider_order_id' => $result->providerOrderId,
               'status' => $order_status = match ($result->status) {
                    PaymentStatus::PENDING => OrderStatus::PENDING,
                    PaymentStatus::FAILED => OrderStatus::FAILED,
                    PaymentStatus::COMPLETED => OrderStatus::COMPLETED,
                    PaymentStatus::PAID => OrderStatus::AWAITING_PROCESSING,
                },
                'payment_status' => $result->status,
                'processed_at' => now(),
                'metadata' =>  array_merge($order->metadata, ['complete_payment' => $result->metadata ?? []])
            ]);

            $order_items->each(fn (BillingOrderItem $order_item) => $order_item->update([
                'status' => $order_status,
                'payment_status' => $result->status,
                'processed_at' => now(),
                'metadata' =>  array_merge($order_item->metadata, ['complete_payment' => $result->metadata ?? []])
            ]));
   
            $payment_transaction->update([
               'payment_provider_transaction_id' => $result->providerTransactionId,
               'payment_provider_checkout_id' => $result->providerCheckoutId,
               'status' => match ($result->status) {
                   PaymentStatus::PENDING => PaymentTransactionStatus::PENDING,
                   PaymentStatus::FAILED => PaymentTransactionStatus::FAILED,
                   PaymentStatus::COMPLETED => PaymentTransactionStatus::COMPLETED,
                   PaymentStatus::PAID => PaymentTransactionStatus::PAID,
                },
                'transacted_at' => now(),
                'metadata' => array_merge($payment_transaction->metadata, ['complete_payment' => $result->metadata ?? []])
            ]);

            switch ($result->status) {
                case PaymentStatus::PENDING:
                    // Handle pending status
                    break;
                case PaymentStatus::FAILED:
                    // Handle failed status
                    event(new PaymentFailed($order, $payment_transaction));
                    break;
                case PaymentStatus::COMPLETED:
                    // Handle completed status
                    event(new PaymentCompleted($order, $payment_transaction));
                    break;
                case PaymentStatus::PAID:
                    event(new PaymentCaptured($order, $payment_transaction));
                    break;
            }
   
            return $result->setCheckoutDetails(
                new CheckoutDetails(
                    $payment_transaction,
                    $order,
                    $order_items,
                    checkoutUrl: $result->getCheckoutUrl()
                )
            );
        }); 
    }

    public function initializePayment(
        Billable $user,
        PaymentProvider|string $paymentProvider,
        CartInterface $cart,
        array $paymentData = [],
        array $metadata = []
    ): PaymentResult {

        return DB::transaction(function (Connection $conn) use ($user, $paymentProvider, $cart, $paymentData, $metadata): PaymentResult {

            $metadata = array_merge($metadata, ['cart' => $cart->toArray()]);
            $paymentProviderValue = $this->resolveProviderValue($paymentProvider);

            $provider = $this->provider($paymentProviderValue);

            $order = $user->billing_orders()->create([
                'billing_order_id' => Str::uuid(),
                'order_number' => BillingOrder::generateOrderNumber(),
                'status' => OrderStatus::PENDING,
                'currency' => $currency = $cart->getCurrencyCode(),
                'subtotal' => $cart->getItemTotals(false),
                'discount' => $cart->getDiscountTotal(),
                'tax' => $cart->getItemTaxTotals(),
                'shipping' => $cart->getShippingTotal() + $cart->getHandlingTotal(),
                'total' => $total_amount = $cart->getGrandTotalFromExtraTax(
                    true,
                    config("billing.providers.{$paymentProviderValue}.extra_tax", 0)
                ),
                'type' => 'one_time',
                'payment_status' => PaymentStatus::UNPAID,
                'payment_provider'  => $paymentProvider, // 'paypal', 'stripe', 'mpesa', etc.
                'sub_payment_provider' => match ($paymentProviderValue) {
                    'card' => $paymentData['card_gateway'] ?? null,
                    default => null
                },
                'delivery_status' => DeliveryStatus::AWAITING_PROCESSING,
                'fulfillment_status' => FulfillmentStatus::AWAITING_PROCESSING,
                'metadata' => $metadata
            ]);

            $order_items = collect($cart->all())->map(function (CartItemInterface&OrderCalculatableInterface $cartItem) use ($order, $paymentProviderValue, $metadata) {

                return $order->billing_order_items()->create([
                    'billing_product_id' => $cartItem->getProduct()->getId(),
                    'name' => $cartItem->getProduct()->getName(),
                    'price' => $cartItem->getProduct()->getListedPrice(),
                    'thumbnail' => $cartItem->getProduct()->getImageUrl(),
                    'url' => $cartItem->getProduct()->getUrl(),
                    'quantity' => $cartItem->getQuantity(),
                    'currency' => $cartItem->getCurrencyCode(),
                    'subtotal' => $cartItem->getItemTotals(true), // before tax & shipping
                    'discount' => $cartItem->getDiscountTotal(),
                    'tax' => $cartItem->getItemTaxTotals(),
                    'shipping' => $cartItem->getShippingTotal() + $cartItem->getHandlingTotal(),
                    'total' => $total_amount = $cartItem->getGrandTotalFromExtraTax(
                        true,
                        config("billing.providers.{$paymentProviderValue}.extra_tax", 0)
                    ),
                    'type' => $cartItem->getProduct()->getProductType() === ProductType::SERVICE ? 'recurring' : 'one-time',
                    'status' => OrderStatus::PENDING,
                    'payment_status' => PaymentStatus::UNPAID,
                    'delivery_status' => DeliveryStatus::AWAITING_PROCESSING,
                    'fulfillment_status' => FulfillmentStatus::AWAITING_PROCESSING,
                    'metadata' => $metadata,
                ]); 

            });


            $payment_transaction = $user->billing_payment_transactions()->create([
                'billing_payment_transaction_id' => Str::uuid(),
                'action_type' => ActionType::CREATE,
                'type' => PaymentTransactionType::PAYMENT,
                'status' => PaymentTransactionStatus::PENDING,
                'total_amount' => $total_amount, 
                'currency' => $currency,
                'payment_provider' => $paymentProviderValue,
                'metadata' => $metadata,
            ]);

            
            $payment_transaction->billing_order()->associate($order);
            $payment_transaction->save();

            \Illuminate\Support\Facades\Log::debug('Saved Payment Transaction', [
                'payment_transaction_id' => $payment_transaction->billing_payment_transaction_id,
                'order_id' => $order->billing_order_id,
                'user_id' => $user->getKey(),
            ]);

            $syncData = collect($cart->all())
                ->mapWithKeys(fn (CartItemInterface $item) => [
                    $item->getProduct()->getId() => ['quantity' => $item->getQuantity()]
                ])->toArray();

            $order->billing_products()->sync($syncData, false);
    
            $providerData = array_merge($paymentData, [
                'user' => $user,
                'billing_order_id' => $order->billing_order_id,
                'billing_payment_transaction_id' => $payment_transaction->billing_payment_transaction_id,
                'order_number' => $order->order_number,
                'product_type' => ApiProductTypeKey::ONE_TIME->value,
                'amount' => $total_amount,
                'currency' => $currency,
                'metadata' => array_merge($metadata, $paymentData)
            ]);
    
            $result = $provider->initializePayment($cart, InitializeOrderRequest::fromArray($providerData));

             \Illuminate\Support\Facades\Log::info(__METHOD__ . ' CompletePayment Result', [
                'result' => $result,
                'metadata' => $result->metadata
            ]);

            if (!$result->success && $result->throw) throw new PaymentInitiationException($result);
    
            $order->update([
                'payment_provider_checkout_id' => $result->providerCheckoutId,
                'payment_provider_order_id' => $result->providerOrderId,
                'payment_provider_transaction_id' => $result->providerTransactionId,
                'status' => $order_status = match ($result->status) {
                    PaymentStatus::PENDING => OrderStatus::PENDING,
                    PaymentStatus::FAILED => OrderStatus::FAILED,
                    PaymentStatus::APPROVED => OrderStatus::PROCESSED,
                    PaymentStatus::COMPLETED => OrderStatus::COMPLETED,
                    PaymentStatus::PAID => OrderStatus::AWAITING_PROCESSING,
                },
                'payment_status' => $result->status,
                'metadata' =>  array_merge($metadata, ['initiate_payment' => $result->metadata ?? []])
            ]);

            $order_items->each(fn (BillingOrderItem $order_item) => $order_item->update([
                'status' => $order_status,
                'payment_status' => $result->status,
                'processed_at' => now(),
                'metadata' =>  array_merge($order_item->metadata, ['initiate_payment' => $result->metadata ?? []])
            ]));

            $payment_transaction->update([
                'payment_provider_transaction_id' => $result->providerTransactionId,
                'payment_provider_checkout_id' => $result->providerCheckoutId,
                'status' => match ($result->status) {
                    PaymentStatus::PENDING => PaymentTransactionStatus::PENDING,
                    PaymentStatus::FAILED => PaymentTransactionStatus::FAILED,
                    PaymentStatus::APPROVED => PaymentTransactionStatus::APPROVED,
                    PaymentStatus::COMPLETED => PaymentTransactionStatus::COMPLETED,
                    PaymentStatus::PAID => PaymentTransactionStatus::PAID,
                },
                'metadata' => array_merge($metadata, ['initiate_payment' => $result->metadata ?? []])
            ]);

            event(new PaymentInitiated($order, $payment_transaction));
    
            return $result->setCheckoutDetails(
                new CheckoutDetails(
                    $payment_transaction,
                    $order,
                    $order_items,
                    checkoutUrl: $result->getCheckoutUrl()
                )
            );
            
        });
    }

    public function completePayment(
        Billable $user, PaymentProvider|string $paymentProvider,
        CheckoutDetails $checkoutDetails, string $providerOrderId, array $metadata = []
    ): ?PaymentResult 
    {
        $paymentProviderValue = $this->resolveProviderValue($paymentProvider);
        $provider = $this->provider($paymentProviderValue);

        \Illuminate\Support\Facades\Log::info('Completing Payment', [
            'user_id' => $user->getKey(),
            'payment_provider' => $paymentProviderValue,
            'provider_order_id' => $providerOrderId,
        ]);

        $result = $provider->completePayment(
            CompleteOrderRequest::make(
                $user, $checkoutDetails->getBillingOrder()->billing_order_id,
                $checkoutDetails->getBillingPaymentTransaction()->billing_payment_transaction_id,
                $checkoutDetails->getBillingOrder()->order_number,
                $checkoutDetails->getBillingOrder()->payment_provider_order_id ?? $providerOrderId, 
                $checkoutDetails->getBillingOrder()->payment_provider_checkout_id,
                $checkoutDetails->getBillingPaymentTransaction()->payment_provider_transaction_id ?? $checkoutDetails->getBillingOrder()->payment_provider_transaction_id,
                null,
                null,
                null,
                [
                    ...$checkoutDetails->getBillingOrder()->metadata, 
                    ...$metadata, 
                    'amount' => $checkoutDetails->getBillingPaymentTransaction()->total_amount,
                    'total_amount' => $checkoutDetails->getBillingPaymentTransaction()->total_amount,
                    'currency' => $checkoutDetails->getBillingPaymentTransaction()->currency
                ],
            )
        );

        \Illuminate\Support\Facades\Log::info(__METHOD__ . ' CompletePayment Result', [
            'result' => $result,
            'metadata' => $result->metadata
        ]);

        $checkoutDetails->setCheckoutUrl($result->getCheckoutUrl());

        return DB::transaction(function () use ($checkoutDetails, $result) {

            $order = $checkoutDetails->getBillingOrder();
            $order_items = $checkoutDetails->getBillingOrderItems();
            $payment_transaction = $checkoutDetails->getBillingPaymentTransaction();

            switch ($result->status) {
                case PaymentStatus::APPROVED:

                    $order->update([
                        'status' => $order_status = OrderStatus::AWAITING_PROCESSING,
                        'payment_status' => $result->status,
                        'processed_at' => now(),
                        'metadata' => array_merge($order->metadata, ['complete_payment' => $result->metadata ?? []])
                    ]);

                    $order_items->map(fn (BillingOrderItem $order_item) => $order_item->fill([
                        'status' => $order_status,
                        'payment_status' => $result->status,
                        'processed_at' => now(),
                        'metadata' => array_merge($order_item->metadata, ['complete_payment' => $result->metadata ?? []])
                    ])->save());

                    $payment_transaction->update([
                        'payment_provider_transaction_id' => $result->providerTransactionId,
                        'status' => PaymentTransactionStatus::APPROVED,
                        'transacted_at' => now(),
                        'metadata' => array_merge($payment_transaction->metadata, ['complete_payment' => $result->metadata ?? []])
                    ]);

                    event(new PaymentApproved($order, $payment_transaction));
                    event(new OrderAwaitingProcessing($order));

                    return $result->setCheckoutDetails($checkoutDetails);
                case PaymentStatus::PAID:
                    
                    $order->update([
                        'status' => $order_status = OrderStatus::AWAITING_PROCESSING,
                        'payment_status' => $result->status,
                        'processed_at' => now(),
                        'metadata' => array_merge($order->metadata, ['complete_payment' => $result->metadata ?? []])
                    ]);

                    $order_items->map(fn (BillingOrderItem $order_item) => $order_item->fill([
                        'status' => $order_status,
                        'payment_status' => $result->status,
                        'processed_at' => now(),
                        'metadata' => array_merge($order_item->metadata, ['complete_payment' => $result->metadata ?? []])
                    ])->save());

                    $payment_transaction->update([
                        'payment_provider_transaction_id' => $result->providerTransactionId,
                        'status' => PaymentTransactionStatus::PAID,
                        'transacted_at' => now(),
                        'metadata' => array_merge($payment_transaction->metadata, ['complete_payment' => $result->metadata ?? []])
                    ]);

                    event(new PaymentCaptured($order, $payment_transaction));
                    event(new OrderAwaitingProcessing($order));

                    return $result->setCheckoutDetails($checkoutDetails);
                case PaymentStatus::COMPLETED:
                    $order->update([
                        'status' => $order_status = OrderStatus::COMPLETED,
                        'payment_status' => $result->status,
                        'processed_at' => now(),
                        'metadata' => array_merge($order->metadata, ['complete_payment' => $result->metadata ?? []])
                    ]);

                    $order_items->each(fn (BillingOrderItem $order_item) => $order_item->update([
                        'status' => $order_status,
                        'payment_status' => $result->status,
                        'processed_at' => now(),
                        'metadata' =>  array_merge($order_item->metadata, ['complete_payment' => $result->metadata ?? []])
                    ]));

                    $payment_transaction->update([
                        'payment_provider_transaction_id' => $result->providerTransactionId,
                        'status' => PaymentTransactionStatus::COMPLETED,
                        'transacted_at' => now(),
                        'metadata' => array_merge($payment_transaction->metadata, ['complete_payment' => $result->metadata ?? []])
                    ]);

                    event(new PaymentCompleted($order, $payment_transaction));
                    
                    return $result->setCheckoutDetails($checkoutDetails);
                case PaymentStatus::FAILED:
                    $order->update([
                        'status' => $order_status = OrderStatus::FAILED,
                        'payment_status' => $result->status,
                        'processed_at' => now(),
                        'metadata' => array_merge($order->metadata, ['complete_payment' => $result->metadata ?? []])
                    ]);

                    $order->billing_order_items->each(fn (BillingOrderItem $order_item) => $order_item->update([
                        'status' => $order_status,
                        'payment_status' => $result->status,
                        'processed_at' => now(),
                        'metadata' =>  array_merge($order_item->metadata, ['complete_payment' => $result->metadata ?? []])
                    ]));
                    

                    $payment_transaction->update([
                        'payment_provider_transaction_id' => $result->providerTransactionId,
                        'status' => PaymentTransactionStatus::FAILED,
                        'transacted_at' => now(),
                        'metadata' => array_merge($payment_transaction->metadata, ['complete_payment' => $result->metadata ?? []])
                    ]);

                    event(new PaymentFailed($order, $payment_transaction));
                    
                    return $result->setCheckoutDetails($checkoutDetails);
                case PaymentStatus::PENDING:
                case PaymentStatus::DEFAULT:
                default:
                    return $result->setCheckoutDetails($checkoutDetails);
            }
        });

    }

    public function cancelPayment(
        Billable $user,
        PaymentProvider|string $paymentProvider,
        string $providerOrderId,
        array $metadata = []
    ): bool
    {
        return DB::transaction(function () use ($user, $paymentProvider, $providerOrderId, $metadata): bool {

            $paymentProviderValue = $this->resolveProviderValue($paymentProvider);
            $provider = $this->provider($paymentProviderValue);

            $order = $user->billing_orders()->where('payment_provider_order_id', $providerOrderId)->first();

            $order->loadMissing(['billing_order_items']);
            
            $order->update([
                'processed_at' => now(),
                'status' => $order_status = OrderStatus::CANCELLED,
                'payment_status' => $payment_status = PaymentStatus::CANCELED,
                'metadata' =>  array_merge($order->metadata , $metadata, ['cancel_payment' => $result->metadata ?? []])
            ]);

            $order->billing_order_items->each(fn (BillingOrderItem $order_item) => $order_item->update([
                'status' => $order_status,
                'payment_status' => $payment_status,
                'processed_at' => now(),
                'metadata' =>  array_merge($order_item->metadata, ['cancel_payment' => $result->metadata ?? []])
            ]));

            $payment_transaction = $order->billing_payment_transaction()->sole();
            $payment_transaction->update([
                'transacted_at' => now(),
                'status' => PaymentTransactionStatus::CANCELED,
                'metadata' => array_merge($order->metadata , $metadata, $metadata, ['cancel_payment' => $result->metadata ?? []])
            ]);

            event(new PaymentCanceled($order, $payment_transaction));
            event(new OrderCanceled($order));

            return true;
            
        });
    }

    public function refundPayment(string $orderId, ?float $amount = null): bool
    {
        $order = Billing::$billingOrder::with(['billing_order_items'])->where('order_id', $orderId)->first();
        
        if (!$order) {
            return false;
        }

        $success = $this->provider(
            $paymentProviderValue = $this->resolveProviderValue($order->payment_provider)
        )->refundPayment($order->payment_provider_order_id, $amount);

        if ($success) {
            $order->update([
                'status' => $order_status = OrderStatus::REFUNDED, 
                'payment_status' => $payment_status = PaymentStatus::REFUNDED,
                'delivery_status' => DeliveryStatus::AWAITING_RETURN,
            ]);

            $order->billing_order_items->each(fn (BillingOrderItem $order_item) => $order_item->update([
                'status' => $order_status,
                'payment_status' => $payment_status,
                'processed_at' => now(),
            ]));

            $order->billing_payment_transaction()->update([
                'status' => PaymentStatus::REFUNDED,
            ]);
        }

        return $success;
    }
}