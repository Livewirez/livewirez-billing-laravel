<?php 

namespace Livewirez\Billing;

use DateInterval;
use App\Models\User;
use RuntimeException;
use DateTimeInterface;
use Tekord\Result\Result;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Livewirez\Billing\Lib\Cart;
use Illuminate\Support\Facades\DB;
use Livewirez\Billing\Lib\CartItem;
use Illuminate\Support\Facades\Cache;
use Livewirez\Billing\Facades\Billing;
use Livewirez\Billing\Enums\ActionType;
use Livewirez\Billing\Enums\EntityType;
use Livewirez\Billing\Enums\OrderStatus;
use Livewirez\Billing\Enums\ProductType;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Models\BillingPlan;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Lib\CheckoutDetails;
use Livewirez\Billing\Models\BillingOrder;
use Livewirez\Billing\Enums\DeliveryStatus;
use Livewirez\Billing\Events\PaymentFailed;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Events\OrderProcessed;
use Livewirez\Billing\Models\BillingProduct;
use Livewirez\Billing\Events\PaymentCaptured;
use Livewirez\Billing\Enums\ApiProductTypeKey;
use Livewirez\Billing\Enums\FulfillmentStatus;
use Livewirez\Billing\Events\PaymentCompleted;
use Livewirez\Billing\Events\PaymentInitiated;
use Livewirez\Billing\Models\BillingOrderItem;
use Livewirez\Billing\Models\BillingPlanPrice;
use Livewirez\Billing\Lib\SubscriptionContext;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Interfaces\CartInterface;
use Livewirez\Billing\Providers\PayPalProvider;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Livewirez\Billing\Models\BillingDiscountCode;
use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Enums\PaymentTransactionType;
use Livewirez\Billing\Events\SubscriptionActivated;
use Livewirez\Billing\Events\SubscriptionIntitated;
use Livewirez\Billing\Models\BillablePaymentMethod;
use Livewirez\Billing\Providers\PayPalHttpProvider;
use Livewirez\Billing\Enums\SubscriptionEvent as EventEnum;
use Livewirez\Billing\Enums\PaymentTransactionStatus;
use Livewirez\Billing\Lib\Orders\CompleteOrderRequest;
use Livewirez\Billing\Enums\SubscriptionTransactionType;
use Livewirez\Billing\Lib\Orders\InitializeOrderRequest;
use Livewirez\Billing\Interfaces\ResolvesPaymentProvider;
use Livewirez\Billing\Enums\SubscriptionTransactionStatus;
use Livewirez\Billing\Interfaces\PaymentProviderInterface;
use Livewirez\Billing\Exceptions\PaymentInitiationException;
use Livewirez\Billing\Traits\HandlesPaymentProviderResolution;
use Livewirez\Billing\Exceptions\SubscriptionActivationException;
use Livewirez\Billing\Exceptions\SubscriptionInitiationException;

class SubscriptionsManager implements ResolvesPaymentProvider
{
    use HandlesPaymentProviderResolution;

    /**
     * Determine the type of subscription change based on plan and price comparison.
     */
    protected static function determineSubscriptionChange(
        BillingSubscription $existingSubscription,
        BillingPlan $newPlan,
        BillingPlanPrice $newPrice
    ): SubscriptionContext {
        $currentPlan = $existingSubscription->billing_plan;
        $currentPrice = $existingSubscription->billing_plan_price;
        
        // Check if this is the exact same plan and price
        if (static::isSamePlanAndPrice($existingSubscription, $newPlan, $newPrice)) {
            return static::handleSamePlanSelection(
                existingSubscription: $existingSubscription,
                plan: $newPlan,
                price: $newPrice,
                currentPlan: $currentPlan,
                currentPrice: $currentPrice
            );
        }

        // Check if it's the same plan but different price (interval change)
        if (static::isSamePlanDifferentPrice($existingSubscription, $newPlan, $newPrice)) {
            return static::handlePriceChange(
                existingSubscription: $existingSubscription,
                newPrice: $newPrice,
                currentPrice: $currentPrice,
                plan: $newPlan,
                currentPlan: $currentPlan
            );
        }

        // Different plan - check for upgrade/downgrade
        return static::handlePlanChange(
            existingSubscription: $existingSubscription,
            newPlan: $newPlan,
            newPrice: $newPrice,
            currentPlan: $currentPlan,
            currentPrice: $currentPrice
        );
    }

    /**
     * Check if the selected plan and price are identical to current subscription.
     */
    protected static function isSamePlanAndPrice(
        BillingSubscription $subscription,
        BillingPlan $plan,
        BillingPlanPrice $price
    ): bool {
        return $subscription->billing_plan_id === $plan->id
            && $subscription->billing_plan_price_id === $price->id;
    }

    /**
     * Check if it's the same plan but different price/interval.
     */
    protected static function isSamePlanDifferentPrice(
        BillingSubscription $subscription,
        BillingPlan $plan,
        BillingPlanPrice $price
    ): bool {
        return $subscription->billing_plan_id === $plan->id
            && $subscription->billing_plan_price_id !== $price->id;
    }

    /**
     * Handle case where user selects their current plan and price.
     * This could be a renewal (if inactive) or static (if active).
     */
    protected static function handleSamePlanSelection(
        BillingSubscription $existingSubscription,
        BillingPlan $plan,
        BillingPlanPrice $price,
        BillingPlan $currentPlan,
        BillingPlanPrice $currentPrice
    ): SubscriptionContext {
        // If subscription is inactive, this is a renewal/reactivation
        // If active, it's a static/no-change scenario

        $isSameDay = $existingSubscription->starts_at?->format('d') === now()->format('d') && 
        $existingSubscription->starts_at?->format('m') === now()->format('m') 
        && $existingSubscription->starts_at?->format('Y') === now()->format('Y');
        
        $type = $existingSubscription->isActive()
            ? SubscriptionTransactionType::Static 
            : ($isSameDay ? SubscriptionTransactionType::Retry : SubscriptionTransactionType::Renewal);

        return SubscriptionContext::make(
            type: $type,
            existingSubscription: $existingSubscription,
            plan: $plan,
            price: $price,
            currentPlan: $currentPlan,
            currentPlanPrice: $currentPrice
        );
    }

    /**
     * Handle price/interval change on the same plan.
     * Determines if it's a price increase or decrease based on interval ranking.
     */
    protected static function handlePriceChange(
        BillingSubscription $existingSubscription,
        BillingPlanPrice $newPrice,
        BillingPlanPrice $currentPrice,
        BillingPlan $plan,
        BillingPlan $currentPlan
    ): SubscriptionContext {
        $newIntervalRank = $newPrice->interval->ranking();
        $currentIntervalRank = $currentPrice->interval->ranking();

        // Compare interval rankings: DAILY(1) < WEEKLY(2) < MONTHLY(3) < YEARLY(4)
        // Higher interval = longer commitment = price increase
        $type = $newIntervalRank > $currentIntervalRank
            ? SubscriptionTransactionType::PriceIncrease
            : SubscriptionTransactionType::PriceDecrease;

        return SubscriptionContext::make(
            type: $type,
            existingSubscription: $existingSubscription,
            plan: $plan,
            price: $newPrice,
            currentPlan: $currentPlan,
            currentPlanPrice: $currentPrice
        );
    }

    /**
     * Handle plan changes (different plan).
     * Determines upgrade, downgrade, or lateral move based on ranking.
     */
    protected static function handlePlanChange(
        BillingSubscription $existingSubscription,
        BillingPlan $newPlan,
        BillingPlanPrice $newPrice,
        BillingPlan $currentPlan,
        BillingPlanPrice $currentPrice
    ): SubscriptionContext {
        $currentRanking = $currentPlan->ranking;
        $newRanking = $newPlan->ranking;

        // Determine transaction type based on plan ranking comparison
        $type = match (true) {
            $newRanking > $currentRanking => SubscriptionTransactionType::Upgrade,
            $newRanking < $currentRanking => SubscriptionTransactionType::Downgrade,
            default => SubscriptionTransactionType::Static, // Lateral move at same tier
        };

        return SubscriptionContext::make(
            type: $type,
            existingSubscription: $existingSubscription,
            plan: $newPlan,
            price: $newPrice,
            currentPlan: $currentPlan,
            currentPlanPrice: $currentPrice
        );
    }

    public static function prepareSubscriptionContext(Billable $user, BillingPlan $plan, BillingPlanPrice $price): SubscriptionContext
    {
        $existingSubscription = $user->billing_subscription()->with(relations: [
            'billing_plan', 'billing_plan_price'
        ])->first();

        // New subscription
        if (! $existingSubscription) {
            return SubscriptionContext::make(SubscriptionTransactionType::Initial, plan: $plan, price: $price);
        }

        // User has existing subscription - determine transaction type
        return static::determineSubscriptionChange(
            existingSubscription: $existingSubscription,
            newPlan: $plan,
            newPrice: $price
        );
    }

    /**
     * Calculate next billing date based on plan interval
     */
    protected function calculateNextBillingDate(DateTimeInterface $start, BillingPlanPrice $planPrice, ?string $customInterval = null): DateTimeInterface
    {
        /** @var SubscriptionInterval */
        $interval = $planPrice->interval;
        $count = $planPrice->billing_interval_count;

        return $interval->calculateNextInterval($start, $count, $customInterval);
    }

    /**
     * Validate discount code
     */
    protected function validateDiscountCode(string $code, BillingPlan $plan, Billable $user): BillingDiscountCode
    {
        $discount = BillingDiscountCode::where('code', $code)->first();

        if (!$discount) {
            throw new \Exception('Invalid discount code');
        }

        if (!$discount->isValid()) {
            throw new \Exception('Discount code is expired or inactive');
        }

        if (!$discount->isApplicableToPlan($plan)) {
            throw new \Exception('Discount code is not applicable to this plan');
        }

        if (!$discount->canBeUsedByCustomer($user)) {
            throw new \Exception('You have already used this discount code');
        }

        return $discount;
    }

    /**
     * Apply discount to subscription
     */
    protected function applyDiscount(BillingSubscription $subscription, BillingDiscountCode $discount, int $discountAmount): void
    {
        $subscription->billing_subscription_discounts()->create([
            'billing_discount_code_id' => $discount->id,
            'discount_amount' => $discountAmount,
        ]);

        // Increment usage count
        $discount->increment('used_count');
    }

    public function initiateSubscription(
        Billable $user,
        PaymentProvider|string $paymentProvider,
        BillingPlanPrice $planPrice,
        array $subscriptionData = [],
        array $metadata = []
    ): SubscriptionResult {
        return DB::transaction(function () use ($user, $paymentProvider, $planPrice, $subscriptionData, $metadata): SubscriptionResult {

            $plan = $planPrice->billing_plan()->with(['billing_product'])->first();

            if (
                $user->hasActiveBillingSubscriptionWithSamePlan($plan->id, $planPrice->id) 
            ) {

                $subscription = $user->billing_subscription()->where('status', SubscriptionStatus::ACTIVE)->sole();

                return new SubscriptionResult(
                    true, 
                    $subscription->billing_subscription_id,
                    PaymentStatus::COMPLETED,
                    $subscription->status,
                    Result::success($subscription->metadata),
                    null,
                    $subscription->payment_provider_subscription_id,
                    $subscription->payment_provider_checkout_id,
                    null,
                    null,
                    $subscription->payment_provider_plan_id,
                    'Subscription Active',
                    $subscription->metadata
                );
            }
            
            $paymentProviderValue = $this->resolveProviderValue($paymentProvider);

            $provider = $this->provider($paymentProviderValue);

            $metadata = array_merge($metadata, [ 
                'billing_plan_info' => [
                    'billing_plan' =>  $plan->only(['id', 'billing_plan_id', 'billing_product_id', 'name']),
                    'billing_plan_price' => $planPrice->only([
                        'id', 'billing_plan_id',
                        'interval',
                        'custom_interval_count',
                        'amount',
                        'currency',
                        'scale'
                    ]),
                ],
                'status' => 'PENDING',
                'payment_status' => 'PENDING',
            ]);

            
            $is_paypal = $paymentProvider === PaymentProvider::PayPal || $paymentProviderValue === 'paypal';

            $subscription = null;
            $subscription_transaction = null;
            $payment_transaction = null;

            $discount = null;
            $finalPrice = $planPrice->amount; 
            
            if (isset($subscriptionData['discount_code']) && ($discountCode = $subscriptionData['discount_code'])) {
                $discount = $this->validateDiscountCode($discountCode, $plan, $user);
                $finalPrice = $planPrice->calculateDiscountedPrice($discount);
            } 

            $order = $user->billing_orders()->create([
                'billing_order_id' => Str::uuid(),
                'order_number' => BillingOrder::generateOrderNumber(),
                'status' => OrderStatus::PENDING,
                'currency' => $currency = $planPrice->currency,
                'subtotal' => $planPrice->amount,
                'discount' => $discount?->value ?? 0,
                'tax' => 0,
                'shipping' => 0,
                'total' => $finalPrice,
                'payment_status' => PaymentStatus::UNPAID,
                'payment_provider'  => $paymentProvider, 
                'metadata' => $metadata,
            ]);

            \Illuminate\Support\Facades\Log::debug(__METHOD__, [
                'order' => $order,
                'is_paypal' => $is_paypal,
                'metadata' => $metadata,
                'plan' => $plan,
                'planPrice' => $planPrice,
                'finalPrice' => $finalPrice
            ]);

            $order_item = $order->billing_order_items()->create([
                'billing_product_id' => $plan->billing_product->id,
                'billing_plan_id' => $plan->id,
                'billing_plan_price_id' => $planPrice->id, 
                'name' => $plan->name,
                'price' => $planPrice->amount,
                'thumbnail' => $plan->thumbnail,
                'url' => $plan->url,
                'quantity' => 1,
                'currency' => $currency,
                'subtotal' => $planPrice->amount, // before tax & shipping
                'discount' => $discount?->value ?? 0,
                'tax' => 0,
                'shipping' => 0,
                'total' => $finalPrice,
                'type' => 'subscription', //  'subscription' : 'one-time'
                'status' => OrderStatus::PENDING,
                'payment_status' => PaymentStatus::UNPAID,
                'delivery_status' => DeliveryStatus::AWAITING_PROCESSING,
                'fulfillment_status' => FulfillmentStatus::AWAITING_PROCESSING,
                'metadata' => $metadata,
            ]);

            \Illuminate\Support\Facades\Log::debug(__METHOD__, [
                'order_item' => $order,
                
            ]);

            $trialStart = null; 
            $trialEnd = null;
            //$trialDays = $plan->trial_days + ($discount?->extends_trial_days ?? 0);

            if ($is_paypal) {
                // Calculate trial period
                $trialDays = $plan->trial_days;
                $trialStart = $trialDays > 0 ? CarbonImmutable::now() : null;
                $trialEnd = $trialStart?->copy()->addDays($trialDays);
            }

            // Determine billing period
            $periodStart = $trialEnd ?? CarbonImmutable::now();
            $periodEnd = $this->calculateNextBillingDate(
                $periodStart, 
                $planPrice, 
                $subscriptionData['custom_interval'] ?? null
            ); 

            $subscriptionContext = static::prepareSubscriptionContext(
                $user,  $plan, $planPrice
            );

            $events = [];

            $start = null;
            $end = null;

            switch ($subscriptionContext->type) {
                case SubscriptionTransactionType::Initial:
                    $subscription = $user->billing_subscription()->create([
                        'billing_subscription_id' => Str::uuid(),
                        'payment_provider' => $paymentProvider,
                        'billing_plan_id' => $plan->id,
                        'billing_plan_price_id' => $planPrice->id,
                        'billing_plan_name' => $plan->name,
                        'interval' => $planPrice->interval,
                        'metadata' => $metadata,
                        ...$is_paypal ? [
                            'status' =>  $trialDays > 0 ? SubscriptionStatus::TRIALING : SubscriptionStatus::PENDING,
                            'trial_starts_at' => $trialStart,
                            'trial_ends_at' => $trialEnd,
                            'sarts_at' => $start = $periodStart,
                            'ends_at' => $end = $periodEnd,
                        ] : [
                            'status' => SubscriptionStatus::PENDING,
                            'sarts_at' => $start = CarbonImmutable::now(),
                            'ends_at' => $end = $this->calculateNextBillingDate(
                                $periodStart, 
                                $planPrice
                            )
                        ]
                    ]);

                    $subscription_transaction = $subscription->billing_subscription_transactions()->create([
                        'billing_plan_id' => $plan->id,
                        'billing_plan_price_id' => $planPrice->id,
                        'billing_plan_name' => $plan->name,
                        'transaction_ref' => $order->order_number,
                        'type' => $subscriptionContext->type,
                        'payment_provider' => $paymentProvider,
                        'amount' => $finalPrice,
                        'currency' => $planPrice->currency,
                        'interval' => $planPrice->interval,
                        'scale' => $planPrice->scale,
                        'status' => SubscriptionTransactionStatus::PENDING,
                        'payment_status' => PaymentStatus::PENDING,
                        'metadata' => $metadata,
                    ]);

                    $payment_transaction = $user->billing_payment_transactions()->create([
                        'action_type' => ActionType::CREATE,
                        'billing_payment_transaction_id' => Str::uuid(),
                        'type' => PaymentTransactionType::SUBSCRIPTION,
                        'status' => PaymentTransactionStatus::PENDING,
                        'total_amount' => $finalPrice,
                        'currency' => $planPrice->currency,
                        'payment_provider' => $paymentProvider,
                        'metadata' => $metadata,
                    ]);

                    $payment_transaction->billing_order()->associate($order);
                    $payment_transaction->billing_subscription()->associate($subscription);
                    $payment_transaction->save();

                    $events[] = EventEnum::Initial;
                    break;

                case SubscriptionTransactionType::Retry:
                case SubscriptionTransactionType::Renewal:
                    $subscription = $subscriptionContext->existingSubscription;

                    $subscription_transaction = $subscription->billing_subscription_transactions()->create([
                        'billing_plan_id' => $plan->id,
                        'billing_plan_price_id' => $planPrice->id,
                        'billing_plan_name' => $plan->name,
                        'transaction_ref' => $order->order_number,
                        'type' => $subscriptionContext->type,
                        'payment_provider' => $paymentProvider,
                        'amount' => $finalPrice,
                        'currency' => $planPrice->currency,
                        'interval' => $planPrice->interval,
                        'scale' => $planPrice->scale,
                        'status' => SubscriptionTransactionStatus::PENDING,
                        'payment_status' => PaymentStatus::PENDING,
                        'metadata' => $metadata,
                    ]);

                    $payment_transaction = $user->billing_payment_transactions()->create([
                        'action_type' => ActionType::MODIFY,
                        'billing_payment_transaction_id' => Str::uuid(),
                        'type' => PaymentTransactionType::SUBSCRIPTION,
                        'status' => PaymentTransactionStatus::PENDING,
                        'total_amount' => $finalPrice,
                        'currency' => $planPrice->currency,
                        'payment_provider' => $paymentProvider,
                        'metadata' => $metadata,
                    ]);

                    $payment_transaction->billing_order()->associate($order);
                    $payment_transaction->billing_subscription()->associate($subscription);
                    $payment_transaction->save();

                    $events[] = EventEnum::Renewal;
                    break;

                case SubscriptionTransactionType::Upgrade:

                    $subscription = $subscriptionContext->existingSubscription;

                    $previousPlan = $subscriptionContext->currentPlan;
                    $previousPlanPrice = $subscriptionContext->currentPlanPrice;

                    $plan = $newPlan = $subscriptionContext->plan;
                    $planPice = $newPlanPrice = $subscriptionContext->price;

                    $total_days = $subscription->ends_at->diffInDays($subscription->starts_at, true); 
                    $remaining_days = $subscription->ends_at->diffInDays(now(), true);

                    if ($newPlanPrice->interval !== $previousPlanPrice->interval) {
                        $substitutedPrice = $previousPlan->billing_plan_prices()->where('interval', $newPlanPrice->interval)->first();

                        $finalPrice = round(
                            (($newPlanPrice->amount - $substitutedPrice->amount) * ($remaining_days / $total_days)),
                            0
                        );
                    } else {

                        $finalPrice = round(
                            (($newPlanPrice->amount - $previousPlanPrice->amount) * ($remaining_days / $total_days)),
                            0
                        );
                    }

                    $subscription_transaction = $subscription->billing_subscription_transactions()->create([
                        'from_billing_plan_id' => $previousPlan->id,
                        'from_billing_plan_price_id' => $previousPlanPrice->id,
                        'billing_plan_id' => $newPlan->id,
                        'billing_plan_price_id' => $newPlanPrice->id,
                        'billing_plan_name' => $newPlan->name,
                        'transaction_ref' => $order->order_number,
                        'type' => $subscriptionContext->type,
                        'payment_provider' => $paymentProvider,
                        'amount' => $finalPrice,
                        'currency' => $newPlanPrice->currency,
                        'interval' => $newPlanPrice->interval,
                        'scale' => $newPlanPrice->scale,
                        'status' => SubscriptionTransactionStatus::PENDING,
                        'payment_status' => PaymentStatus::PENDING,
                        'metadata' => $metadata,
                    ]);

                    $payment_transaction = $user->billing_payment_transactions()->create([
                        'action_type' => ActionType::MODIFY,
                        'billing_payment_transaction_id' => Str::uuid(),
                        'type' => PaymentTransactionType::SUBSCRIPTION,
                        'status' => PaymentTransactionStatus::PENDING,
                        'total_amount' => $finalPrice,
                        'currency' => $newPlanPrice->currency,
                        'payment_provider' => $paymentProvider,
                        'metadata' => $metadata,
                    ]);

                    $payment_transaction->billing_order()->associate($order);
                    $payment_transaction->billing_subscription()->associate($subscription);
                    $payment_transaction->save();

                    $events[] = EventEnum::Upgrade;
                    break;

                case SubscriptionTransactionType::Downgrade:
                     $subscription = $subscriptionContext->existingSubscription;

                    $previousPlan = $subscriptionContext->currentPlan;
                    $previousPlanPrice = $subscriptionContext->currentPlanPrice;

                    $plan = $newPlan = $subscriptionContext->plan;
                    $planPice = $newPlanPrice = $subscriptionContext->price;

                    $total_days = $subscription->ends_at->diffInDays($subscription->starts_at, true); 
                    $remaining_days = $subscription->ends_at->diffInDays(now(), true);

                    if ($newPlanPrice->interval !== $previousPlanPrice->interval) {
                        $substitutedPrice = $previousPlan->billing_plan_prices()->where('interval', $newPlanPrice->interval)->first();

                        $finalPrice = round(
                            (($newPlanPrice->amount - $substitutedPrice->amount) * ($remaining_days / $total_days)),
                            0
                        );
                    } else {

                        $finalPrice = round(
                            (($newPlanPrice->amount - $previousPlanPrice->amount) * ($remaining_days / $total_days)),
                            0
                        );
                    }

                    $subscription_transaction = $subscription->billing_subscription_transactions()->create([
                        'from_billing_plan_id' => $previousPlan->id,
                        'from_billing_plan_price_id' => $previousPlanPrice->id,
                        'billing_plan_id' => $newPlan->id,
                        'billing_plan_price_id' => $newPlanPrice->id,
                        'billing_plan_name' => $newPlan->name,
                        'transaction_ref' => $order->order_number,
                        'type' => $subscriptionContext->type,
                        'payment_provider' => $paymentProvider,
                        'amount' => $finalPrice,
                        'currency' => $newPlanPrice->currency,
                        'interval' => $newPlanPrice->interval,
                        'scale' => $newPlanPrice->scale,
                        'status' => SubscriptionTransactionStatus::PENDING,
                        'payment_status' => PaymentStatus::PENDING,
                        'metadata' => $metadata,
                    ]);

                    $payment_transaction = $user->billing_payment_transactions()->create([
                        'action_type' => ActionType::MODIFY,
                        'billing_payment_transaction_id' => Str::uuid(),
                        'type' => PaymentTransactionType::SUBSCRIPTION,
                        'status' => PaymentTransactionStatus::PENDING,
                        'total_amount' => $finalPrice,
                        'currency' => $newPlanPrice->currency,
                        'payment_provider' => $paymentProvider,
                        'metadata' => $metadata,
                    ]);

                    $payment_transaction->billing_order()->associate($order);
                    $payment_transaction->billing_subscription()->associate($subscription);
                    $payment_transaction->save();

                    $events[] = EventEnum::Downgrade;
                    break;
                case SubscriptionTransactionType::PriceIncrease:
                    $subscription = $subscriptionContext->existingSubscription;

                    $previousPlan = $subscriptionContext->currentPlan;
                    $previousPlanPrice = $subscriptionContext->currentPlanPrice;

                    $plan = $newPlan = $subscriptionContext->plan;
                    $planPice = $newPlanPrice = $subscriptionContext->price;

                    $total_days = $subscription->ends_at->diffInDays($subscription->starts_at, true); 
                    $remaining_days = $subscription->ends_at->diffInDays(now(), true);

                    $finalPrice = round(
                        (($newPlanPrice->amount - $previousPlanPrice->amount) * ($remaining_days / $total_days)),
                        0
                    );

                    $subscription_transaction = $subscription->billing_subscription_transactions()->create([
                        'from_billing_plan_price_id' => $previousPlanPrice->id,
                        'billing_plan_id' => $newPlan->id,
                        'billing_plan_price_id' => $newPlanPrice->id,
                        'billing_plan_name' => $newPlan->name,
                        'transaction_ref' => $order->order_number,
                        'type' => $subscriptionContext->type,
                        'payment_provider' => $paymentProvider,
                        'amount' => $finalPrice,
                        'currency' => $newPlanPrice->currency,
                        'interval' => $newPlanPrice->interval,
                        'scale' => $newPlanPrice->scale,
                        'status' => SubscriptionTransactionStatus::PENDING,
                        'payment_status' => PaymentStatus::PENDING,
                        'metadata' => $metadata,
                    ]);

                    $payment_transaction = $user->billing_payment_transactions()->create([
                        'action_type' => ActionType::MODIFY,
                        'billing_payment_transaction_id' => Str::uuid(),
                        'type' => PaymentTransactionType::SUBSCRIPTION,
                        'status' => PaymentTransactionStatus::PENDING,
                        'total_amount' => $finalPrice,
                        'currency' => $newPlanPrice->currency,
                        'payment_provider' => $paymentProvider,
                        'metadata' => $metadata,
                    ]);

                    $payment_transaction->billing_order()->associate($order);
                    $payment_transaction->billing_subscription()->associate($subscription);
                    $payment_transaction->save();

                    $events[] = EventEnum::PriceChange;
                    break;
                default:
                    throw new RuntimeException('SubscriptionsManager::initiateSubscription Unsupported Subscription Context Type' . $subscriptionContext->type->value);

            }

            $order->billing_subscription()->associate($subscription);
            $order->save();

            $syncData = collect([$plan->billing_product])
                ->mapWithKeys(fn (BillingProduct $item) => [
                    $item->getId() => ['quantity' => 1]
                ])->toArray();

            $order->billing_products()->sync($syncData, false);

            $subscription_events = $subscription->billing_subscription_events()->createMany(array_map(fn (EventEnum $event) => [
                'type' => $event,
                'triggered_by' => 'SYSTEM',
                'billing_subscription_transaction_id' => $subscription_transaction?->id,
            ], $events));
            
            $providerData = array_merge($subscriptionData, [
                'product_type' => ApiProductTypeKey::SUBSCRIPTION->value,
                'user' => $user , 
                'billing_subscription_id' => $subscription->billing_subscription_id,
                'billing_subscription_transaction_id' => $subscription_transaction->billing_subscription_transaction_id,
                'billing_payment_transaction_id' => $payment_transaction->billing_payment_transaction_id,
                'billing_order_id' => $order->billing_order_id,
                'order_number' => $order->order_number,
                'amount' => $finalPrice,
                'currency' => $subscription_transaction->currency,
                'metadata' => $metadata,
                'start' => $start ??= now(),
                'end' => $end ??= $planPrice->interval->calculateNextInterval($start, $planPrice->billing_interval_count)
            ]);

            $result = $provider->initiateSubscription($plan, $planPrice, InitializeOrderRequest::fromArray($providerData));

            if (!$result->success && $result->throw) throw new SubscriptionInitiationException($result);

            $payment_transaction_status = match ($result->status) {
                SubscriptionStatus::APPROVED,
                SubscriptionStatus::PENDING,  
                SubscriptionStatus::APPROVAL_PENDING => PaymentTransactionStatus::PENDING,
                SubscriptionStatus::FAILED, 
                SubscriptionStatus::SUSPENDED, 
                SubscriptionStatus::CANCELLED, 
                SubscriptionStatus::CANCELED, 
                SubscriptionStatus::EXPIRED => PaymentTransactionStatus::EXPIRED,
                SubscriptionStatus::ACTIVE => PaymentTransactionStatus::COMPLETED,
                default =>  PaymentTransactionStatus::PENDING,
            };

            $order->update([
               'payment_provider_checkout_id' => $result->providerCheckoutId,
               'payment_provider_order_id' => $result->providerOrderId,
               'payment_provider_transaction_id' => $result->providerTransactionId,
               'status' => $order_status = match ($result->paymentStatus) {
                    PaymentStatus::PENDING => OrderStatus::PENDING,
                    PaymentStatus::FAILED => OrderStatus::FAILED,
                    PaymentStatus::COMPLETED => OrderStatus::COMPLETED,
                    PaymentStatus::PAID => OrderStatus::PROCESSED,
                },
                'payment_status' => $result->paymentStatus,
                'processed_at' => now(),
                'metadata' =>  array_merge(
                    $order->metadata, 
                    $metadata, 
                    $subscription->wasRecentlyCreated ? ['initiate_subscription' =>  $result->metadata ?
                        [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                        : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')]
                    ] : [
                        'modify_subscription' =>  $result->metadata ?
                            [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                            : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                    ]
                )
            ]);

            $merge = $subscription->wasRecentlyCreated ? ['initiate_subscription' =>  $result->metadata ?
                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')]
            ] : ['modify_subscription' =>  $result->metadata ?
                            [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                            : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')]
                        ];

            $subscription->update([
                'payment_provider_subscription_id' => $result->providerSubscriptionId,
                'payment_provider_checkout_id' => $result->providerCheckoutId,
                'payment_provider_plan_id' => $result->providerPlanId,
                'status' => $result->status,
                'metadata' => array_merge(
                    $subscription->metadata, 
                    $merge
                ),
                'canceled_at' => null,
                'resumed_at' => null,
                'paused_at' => null,
                'expired_at' => null,
            ]);

            $subscription_transaction->update([
                'payment_provider_subscription_id' => $result->providerSubscriptionId,
                'payment_provider_checkout_id' => $result->providerCheckoutId,
                'payment_provider_plan_id' => $result->providerPlanId,
                'status' => match ($result->paymentStatus) {
                    PaymentStatus::PENDING => SubscriptionTransactionStatus::PENDING,
                    PaymentStatus::FAILED => SubscriptionTransactionStatus::FAILED,
                    PaymentStatus::COMPLETED => SubscriptionTransactionStatus::COMPLETED,
                    PaymentStatus::PAID => SubscriptionTransactionStatus::PROCESSED,
                },
                'payment_status' => $result->paymentStatus,
                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                'payment_response' => $result->result->getOk(),
                'metadata' => array_merge(
                    $subscription_transaction->metadata,
                    $merge
                )
            ]);


            $order_item->update([
                'status' => $order_status,
                'payment_status' => $result->paymentStatus,
                'processed_at' => now(),
                'metadata' =>  array_merge(
                    $order_item->metadata, 
                    $metadata, 
                    $subscription->wasRecentlyCreated ? ['initiate_subscription' =>  $result->metadata ?
                        [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                        : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')]
                    ] : [
                        'modify_subscription' =>  $result->metadata ?
                            [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                            : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                    ]
                )
            ]);

            $payment_transaction->update([
                'payment_provider_subscription_id' => $result->providerSubscriptionId,
                'payment_provider_checkout_id' => $result->providerCheckoutId,
                'payment_provider_transaction_id' => $result->providerTransactionId,
                'status' => $payment_transaction_status,
                'metadata' => array_merge(
                    $payment_transaction->metadata, 
                    $metadata, 
                    $subscription->wasRecentlyCreated ? ['initiate_subscription' =>  $result->metadata ?
                        [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                        : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')]
                    ] : [
                        'modify_subscription' =>  $result->metadata ?
                            [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                            : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                    ]
                )
            ]);

            event(new SubscriptionIntitated($subscription));
            event(new PaymentInitiated($order, $payment_transaction));

            if ($is_paypal) {
                $key = 'paypal_subscription_transaction_' . $subscription->id;
                
                Cache::put($key, [
                    'billing_subscription_id' => $subscription->id,
                    'billing_subscription_transaction_id' => $subscription_transaction->id,
                    'billing_payment_transaction_id' => $payment_transaction->id,
                    'billing_order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);
            }

            return $result->setCheckoutDetails(
                CheckoutDetails::make(
                    $payment_transaction,
                    billingOrder: $order,
                    billingOrderItems: collect([$order_item]),
                    billingSubscription: $subscription,
                    checkoutUrl: $result->getCheckoutUrl()
                )->setSubscriptionContext(
                    $subscriptionContext->setSubcriptionTransaction($subscription_transaction)
                )
            );
        });
    }

    public function startSubscriptionUsingCheckoutDetails(
        Billable $user,
        PaymentProvider|string $paymentProvider,
        CheckoutDetails $checkoutDetails,
        string $providerSubscriptionId,
        BillingPlanPrice $planPrice,
        array $metadata = []
    ): SubscriptionResult {

        return DB::transaction(function () use ($user, $checkoutDetails, $providerSubscriptionId, $planPrice, $paymentProvider, $metadata): SubscriptionResult {

            $paymentProviderValue = $this->resolveProviderValue($paymentProvider);

            $provider = $this->provider($paymentProviderValue);

            $plan = $planPrice->billing_plan()->first();

            $subscription = $checkoutDetails->getBillingSubscription();
            $order =  $checkoutDetails->getBillingOrder();
            $order_items =  $checkoutDetails->getBillingOrderItems();
            $payment_transaction = $checkoutDetails->getBillingPaymentTransaction();
            $subscription_context = $checkoutDetails->getSubscriptionContext();
            $subscription_transaction = $subscription_context->subscriptionTransaction;

            if ($subscription_transaction === null) throw new RuntimeException(
                'SubscriptionsManager::startSubscriptionUsingCheckoutDetails Subscription Transaction is missing'
            );

            $subscription->billing_subscription_events()->create( [
                'type' => EventEnum::TransactionProcessing,
                'triggered_by' => 'SYSTEM',
                'billing_subscription_transaction_id' => $subscription_transaction?->id,
            ]);

            $providerData = array_merge($metadata, [
                'product_type' => ApiProductTypeKey::SUBSCRIPTION->value,
                'user' => $user,
                'billing_subscription_id' => $subscription->billing_subscription_id,
                'billing_subscription_transaction_id' => $subscription_transaction->billing_subscription_transaction_id,
                'billing_payment_transaction_id' => $payment_transaction->billing_payment_transaction_id,
                'billing_order_id' => $order->billing_order_id,
                'order_number' => $order->order_number,
                'amount' => $subscription_transaction?->amount ?? $planPrice->amount,
                'currency' => $subscription_transaction?->currency ?? $planPrice->currency,
                'metadata' => $metadata,
                'provider_subscription_id' => $providerSubscriptionId,
                ...$paymentProviderValue === PaymentProvider::Paddle->value ? [
                    'provider_transaction_id' => $providerSubscriptionId
                ] : [],
                ...$paymentProviderValue === PaymentProvider::Polar->value ? [
                    'provider_checkout_id' => $providerSubscriptionId
                ] : []
            ]);
            
            $result = $provider->startSubscription(CompleteOrderRequest::fromArray($providerData));

            if (!$result->success && $result->throw) throw new SubscriptionActivationException($result);

            $payment_transaction_status = match ($result->status) {
                SubscriptionStatus::APPROVED,
                SubscriptionStatus::PENDING,  
                SubscriptionStatus::APPROVAL_PENDING => PaymentTransactionStatus::PENDING,
                SubscriptionStatus::CANCELLED, 
                SubscriptionStatus::CANCELED => PaymentTransactionStatus::CANCELED, 
                SubscriptionStatus::PAST_DUE,
                SubscriptionStatus::FAILED, 
                SubscriptionStatus::SUSPENDED, 
                SubscriptionStatus::EXPIRED => PaymentTransactionStatus::EXPIRED,
                SubscriptionStatus::ACTIVE => PaymentTransactionStatus::COMPLETED,
                default =>  PaymentTransactionStatus::PENDING,
            };
            
            // $subscription->fill([
            //     'billing_plan_id' => $plan->id,
            //     'billing_plan_price_id' => $planPrice->id,
            //     'billing_plan_name' => $plan->name,
            //     'payment_provider' => $paymentProvider,
            //     'payment_provider_checkout_id' => $result->providerCheckoutId,

            //     ...match(true) {
            //         $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
            //             'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
            //             'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
            //         ],

            //         $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
            //             'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
            //             'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
            //         ],

            //         default => [
            //             'payment_provider_subscription_id' => $result->providerSubscriptionId,
            //             'status' =>  $result->status,
            //         ]
            //     },
            //     'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
            //     'payment_provider_plan_id' => $result->providerPlanId,
            //     'ended_at' => null,
            //     'canceled_at' => null,
            //     'paused_at' => null,
            //     'expired_at' => null,
            //     'resumed_at' => null,
            //     'processed_at' => now(),
            //     'metadata' => array_merge(
            //         $subscription->metadata, 
            //         $subscription->wasRecentlyCreated ? [
            //             'modify_subscription' =>  $result->metadata ?
            //                 [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
            //                 : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
            //         ] : [],
            //         ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
            //         ['complete_subscription' => $result->metadata 
            //             ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
            //             : ['date' => now()->format('Y-m-d H:i:s')]
            //         ]
            //     )
            // ]);

            $payment_transaction->update([
                'status' => $payment_transaction_status,
                'payment_provider_subscription_id' => match ($paymentProviderValue) {
                    'polar',
                    PaymentProvider::Polar->value => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                    'paddle',
                    PaymentProvider::Paddle->value => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                    default => $result->providerSubscriptionId
                },
                'payment_provider_transaction_id' => $result->providerTransactionId,
                'transacted_at' => now(),
                'metadata' => array_merge(
                    $payment_transaction->metadata, 
                    $subscription->metadata,
                    $subscription->wasRecentlyCreated ? [
                        'modify_subscription' =>  $result->metadata ?
                            [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                            : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                    ] : [], 
                    ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                    ['complete_subscription' => $result->metadata 
                        ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                        : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                    ]
                )
            ]);

            $subscription_transaction->update([
                'payment_provider' => $paymentProvider,
                'payment_provider_checkout_id' => $result->providerCheckoutId,
                ...match(true) {
                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                        'status' => match (SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status) {
                            SubscriptionStatus::APPROVED,
                            SubscriptionStatus::PENDING,  
                            SubscriptionStatus::APPROVAL_PENDING => SubscriptionTransactionStatus::PENDING,
                            SubscriptionStatus::CANCELLED, 
                            SubscriptionStatus::CANCELED => SubscriptionTransactionStatus::CANCELED, 
                            SubscriptionStatus::FAILED => SubscriptionTransactionStatus::FAILED, 
                            SubscriptionStatus::PAST_DUE,
                            SubscriptionStatus::SUSPENDED, 
                            SubscriptionStatus::EXPIRED => SubscriptionTransactionStatus::EXPIRED,
                            SubscriptionStatus::ACTIVE => SubscriptionTransactionStatus::COMPLETED,
                            default =>  SubscriptionTransactionStatus::PENDING,
                        },
                        'metadata' => array_merge(
                            $subscription->metadata, 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ],
                            $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'metadata') ?? []
                        )
                    ],

                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                        'status' => match (SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status) {
                            SubscriptionStatus::APPROVED,
                            SubscriptionStatus::PENDING,  
                            SubscriptionStatus::APPROVAL_PENDING => SubscriptionTransactionStatus::PENDING,
                            SubscriptionStatus::CANCELLED, 
                            SubscriptionStatus::CANCELED => SubscriptionTransactionStatus::CANCELED, 
                            SubscriptionStatus::FAILED => SubscriptionTransactionStatus::FAILED, 
                            SubscriptionStatus::PAST_DUE,
                            SubscriptionStatus::SUSPENDED, 
                            SubscriptionStatus::EXPIRED => SubscriptionTransactionStatus::EXPIRED,
                            SubscriptionStatus::ACTIVE => SubscriptionTransactionStatus::COMPLETED,
                            default =>  SubscriptionTransactionStatus::PENDING,
                        },
                        'metadata' => array_merge(
                            $subscription->metadata, 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ],
                            $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'metadata') ?? []
                        )
                    ],

                    default => [
                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                        'status' => match ($result->status) {
                            SubscriptionStatus::APPROVED,
                            SubscriptionStatus::PENDING,  
                            SubscriptionStatus::APPROVAL_PENDING => SubscriptionTransactionStatus::PENDING,
                            SubscriptionStatus::CANCELLED, 
                            SubscriptionStatus::CANCELED => SubscriptionTransactionStatus::CANCELED, 
                            SubscriptionStatus::FAILED => SubscriptionTransactionStatus::FAILED, 
                            SubscriptionStatus::PAST_DUE,
                            SubscriptionStatus::SUSPENDED, 
                            SubscriptionStatus::EXPIRED => SubscriptionTransactionStatus::EXPIRED,
                            SubscriptionStatus::ACTIVE => SubscriptionTransactionStatus::COMPLETED,
                            default =>  SubscriptionTransactionStatus::PENDING,
                        },
                        'metadata' => array_merge(
                            $subscription->metadata, 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                : ['date' => now()->format('Y-m-d H:i:s')]
                            ]
                        )
                    ]
                },
                'payment_provider_plan_id' => $result->providerPlanId,
                'payment_status' => $result->paymentStatus,
                'payment_provider_status' => $result->metadata['status'] ?? 'COMPLETED',
                
            ]);

            switch ($result->paymentStatus) {
                case PaymentStatus::PAID:

                    switch ($subscription_transaction->type)
                    {
                        case SubscriptionTransactionType::Initial:
                            $subscription->fill([
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                    
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                )
                            ]);
                            break;
                        case SubscriptionTransactionType::Retry:
                        case SubscriptionTransactionType::Renewal:
                            
                            $subscription->fill([
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $planPrice->interval->calculateNextInterval($start, $planPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::Upgrade:    
                            $newPlan = $subscription_context->plan;
                            $newPlanPrice = $subscription_context->price;

                            $subscription->fill([
                                'billing_plan_id' => $newPlan->id,
                                'billing_plan_price_id' => $newPlanPrice->id,
                                'billing_plan_name' => $plan->name,
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $newPlanPrice->interval->calculateNextInterval($start, $newPlanPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::Downgrade:
                            break;
                        case SubscriptionTransactionType::PriceIncrease:
                            $newPlanPrice = $subscription_context->price;

                            $subscription->fill([
                                'billing_plan_price_id' => $newPlanPrice->id,
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $newPlanPrice->interval->calculateNextInterval($start, $newPlanPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::PriceDecrease:
                            break;
                        case SubscriptionTransactionType::Static:
                        default:
                            break;
                    }

                    $subscription->fill([
                        'is_active' => true
                    ]);
                    
                    $order->update([
                        'status' => $order_status = OrderStatus::PROCESSED,
                        'delivery_status' => DeliveryStatus::IN_PROGRESS,
                        'fulfillment_status' => FulfillmentStatus::IN_PROGRESS,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ]);

                    $order_items->map(fn (BillingOrderItem $order_item) => $order_item->fill([
                        'status' => $order_status,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order_item->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ])->save());

                    event(new PaymentCaptured($order, $payment_transaction));
                    event(new OrderProcessed($order));
                    event(new SubscriptionActivated($subscription));
                    break;

                case PaymentStatus::COMPLETED:

                    switch ($subscription_transaction->type)
                    {
                        case SubscriptionTransactionType::Initial:
                            $subscription->fill([
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                    
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                )
                            ]);
                            break;
                        case SubscriptionTransactionType::Retry:
                        case SubscriptionTransactionType::Renewal:
                            
                            $subscription->fill([
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $planPrice->interval->calculateNextInterval($start, $planPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::Upgrade:    
                            $newPlan = $subscription_context->plan;
                            $newPlanPrice = $subscription_context->price;

                            $subscription->fill([
                                'billing_plan_id' => $newPlan->id,
                                'billing_plan_price_id' => $newPlanPrice->id,
                                'billing_plan_name' => $plan->name,
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $newPlanPrice->interval->calculateNextInterval($start, $newPlanPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::Downgrade:
                            break;
                        case SubscriptionTransactionType::PriceIncrease:
                            $newPlanPrice = $subscription_context->price;

                            $subscription->fill([
                                'billing_plan_price_id' => $newPlanPrice->id,
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $newPlanPrice->interval->calculateNextInterval($start, $newPlanPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::PriceDecrease:
                            break;
                        case SubscriptionTransactionType::Static:
                        default:
                            break;
                    }

                    $subscription->fill([
                        'is_active' => true
                    ]);

                    $order->update([
                        'status' => $order_status = OrderStatus::COMPLETED,
                        'delivery_status' => DeliveryStatus::DELIVERED,
                        'fulfillment_status' => FulfillmentStatus::FULFILLED,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ]);

                    $order_items->map(fn (BillingOrderItem $order_item) => $order_item->fill([
                        'status' => $order_status,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order_item->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ])->save());

                    event(new PaymentCompleted($order, $payment_transaction));
                    event(new SubscriptionActivated($subscription));
                    break;
                case PaymentStatus::FAILED:
                    $subscription->fill([
                        'payment_provider' => $paymentProvider,
                        'payment_provider_checkout_id' => $result->providerCheckoutId,

                        ...match(true) {
                            $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                            ],

                            $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                            ],

                            default => [
                                'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                'status' =>  $result->status,
                            ]
                        },
                        'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                        'payment_provider_plan_id' => $result->providerPlanId,
                        'ended_at' => null,
                        'canceled_at' => null,
                        'paused_at' => null,
                        'expired_at' => null,
                        'is_active' => false
                    ]);

                    $order->update([
                        'status' => $order_status = OrderStatus::FAILED,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ]);

                    $order_items->map(fn (BillingOrderItem $order_item) => $order_item->fill([
                        'status' => $order_status,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order_item->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ])->save());

                    event(new PaymentFailed($order, $payment_transaction));
                    
                case PaymentStatus::PENDING:
                case PaymentStatus::DEFAULT:
                default:
                   break;
            }

            if ($paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar') {
                Cache::forget(
                    class_basename($user).'_'.$user->getKey().'_polar_subscription_active'
                );
            }

            if ($paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle') {
                Cache::forget(
                    class_basename($user).'_'.$user->getKey().'_paddle_subscription_active'
                );
            }

            $subscription->save();

            $subscription->billing_subscription_events()->create( [
                'type' => EventEnum::TransactionCompleted,
                'triggered_by' => 'SYSTEM',
                'billing_subscription_transaction_id' => $subscription_transaction?->id,
            ]);

            return $result->setCheckoutDetails(
                CheckoutDetails::make(
                    $payment_transaction,
                    $order,
                    $order_items,
                    billingSubscription: $subscription,
                    checkoutUrl: $result->getCheckoutUrl()
                )->setSubscriptionContext(
                    new SubscriptionContext(
                        $subscription_context->type,
                        $subscription,
                        $subscription_transaction,
                        $subscription_context->plan ?? $plan,
                        $subscription_context->price ?? $planPrice,
                        $subscription_context->currentPlan,
                        $subscription_context->currentPlanPrice
                    )
                )
            );
        });
    }

    public function startSubscription(
        Billable $user,
        PaymentProvider|string $paymentProvider,
        string $providerSubscriptionId,
        BillingPlanPrice $planPrice,
        array $metadata = []
    ): SubscriptionResult {

        return DB::transaction(function () use ($user, $providerSubscriptionId, $planPrice, $paymentProvider, $metadata): SubscriptionResult {

            $paymentProviderValue = $this->resolveProviderValue($paymentProvider);

            $provider = $this->provider($paymentProviderValue);

            
            $payment_transaction = null;
            $subscription_provider_history = null;

            $creationMetadata = array_merge($metadata, [ 'billing_plan_info' => [
                'billing_plan' => ($plan = $planPrice->billing_plan()->with(['billing_product'])->first())->only(['id', 'billing_plan_id', 'billing_product_id', 'name']),
                'billing_plan_price' => $planPrice->only([
                    'id', 'billing_plan_id',
                    'interval',
                    'custom_interval_count',
                    'amount',
                    'currency',
                    'scale'
                ]),
                'date' => now()->format('Y-m-d H:i:s'),
            ]]);

            $finalPrice = $planPrice->amount; 

            $order = $user->billing_orders()->create([
                'billing_order_id' => $billing_order_id = Str::uuid(),
                'order_number' => $order_number = BillingOrder::generateOrderNumber(),
                'status' => $_orderStatus = OrderStatus::PENDING,
                'currency' => $currency = $planPrice->currency,
                'subtotal' => $planPrice->amount,
                'discount' => $discount?->value ?? 0,
                'tax' => 0,
                'shipping' => 0,
                'total' => $finalPrice,
                'payment_status' => $_paymentStatus = PaymentStatus::PENDING,
                'payment_provider' => $paymentProvider, 
                'metadata' => $metadata,
            ]);

            $order_item = $order->billing_order_items()->create([
                'billing_product_id' => $plan->billing_product->id,
                'billing_plan_id' => $plan->id,
                'billing_plan_price_id' => $planPrice->id, 
                'name' => $plan->name,
                'price' => $planPrice->amount,
                'thumbnail' => $plan->thumbnail,
                'url' => $plan->url,
                'quantity' => 1,
                'currency' => $currency,
                'subtotal' => $planPrice->amount, // before tax & shipping
                'discount' => $discount?->value ?? 0,
                'tax' => 0,
                'shipping' => 0,
                'total' => $finalPrice,
                'type' => 'subscription', //  'subscription' : 'one-time'
                'status' => $_orderStatus,
                'payment_status' => $_paymentStatus,
                'delivery_status' => $_deliveryStatus =  DeliveryStatus::AWAITING_PROCESSING,
                'fulfillment_status' => $_fulfillmentStatus = FulfillmentStatus::AWAITING_PROCESSING,
                'metadata' => $metadata,
            ]);

            $order_items = collect([$order_item]);

            $subscriptionContext = static::prepareSubscriptionContext(
                $user, $plan, $planPrice
            );

            $start = null;
            $end = null;

            $events = [];

            switch ($subscriptionContext->type) {
                case SubscriptionTransactionType::Initial:
                    $subscription = $user->billing_subscription()->create([
                        'billing_subscription_id' => Str::uuid(),
                        'payment_provider' => $paymentProvider,
                        'payment_provider_subscription_id' => match($paymentProvider) {
                            PaymentProvider::Polar => null,
                            default => $providerSubscriptionId,
                        },
                        'payment_provider_checkout_id' =>  match($paymentProvider) {
                            PaymentProvider::Polar => $providerSubscriptionId,
                            default => null,
                        },
                        'billing_plan_id' => $plan->id,
                        'billing_plan_price_id' => $planPrice->id,
                        'billing_plan_name' => $plan->name,
                        'interval' => $planPrice->interval,
                        'status' => SubscriptionStatus::PENDING,
                        'sarts_at' => $start = CarbonImmutable::now(),
                        'ends_at' => match ($planPrice->interval) {
                            SubscriptionInterval::MONTHLY => $end = $start->addMonth(), 
                            SubscriptionInterval::YEARLY => $end = $start->addYear()
                        },
                        'metadata' => $creationMetadata
                    ]);

                    $subscription_transaction = $subscription->billing_subscription_transactions()->create([
                        'billing_plan_id' => $plan->id,
                        'billing_plan_price_id' => $planPrice->id,
                        'billing_plan_name' => $plan->name,
                        'transaction_ref' => $order->order_number,
                        'type' => $type = SubscriptionTransactionType::Initial,
                        'payment_provider' => $paymentProvider,
                        'amount' => $finalPrice,
                        'currency' => $planPrice->currency,
                        'interval' => $planPrice->interval,
                        'scale' => $planPrice->scale,
                        'status' => SubscriptionTransactionStatus::PENDING,
                        'payment_status' => PaymentStatus::PENDING,
                        'metadata' => $creationMetadata,
                    ]);

                    $events[] = EventEnum::Initial;

                    break;
                case SubscriptionTransactionType::Retry:
                case SubscriptionTransactionType::Renewal:
                    break;
                case SubscriptionTransactionType::Upgrade:
                    $subscription = $subscriptionContext->existingSubscription;

                    $previousPlan = $subscriptionContext->currentPlan;
                    $previousPlanPrice = $subscriptionContext->currentPlanPrice;

                    $plan = $newPlan = $subscriptionContext->plan;
                    $planPrice = $newPlanPrice = $subscriptionContext->price;

                    $total_days = $subscription->ends_at->diffInDays($subscription->starts_at, true); 
                    $remaining_days = $subscription->ends_at->diffInDays(now(), true);

                    if ($newPlanPrice->interval !== $previousPlanPrice->interval) {
                        $substitutedPrice = $previousPlan->billing_plan_prices()->where('interval', $newPlanPrice->interval)->first();

                        $finalPrice = round(
                            (($newPlanPrice->amount - $substitutedPrice->amount) * ($remaining_days / $total_days)),
                            0
                        );
                    } else {

                        $finalPrice = round(
                            (($newPlanPrice->amount - $previousPlanPrice->amount) * ($remaining_days / $total_days)),
                            0
                        );
                    }

                    $subscription_transaction = $subscription->billing_subscription_transactions()->create([
                        'from_billing_plan_id' => $previousPlan->id,
                        'from_billing_plan_price_id' => $previousPlanPrice->id,
                        'billing_plan_id' => $newPlan->id,
                        'billing_plan_price_id' => $newPlanPrice->id,
                        'billing_plan_name' => $newPlan->name,
                        'transaction_ref' => $order->order_number,
                        'type' => $subscriptionContext->type,
                        'payment_provider' => $paymentProvider,
                        'amount' => $finalPrice,
                        'currency' => $newPlanPrice->currency,
                        'interval' => $newPlanPrice->interval,
                        'scale' => $newPlanPrice->scale,
                        'status' => SubscriptionTransactionStatus::PENDING,
                        'payment_status' => PaymentStatus::PENDING,
                        'metadata' => $creationMetadata,
                    ]);

                    $events[] = EventEnum::Upgrade;

                    break;
                case SubscriptionTransactionType::PriceIncrease:

                    $subscription = $subscriptionContext->existingSubscription;

                    $previousPlan = $subscriptionContext->currentPlan;
                    $previousPlanPrice = $subscriptionContext->currentPlanPrice;

                    $plan = $newPlan = $subscriptionContext->plan;
                    $planPrice = $newPlanPrice = $subscriptionContext->price;

                    $total_days = $subscription->ends_at->diffInDays($subscription->starts_at, true); 
                    $remaining_days = $subscription->ends_at->diffInDays(now(), true);

                    $finalPrice = round(
                        (($newPlanPrice->amount - $previousPlanPrice->amount) * ($remaining_days / $total_days)),
                        0
                    );

                    $subscription_transaction = $subscription->billing_subscription_transactions()->create([
                        'from_billing_plan_price_id' => $previousPlanPrice->id,
                        'billing_plan_id' => $newPlan->id,
                        'billing_plan_price_id' => $newPlanPrice->id,
                        'billing_plan_name' => $newPlan->name,
                        'transaction_ref' => $order->order_number,
                        'type' => $subscriptionContext->type,
                        'payment_provider' => $paymentProvider,
                        'amount' => $finalPrice,
                        'currency' => $newPlanPrice->currency,
                        'interval' => $newPlanPrice->interval,
                        'scale' => $newPlanPrice->scale,
                        'status' => SubscriptionTransactionStatus::PENDING,
                        'payment_status' => PaymentStatus::PENDING,
                        'metadata' => $creationMetadata,
                    ]);

                    $events[] = EventEnum::PriceChange;
                    break;
                case SubscriptionTransactionType::PriceDecrease:
                    break;
                default: 
                    throw new RuntimeException('SubscriptionsManager::startSubscription Unsupported Subscription Context Type' . $subscriptionContext->type->value);
            }

            // $subscription = $user->billing_subscription()->firstOrCreate([
            //     'billable_id' => $user->getKey(), 
            //     'billable_type' => get_class($user)
            // ], [
            //     'billing_subscription_id' => Str::uuid(),
            //     'payment_provider' => $paymentProvider,
            //     'payment_provider_subscription_id' => match($paymentProvider) {
            //         PaymentProvider::Polar => null,
            //         default => $providerSubscriptionId,
            //     },
            //     'payment_provider_checkout_id' =>  match($paymentProvider) {
            //         PaymentProvider::Polar => $providerSubscriptionId,
            //         default => null,
            //     },
            //     'billing_plan_id' => $plan->id,
            //     'billing_plan_price_id' => $planPrice->id,
            //     'billing_plan_name' => $plan->name,
            //     'interval' => $planPrice->interval,
            //     'status' => SubscriptionStatus::PENDING,
            //     'sarts_at' => $start = CarbonImmutable::now(),
            //     'ends_at' => match ($planPrice->interval) {
            //         SubscriptionInterval::MONTHLY => $end = $start->addMonth(), 
            //         SubscriptionInterval::YEARLY => $end = $start->addYear()
            //     },
            //     'metadata' => $creationMetadata
            // ]);

            $order->billing_subscription()->associate($subscription);
            $order->save();

            $syncData = collect([$plan->billing_product])
                ->mapWithKeys(fn (BillingProduct $item) => [
                    $item->getId() => ['quantity' => 1]
                ])->toArray();

            $order->billing_products()->sync($syncData, false);

            $subscriptionContext = static::prepareSubscriptionContext(
                $user, $plan, $planPrice
            );

            $payment_transaction = $user->billing_payment_transactions()->create([
                'action_type' => $subscription->wasRecentlyCreated ? ActionType::CREATE : ActionType::MODIFY,
                'billing_payment_transaction_id' => $billing_payment_transaction_id = Str::uuid(),
                'type' => PaymentTransactionType::SUBSCRIPTION,
                'status' => PaymentTransactionStatus::PENDING,
                'total_amount' => $finalPrice,
                'currency' => $planPrice->currency,
                'payment_provider' => $paymentProvider,
            ]);

            $payment_transaction->billing_subscription()->associate($subscription);
            $payment_transaction->billing_order()->associate($order);
            $payment_transaction->save();

            $events[] = EventEnum::TransactionProcessing;

            $subscription_events = $subscription->billing_subscription_events()->createMany(array_map(fn (EventEnum $event) => [
                'type' => $event,
                'triggered_by' => 'SYSTEM',
                'billing_subscription_transaction_id' => $subscription_transaction?->id,
            ], $events));

            $providerData = array_merge($metadata, [
                'user' => $user,
                'billing_subscription_id' => $subscription->billing_subscription_id,
                'billing_subscription_transaction_id' => $subscription_transaction->billing_subscription_transaction_id,
                'billing_payment_transaction_id' => $payment_transaction->billing_payment_transaction_id,
                'billing_order_id' => $order->billing_order_id,
                'order_number' => $order->order_number,
                'product_type' => ApiProductTypeKey::SUBSCRIPTION->value,
                'amount' => $finalPrice,
                'currency' => $planPrice->currency,
                'metadata' => $metadata,
                'start' => $start ??= now(),
                'end' => $end ??= $planPrice->interval->calculateNextInterval($start, $planPrice->billing_interval_count)
            ]);
            
            $result = $provider->startSubscription(
                CompleteOrderRequest::make(
                    $user,
                    $billing_order_id,
                    $billing_payment_transaction_id,
                    $order_number,
                    billingSubscriptionId: $subscription->billing_subscription_id,
                    providerSubscriptionId: $providerSubscriptionId,
                    billingSubscriptionTransactionId: $subscription_transaction->billing_subscription_transaction_id,
                    metadata: [
                        ...$metadata, 
                        'amount' => $planPrice->amount,
                        'currency' => $planPrice->currency,
                        'start' => $providerData['start'],
                        'end' => $providerData['end']

                    ],
                    productType: ApiProductTypeKey::SUBSCRIPTION
                )
            );

            if (!$result->success && $result->throw) throw new SubscriptionActivationException($result);

            $payment_transaction_status = match ($result->status) {
                SubscriptionStatus::APPROVED,
                SubscriptionStatus::PENDING,  
                SubscriptionStatus::APPROVAL_PENDING => PaymentTransactionStatus::PENDING,
                SubscriptionStatus::FAILED, 
                SubscriptionStatus::SUSPENDED, 
                SubscriptionStatus::CANCELLED, 
                SubscriptionStatus::CANCELED, 
                SubscriptionStatus::EXPIRED => PaymentTransactionStatus::EXPIRED,
                SubscriptionStatus::ACTIVE => PaymentTransactionStatus::COMPLETED,
                default =>  PaymentTransactionStatus::PENDING,
            };

            $order->update([
                'status' => $orderStatus = match ($result->status) {
                    SubscriptionStatus::APPROVED,
                    SubscriptionStatus::PENDING,  
                    SubscriptionStatus::APPROVAL_PENDING => OrderStatus::CANCELED,
                    SubscriptionStatus::FAILED => OrderStatus::FAILED, 
                    SubscriptionStatus::SUSPENDED, 
                    SubscriptionStatus::CANCELLED, 
                    SubscriptionStatus::CANCELED, 
                    SubscriptionStatus::EXPIRED => OrderStatus::CANCELED,
                    SubscriptionStatus::ACTIVE => OrderStatus::COMPLETED,
                    default => OrderStatus::PENDING
                },
                'delivery_status' => $deliveryStatus = match ($result->status) {
                    SubscriptionStatus::APPROVED,
                    SubscriptionStatus::PENDING,  
                    SubscriptionStatus::APPROVAL_PENDING => DeliveryStatus::AWAITING_PROCESSING,
                    SubscriptionStatus::FAILED => DeliveryStatus::RETURNED, 
                    SubscriptionStatus::SUSPENDED, 
                    SubscriptionStatus::CANCELLED, 
                    SubscriptionStatus::CANCELED, 
                    SubscriptionStatus::EXPIRED => DeliveryStatus::CANCELED,
                    SubscriptionStatus::ACTIVE => DeliveryStatus::DELIVERED,
                    default => DeliveryStatus::AWAITING_PROCESSING
                },
                'fulfillment_status' =>  $fulfillmentStatus = match ($result->status) {
                    SubscriptionStatus::APPROVED,
                    SubscriptionStatus::PENDING,  
                    SubscriptionStatus::APPROVAL_PENDING => FulfillmentStatus::AWAITING_PROCESSING,
                    SubscriptionStatus::FAILED, 
                    SubscriptionStatus::SUSPENDED, 
                    SubscriptionStatus::CANCELLED, 
                    SubscriptionStatus::CANCELED, 
                    SubscriptionStatus::EXPIRED => FulfillmentStatus::UNFULFILLED,
                    SubscriptionStatus::ACTIVE => FulfillmentStatus::FULFILLED,
                    default => FulfillmentStatus::AWAITING_PROCESSING
                },
            ]);

            $order_item->update([
                'status' => $orderStatus,
                'payment_status' => $result->paymentStatus,
                'delivery_status' => $deliveryStatus,
                'fulfillment_status' => $fulfillmentStatus,
            ]);

            $payment_transaction->update([
                'payment_provider_checkout_id' => $result->providerCheckoutId,
                'payment_provider_subscription_id' => $result->providerSubscriptionId,
                'payment_provider_transaction_id' => $result->providerTransactionId,
                'metadata' => array_merge(
                    $subscription->metadata ?? [],
                    $subscription->wasRecentlyCreated ? [
                        'initiate_subscription' =>  $result->metadata ?
                            [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                            : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')]
                        
                    ] : [
                        'modify_subscription' =>  $result->metadata ?
                            [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                            : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')]
                        ,
                        'completing_subscription' => [
                            ...$metadata, 'date' => now()->format('Y-m-d H:i:s')
                        ]
                    ],  
                    ['complete_subscription' => $result->metadata 
                        ? [...$result->metadata,  'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                        : [ 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')]
                    ]
                )
            ]);

            $subscription_transaction->update([
                'payment_provider' => $paymentProvider,
                'payment_provider_checkout_id' => $result->providerCheckoutId,
                ...match(true) {
                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                        'status' => match (SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status) {
                            SubscriptionStatus::APPROVED,
                            SubscriptionStatus::PENDING,  
                            SubscriptionStatus::APPROVAL_PENDING => SubscriptionTransactionStatus::PENDING,
                            SubscriptionStatus::CANCELLED, 
                            SubscriptionStatus::CANCELED => SubscriptionTransactionStatus::CANCELED, 
                            SubscriptionStatus::FAILED => SubscriptionTransactionStatus::FAILED, 
                            SubscriptionStatus::PAST_DUE,
                            SubscriptionStatus::SUSPENDED, 
                            SubscriptionStatus::EXPIRED => SubscriptionTransactionStatus::EXPIRED,
                            SubscriptionStatus::ACTIVE => SubscriptionTransactionStatus::COMPLETED,
                            default =>  SubscriptionTransactionStatus::PENDING,
                        },
                        'metadata' => array_merge(
                            $subscription->metadata, 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ],
                            $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'metadata') ?? []
                        )
                    ],

                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                        'status' => match (SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status) {
                            SubscriptionStatus::APPROVED,
                            SubscriptionStatus::PENDING,  
                            SubscriptionStatus::APPROVAL_PENDING => SubscriptionTransactionStatus::PENDING,
                            SubscriptionStatus::CANCELLED, 
                            SubscriptionStatus::CANCELED => SubscriptionTransactionStatus::CANCELED, 
                            SubscriptionStatus::FAILED => SubscriptionTransactionStatus::FAILED, 
                            SubscriptionStatus::PAST_DUE,
                            SubscriptionStatus::SUSPENDED, 
                            SubscriptionStatus::EXPIRED => SubscriptionTransactionStatus::EXPIRED,
                            SubscriptionStatus::ACTIVE => SubscriptionTransactionStatus::COMPLETED,
                            default =>  SubscriptionTransactionStatus::PENDING,
                        },
                        'metadata' => array_merge(
                            $subscription->metadata, 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ],
                            $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'metadata') ?? []
                        )
                    ],

                    default => [
                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                        'status' => match ($result->status) {
                            SubscriptionStatus::APPROVED,
                            SubscriptionStatus::PENDING,  
                            SubscriptionStatus::APPROVAL_PENDING => SubscriptionTransactionStatus::PENDING,
                            SubscriptionStatus::CANCELLED, 
                            SubscriptionStatus::CANCELED => SubscriptionTransactionStatus::CANCELED, 
                            SubscriptionStatus::FAILED => SubscriptionTransactionStatus::FAILED, 
                            SubscriptionStatus::PAST_DUE,
                            SubscriptionStatus::SUSPENDED, 
                            SubscriptionStatus::EXPIRED => SubscriptionTransactionStatus::EXPIRED,
                            SubscriptionStatus::ACTIVE => SubscriptionTransactionStatus::COMPLETED,
                            default =>  SubscriptionTransactionStatus::PENDING,
                        },
                        'metadata' => array_merge(
                            $subscription->metadata, 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                : ['date' => now()->format('Y-m-d H:i:s')]
                            ]
                        )
                    ]
                },
                'payment_provider_plan_id' => $result->providerPlanId,
                'payment_status' => $result->paymentStatus,
                'payment_provider_status' => $result->metadata['status'] ?? 'COMPLETED',
                
            ]);

            switch ($result->paymentStatus) {
                case PaymentStatus::PAID:

                    switch ($subscription_transaction->type)
                    {
                        case SubscriptionTransactionType::Initial:
                            $subscription->fill([
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                    
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                )
                            ]);
                            break;
                        case SubscriptionTransactionType::Retry:
                        case SubscriptionTransactionType::Renewal:
                            
                            $subscription->fill([
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $planPrice->interval->calculateNextInterval($start, $planPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::Upgrade:    
                            $newPlan = $subscriptionContext->plan;
                            $newPlanPrice = $subscriptionContext->price;

                            $subscription->fill([
                                'billing_plan_id' => $newPlan->id,
                                'billing_plan_price_id' => $newPlanPrice->id,
                                'billing_plan_name' => $plan->name,
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $newPlanPrice->interval->calculateNextInterval($start, $newPlanPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::Downgrade:
                            break;
                        case SubscriptionTransactionType::PriceIncrease:
                            $newPlanPrice = $subscriptionContext->price;

                            $subscription->fill([
                                'billing_plan_price_id' => $newPlanPrice->id,
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $newPlanPrice->interval->calculateNextInterval($start, $newPlanPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::PriceDecrease:
                            break;
                        case SubscriptionTransactionType::Static:
                        default:
                            break;
                    }

                    $subscription->fill([
                        'is_active' => true
                    ]);
                    
                    $order->update([
                        'payment_provider_checkout_id' => $result->providerCheckoutId,
                        'payment_provider_order_id' => $result->providerOrderId,
                        'payment_provider_transaction_id' => $result->providerTransactionId,
                        'status' => $order_status = OrderStatus::PROCESSED,
                        'delivery_status' => DeliveryStatus::IN_PROGRESS,
                        'fulfillment_status' => FulfillmentStatus::IN_PROGRESS,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ]);

                    $order_items->map(fn (BillingOrderItem $order_item) => $order_item->fill([
                        'status' => $order_status,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order_item->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ])->save());

                    event(new PaymentCaptured($order, $payment_transaction));
                    event(new OrderProcessed($order));
                    event(new SubscriptionActivated($subscription));
                    break;

                case PaymentStatus::COMPLETED:

                    switch ($subscription_transaction->type)
                    {
                        case SubscriptionTransactionType::Initial:
                            $subscription->fill([
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                    
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                )
                            ]);
                            break;
                        case SubscriptionTransactionType::Retry:
                        case SubscriptionTransactionType::Renewal:
                            
                            $subscription->fill([
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $planPrice->interval->calculateNextInterval($start, $planPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::Upgrade:    
                            $newPlan = $subscriptionContext->plan;
                            $newPlanPrice = $subscriptionContext->price;

                            $subscription->fill([
                                'billing_plan_id' => $newPlan->id,
                                'billing_plan_price_id' => $newPlanPrice->id,
                                'billing_plan_name' => $plan->name,
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $newPlanPrice->interval->calculateNextInterval($start, $newPlanPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::Downgrade:
                            break;
                        case SubscriptionTransactionType::PriceIncrease:
                            $newPlanPrice = $subscriptionContext->price;

                            $subscription->fill([
                                'billing_plan_price_id' => $newPlanPrice->id,
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $newPlanPrice->interval->calculateNextInterval($start, $newPlanPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::PriceDecrease:
                            break;
                        case SubscriptionTransactionType::Static:
                        default:
                            break;
                    }

                    $subscription->fill([
                        'is_active' => true
                    ]);

                    $order->update([
                        'payment_provider_checkout_id' => $result->providerCheckoutId,
                        'payment_provider_order_id' => $result->providerOrderId,
                        'payment_provider_transaction_id' => $result->providerTransactionId,
                        'status' => $order_status = OrderStatus::COMPLETED,
                        'delivery_status' => DeliveryStatus::DELIVERED,
                        'fulfillment_status' => FulfillmentStatus::FULFILLED,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ]);

                    $order_items->map(fn (BillingOrderItem $order_item) => $order_item->fill([
                        'status' => $order_status,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order_item->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ])->save());

                    event(new PaymentCompleted($order, $payment_transaction));
                    event(new SubscriptionActivated($subscription));
                    break;
                case PaymentStatus::FAILED:
                    $subscription->fill([
                        'payment_provider' => $paymentProvider,
                        'payment_provider_checkout_id' => $result->providerCheckoutId,

                        ...match(true) {
                            $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                            ],

                            $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                            ],

                            default => [
                                'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                'status' =>  $result->status,
                            ]
                        },
                        'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                        'payment_provider_plan_id' => $result->providerPlanId,
                        'ended_at' => null,
                        'canceled_at' => null,
                        'paused_at' => null,
                        'expired_at' => null,
                        'is_active' => false
                    ]);

                    $order->update([
                        'status' => $order_status = OrderStatus::FAILED,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ]);

                    $order_items->map(fn (BillingOrderItem $order_item) => $order_item->fill([
                        'status' => $order_status,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order_item->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ])->save());

                    event(new PaymentFailed($order, $payment_transaction));
                    
                case PaymentStatus::PENDING:
                case PaymentStatus::DEFAULT:
                default:
                   break;
            }

            if ($paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar') {
                Cache::forget(
                    class_basename($user).'_'.$user->getKey().'_polar_subscription_active'
                );
            }

            if ($paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle') {
                Cache::forget(
                    class_basename($user).'_'.$user->getKey().'_paddle_subscription_active'
                );
            }

            $subscription->save();

            $subscription->billing_subscription_events()->create( [
                'type' => EventEnum::TransactionCompleted,
                'triggered_by' => 'SYSTEM',
                'billing_subscription_transaction_id' => $subscription_transaction?->id,
            ]);

            return $result->setCheckoutDetails(
                CheckoutDetails::make(
                    $payment_transaction,
                    $order,
                    collect([$order_item]),
                    billingSubscription: $subscription,
                    checkoutUrl: $result->getCheckoutUrl()
                )->setSubscriptionContext(
                    new SubscriptionContext(
                        $subscriptionContext->type,
                        $subscription,
                        $subscription_transaction,
                        $subscriptionContext->plan ?? $plan,
                        $subscriptionContext->price ?? $planPrice,
                        $subscriptionContext->currentPlan,
                        $subscriptionContext->currentPlanPrice
                    )
                )
            );
        });
    }

    public function updateSubscription(
        Billable $user,
        PaymentProvider|string $paymentProvider,
        BillingPlanPrice $newPlanPrice,
        array $updates = [],
        array $metadata = []
    ): SubscriptionResult 
    {
        $paymentProviderValue = $this->resolveProviderValue($paymentProvider);

        $provider = $this->provider($paymentProviderValue);

        $subscription = $user->billing_subscription()->where('status', SubscriptionStatus::ACTIVE)->sole();

        $previousProvider = $subscription->provider;

        if ($this->resolveProviderValue($previousProvider) !== $paymentProviderValue) {
            return new SubscriptionResult(
                false,
                $subscription->billing_subscription_id,
                PaymentStatus::FAILED,
                SubscriptionStatus::PAYMENT_PROVIDER_MISMATCH,
                Result::fail(new ErrorInfo(
                    'Payment Provider Mismatch',
                    500,
                    'The payment provider for the subscription does not match the current provider.',
                    [
                        'expected' => $previousProvider->value,
                        'actual' => $paymentProvider->value
                    ]
                )),
                null,
                $subscription->payment_provider_subscription_id,
                null,
                null,
                null,
                $subscription->payment_provider_plan_id,
                'Subscription Modification Failed',
                []
            );
        } 

        $providerData = array_merge($updates, [
            'user' => $user, // $user->getEmail()
            'billing_subscription_id' => $subscription->billing_subscription_id,
            'amount' => $newPlanPrice->amount,
            'currency' => $newPlanPrice->currency,
            'metadata' => $metadata
        ]);

        return $provider->updateSubscription(
            $subscription->billing_subscription_id, 
            $subscription->payment_provider_subscription_id, 
            $newPlanPrice, 
            $providerData
        );
    }

    public function cancelSubscription(BillingSubscription|string $billingSubscription): bool
    {
        $subscription = is_string($billingSubscription) ? BillingSubscription::where([
            'billing_subscription_id' => $billingSubscription
        ])->first() : $billingSubscription;
        
        if (!$subscription) {
            return false;
        }

        $success = $this->provider(
            $paymentProviderValue = $this->resolveProviderValue($subscription->payment_provider)
        )->cancelSubscription($subscription->payment_provider_subscription_id);

        if ($success) {
            $subscription->update([
                'status' => SubscriptionStatus::CANCELLATION_PENDING,
                'canceled_at' => now(),
                'resumed_at' => null,
                'paused_at' => null,
                'expired_at' => null,
            ]);
        }

        return $success;
    }

    public function pauseSubscription(BillingSubscription|string $billingSubscription): bool
    {
        $subscription = is_string($billingSubscription) ? BillingSubscription::where([
            'billing_subscription_id' => $billingSubscription
        ])->first() : $billingSubscription;
        
        if (!$subscription) {
            return false;
        }

        $success = $this->provider(
            $paymentProviderValue = $this->resolveProviderValue($subscription->payment_provider)
        )->pauseSubscription($subscription->payment_provider_subscription_id);

        if ($success) {
            $subscription->update([
                'status' => SubscriptionStatus::PAUSED,
                'paused_at' => now(),
                'resumed_at' => null,
                'canceled_at' => null,
                'expired_at' => null,
            ]);
        }

        return $success;
    }


    private function getCachedProviderSubscriptionCreationData(
        Billable $user,
        string $provider,
        ?string $array_key = null
    )
    {
        $key = class_basename($user).'_'.$user->getKey().'_' . $provider . '_subscription_active';

        return data_get(Cache::get($key, []), $array_key);
    }

    public function setupSubscriptionPaymentToken(
        Billable $user, 
        PaymentProvider|string $paymentProvider,
        BillingPlanPrice $planPrice, 
        array $metadata = []
    ): array
    {
        $paymentProviderValue = $this->resolveProviderValue($paymentProvider);

        $provider = $this->provider($paymentProviderValue);
            
        $tokenPaymentProvider = $provider->getTokenPaymentProvider();

        return $tokenPaymentProvider->setupSubscriptionPaymentToken($planPrice, [...$metadata, 'user' => $user]);
    }
    

    /**
     * @see https://developer.paypal.com/docs/checkout/standard/customize/save-payment-methods-for-recurring-payments/
     * 
     * @brief Remember to setup a scheduler that uses the token to bill the user when subscription expires if subscription
     * used the 'paypal' payment provider with the 'vault_token'  sub_payment_provider
     */
    public function startSubscriptionWithToken(
        Billable $user,
        PaymentProvider|string $paymentProvider,
        BillingPlanPrice $planPrice,
        BillablePaymentMethod | string $token, 
        array $metadata = [],
    ): SubscriptionResult
    {
        return DB::transaction(function () use ($user, $paymentProvider, $planPrice, $token, $metadata): SubscriptionResult {

            $paymentProviderValue = $this->resolveProviderValue($paymentProvider);

            $provider = $this->provider($paymentProviderValue);
            
            $tokenPaymentProvider = $provider->getTokenPaymentProvider();

            $payment_transaction = null;
            $subscription_provider_history = null;
            $finalPrice = $planPrice->amount; 

            $creationMetadata = array_merge($metadata, [ 'billing_plan_info' => [
                'billing_plan' => ($plan = $planPrice->billing_plan()->with(['billing_product'])->first())->only(['id', 'billing_plan_id', 'billing_product_id', 'name']),
                'billing_plan_price' => $planPrice->only([
                    'id', 'billing_plan_id',
                    'interval',
                    'custom_interval_count',
                    'amount',
                    'currency',
                    'scale'
                ]),
                'date' => now()->format('Y-m-d H:i:s')
            ]]);


            $subscription = $user->billing_subscription()->updateOrCreate([
                'billable_id' => $user->getKey(), 
                'billable_type' => get_class($user)
            ], [
                'billing_subscription_id' => Str::uuid(),
                'payment_provider' => $paymentProvider,
                'sub_payment_provider' => 'vault_token',
                'payment_provider_subscription_id' => null,
                'payment_provider_checkout_id' => null,
                'billing_plan_id' => $plan->id,
                'billing_plan_price_id' => $planPrice->id,
                'billing_plan_name' => $plan->name,
                'amount' => $planPrice->amount,
                'currency' => $planPrice->currency,
                'interval' => $planPrice->interval,
                'scale' => $planPrice->scale,
                'status' => SubscriptionStatus::PENDING,
                'sarts_at' => $start = CarbonImmutable::now(),
                'ends_at' => match ($planPrice->interval) {
                    SubscriptionInterval::MONTHLY => $end = $start->addMonth(), 
                    SubscriptionInterval::YEARLY => $end = $start->addYear()
                },
                'metadata' => $creationMetadata
            ]);

            $order = $user->billing_orders()->create([
                'billing_order_id' => Str::uuid(),
                'order_number' => BillingOrder::generateOrderNumber(),
                'status' => $orderStatus = OrderStatus::PENDING,
                'currency' => $currency = $planPrice->currency,
                'subtotal' => $planPrice->amount,
                'discount' => $discount?->value ?? 0,
                'tax' => 0,
                'shipping' => 0,
                'total' => $finalPrice,
                'payment_status' => PaymentStatus::PENDING,
                'payment_provider' => $paymentProvider, 
                'sub_payment_provider' => 'vault_token',
                'metadata' => $metadata,
            ]);

            $subscriptionContext = static::prepareSubscriptionContext(
                $user, $plan, $planPrice
            );

            $start = null;
            $end = null;

            $events = [];

            switch ($subscriptionContext->type) {
                case SubscriptionTransactionType::Initial:
                    $subscription = $user->billing_subscription()->create([
                        'billing_subscription_id' => Str::uuid(),
                        'payment_provider' => $paymentProvider,
                        'billing_plan_id' => $plan->id,
                        'billing_plan_price_id' => $planPrice->id,
                        'billing_plan_name' => $plan->name,
                        'interval' => $planPrice->interval,
                        'status' => SubscriptionStatus::PENDING,
                        'sarts_at' => $start = CarbonImmutable::now(),
                        'ends_at' => match ($planPrice->interval) {
                            SubscriptionInterval::MONTHLY => $end = $start->addMonth(), 
                            SubscriptionInterval::YEARLY => $end = $start->addYear()
                        },
                        'metadata' => $creationMetadata
                    ]);

                    $subscription_transaction = $subscription->billing_subscription_transactions()->create([
                        'billing_plan_id' => $plan->id,
                        'billing_plan_price_id' => $planPrice->id,
                        'billing_plan_name' => $plan->name,
                        'transaction_ref' => $order->order_number,
                        'type' => $type = SubscriptionTransactionType::Initial,
                        'payment_provider' => $paymentProvider,
                        'amount' => $finalPrice,
                        'currency' => $planPrice->currency,
                        'interval' => $planPrice->interval,
                        'scale' => $planPrice->scale,
                        'status' => SubscriptionTransactionStatus::PENDING,
                        'payment_status' => PaymentStatus::PENDING,
                        'metadata' => $creationMetadata,
                    ]);

                    $events[] = EventEnum::Initial;
                    break;
                case SubscriptionTransactionType::Retry:
                case SubscriptionTransactionType::Renewal:
                    break;
                case SubscriptionTransactionType::Upgrade:
                    $subscription = $subscriptionContext->existingSubscription;

                    $previousPlan = $subscriptionContext->currentPlan;
                    $previousPlanPrice = $subscriptionContext->currentPlanPrice;

                    $plan = $newPlan = $subscriptionContext->plan;
                    $planPrice = $newPlanPrice = $subscriptionContext->price;

                    $total_days = $subscription->ends_at->diffInDays($subscription->starts_at, true); 
                    $remaining_days = $subscription->ends_at->diffInDays(now(), true);

                    if ($newPlanPrice->interval !== $previousPlanPrice->interval) {
                        $substitutedPrice = $previousPlan->billing_plan_prices()->where('interval', $newPlanPrice->interval)->first();

                        $finalPrice = round(
                            (($newPlanPrice->amount - $substitutedPrice->amount) * ($remaining_days / $total_days)),
                            0
                        );
                    } else {

                        $finalPrice = round(
                            (($newPlanPrice->amount - $previousPlanPrice->amount) * ($remaining_days / $total_days)),
                            0
                        );
                    }

                    $subscription_transaction = $subscription->billing_subscription_transactions()->create([
                        'from_billing_plan_id' => $previousPlan->id,
                        'from_billing_plan_price_id' => $previousPlanPrice->id,
                        'billing_plan_id' => $newPlan->id,
                        'billing_plan_price_id' => $newPlanPrice->id,
                        'billing_plan_name' => $newPlan->name,
                        'transaction_ref' => $order->order_number,
                        'type' => $subscriptionContext->type,
                        'payment_provider' => $paymentProvider,
                        'amount' => $finalPrice,
                        'currency' => $newPlanPrice->currency,
                        'interval' => $newPlanPrice->interval,
                        'scale' => $newPlanPrice->scale,
                        'status' => SubscriptionTransactionStatus::PENDING,
                        'payment_status' => PaymentStatus::PENDING,
                        'metadata' => $creationMetadata,
                    ]);

                    $events[] = EventEnum::Upgrade;
                    break;
                case SubscriptionTransactionType::PriceIncrease:

                    $subscription = $subscriptionContext->existingSubscription;

                    $previousPlan = $subscriptionContext->currentPlan;
                    $previousPlanPrice = $subscriptionContext->currentPlanPrice;

                    $plan = $newPlan = $subscriptionContext->plan;
                    $planPrice = $newPlanPrice = $subscriptionContext->price;

                    $total_days = $subscription->ends_at->diffInDays($subscription->starts_at, true); 
                    $remaining_days = $subscription->ends_at->diffInDays(now(), true);

                    $finalPrice = round(
                        (($newPlanPrice->amount - $previousPlanPrice->amount) * ($remaining_days / $total_days)),
                        0
                    );

                    $subscription_transaction = $subscription->billing_subscription_transactions()->create([
                        'from_billing_plan_price_id' => $previousPlanPrice->id,
                        'billing_plan_id' => $newPlan->id,
                        'billing_plan_price_id' => $newPlanPrice->id,
                        'billing_plan_name' => $newPlan->name,
                        'transaction_ref' => $order->order_number,
                        'type' => $subscriptionContext->type,
                        'payment_provider' => $paymentProvider,
                        'amount' => $finalPrice,
                        'currency' => $newPlanPrice->currency,
                        'interval' => $newPlanPrice->interval,
                        'scale' => $newPlanPrice->scale,
                        'status' => SubscriptionTransactionStatus::PENDING,
                        'payment_status' => PaymentStatus::PENDING,
                        'metadata' => $creationMetadata,
                    ]);

                    $events[] = EventEnum::PriceChange;
                    break;
                case SubscriptionTransactionType::PriceDecrease:
                    break;
                default: 
                    throw new RuntimeException('SubscriptionsManager::startSubscription Unsupported Subscription Context Type' . $subscriptionContext->type->value);
            }

            $payment_transaction = $user->billing_payment_transactions()->create([
                'action_type' => $subscription->wasRecentlyCreated ? ActionType::CREATE : ActionType::MODIFY,
                'billing_payment_transaction_id' => Str::uuid(),
                'type' => PaymentTransactionType::SUBSCRIPTION,
                'status' => PaymentTransactionStatus::PENDING,
                'total_amount' => $planPrice->amount,
                'currency' => $planPrice->currency,
                'payment_provider' => $paymentProvider,
                'sub_payment_provider' => 'vault_token',
                'metadata' => $subscription->metadata ?? [],
            ]);

            $payment_transaction->billing_subscription()->associate($subscription);
            $payment_transaction->billing_order()->associate($order);
            $payment_transaction->save();

            $events[] = EventEnum::TransactionProcessing;

            $subscription_events = $subscription->billing_subscription_events()->createMany(array_map(fn (EventEnum $event) => [
                'type' => $event,
                'triggered_by' => 'SYSTEM',
                'billing_subscription_transaction_id' => $subscription_transaction?->id,
            ], $events));

            $providerData = array_merge($metadata, [
                'user' => $user,
                'billing_subscription_id' => $subscription->billing_subscription_id,
                'billing_subscription_transaction_id' => $subscription_transaction->billing_subscription_transaction_id,
                'billing_payment_transaction_id' => $payment_transaction->billing_payment_transaction_id,
                'billing_order_id' => $order->billing_order_id,
                'order_number' => $order->order_number,
                'product_type' => ApiProductTypeKey::SUBSCRIPTION->value,
                'amount' => $finalPrice,
                'currency' => $planPrice->currency,
                'metadata' => $metadata,
                'start' => $start ??= now(),
                'end' => $end ??= $planPrice->interval->calculateNextInterval($start, $planPrice->billing_interval_count)
            ]);

            $result = is_string($token) ? $tokenPaymentProvider->startSubscriptionWithToken(
                $plan, $planPrice, $token, $providerData
            ) : $tokenPaymentProvider->startSubscriptionWithSavedToken($plan, $planPrice, $token, $providerData);

            if (!$result->success && $result->throw) throw new SubscriptionActivationException($result);

            $payment_transaction_status = match ($result->status) {
                SubscriptionStatus::APPROVED,
                SubscriptionStatus::PENDING,  
                SubscriptionStatus::APPROVAL_PENDING => PaymentTransactionStatus::PENDING,
                SubscriptionStatus::FAILED, 
                SubscriptionStatus::SUSPENDED, 
                SubscriptionStatus::CANCELLED, 
                SubscriptionStatus::CANCELED, 
                SubscriptionStatus::EXPIRED => PaymentTransactionStatus::EXPIRED,
                SubscriptionStatus::ACTIVE => PaymentTransactionStatus::COMPLETED,
                default =>  PaymentTransactionStatus::PENDING,
            };


            $order->update([
                'status' => $orderStatus = match ($result->status) {
                    SubscriptionStatus::APPROVED,
                    SubscriptionStatus::PENDING,  
                    SubscriptionStatus::APPROVAL_PENDING => OrderStatus::CANCELED,
                    SubscriptionStatus::FAILED => OrderStatus::FAILED, 
                    SubscriptionStatus::SUSPENDED, 
                    SubscriptionStatus::CANCELLED, 
                    SubscriptionStatus::CANCELED, 
                    SubscriptionStatus::EXPIRED => OrderStatus::CANCELED,
                    SubscriptionStatus::ACTIVE => OrderStatus::COMPLETED,
                    default => OrderStatus::PENDING
                },
                'payment_status' => $result->paymentStatus,
                'delivery_status' => $deliveryStatus = match ($result->status) {
                    SubscriptionStatus::APPROVED,
                    SubscriptionStatus::PENDING,  
                    SubscriptionStatus::APPROVAL_PENDING => DeliveryStatus::AWAITING_PROCESSING,
                    SubscriptionStatus::FAILED => DeliveryStatus::FAILED, 
                    SubscriptionStatus::SUSPENDED, 
                    SubscriptionStatus::CANCELLED, 
                    SubscriptionStatus::CANCELED, 
                    SubscriptionStatus::EXPIRED => DeliveryStatus::CANCELED,
                    SubscriptionStatus::ACTIVE => DeliveryStatus::DELIVERED,
                    default => DeliveryStatus::AWAITING_PROCESSING
                },
                'fulfillment_status' =>  $fulfillmentStatus = match ($result->status) {
                    SubscriptionStatus::APPROVED,
                    SubscriptionStatus::PENDING,  
                    SubscriptionStatus::APPROVAL_PENDING => FulfillmentStatus::AWAITING_PROCESSING,
                    SubscriptionStatus::FAILED, 
                    SubscriptionStatus::SUSPENDED, 
                    SubscriptionStatus::CANCELLED, 
                    SubscriptionStatus::CANCELED, 
                    SubscriptionStatus::EXPIRED => FulfillmentStatus::UNFULFILLED,
                    SubscriptionStatus::ACTIVE => FulfillmentStatus::FULFILLED,
                    default => FulfillmentStatus::AWAITING_PROCESSING
                },
                'metadata' => array_merge($metadata, $creationMetadata, ['token_result' => $result->metadata]),
                'payment_provider_checkout_id' => $result->providerCheckoutId,
                'payment_provider_order_id' => $result->providerOrderId,
                'payment_provider_transaction_id' => $result->providerTransactionId,
            ]);


            $order_item = $order->billing_order_items()->create([
                'billing_product_id' => $plan->billing_product->id,
                'billing_plan_id' => $plan->id,
                'billing_plan_price_id' => $planPrice->id, 
                'name' => $plan->name,
                'price' => $planPrice->amount,
                'thumbnail' => $plan->thumbnail,
                'url' => $plan->url,
                'quantity' => 1,
                'currency' => $currency,
                'subtotal' => $planPrice->amount, // before tax & shipping
                'discount' => $discount?->value ?? 0,
                'tax' => 0,
                'shipping' => 0,
                'total' => $finalPrice,
                'type' => 'subscription', //  'subscription' : 'one-time'
                'status' => $orderStatus,
                'payment_status' => $result->paymentStatus,
                'delivery_status' => $deliveryStatus,
                'fulfillment_status' => $fulfillmentStatus,
                'metadata' => $metadata,
            ]);

            $order->billing_subscription()->associate($subscription);
            $order->save();

            $order_items = collect([$order_item]);

            $syncData = collect([$plan->billing_product])
                ->mapWithKeys(fn (BillingProduct $item) => [
                    $item->getId() => ['quantity' => 1]
                ])->toArray();

            $order->billing_products()->sync($syncData, false);

            $payment_transaction->update([
                'payment_provider_checkout_id' => $result->providerCheckoutId,
                'payment_provider_subscription_id' => $result->providerSubscriptionId,
                'payment_provider_transaction_id' => $result->providerTransactionId,
                'metadata' => array_merge(
                    $subscription->metadata ?? [],
                    $subscription->wasRecentlyCreated ? [
                        'initiate_subscription' =>  $result->metadata ?
                            [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                            : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')]
                        
                    ] : [
                        'modify_subscription' =>  $result->metadata ?
                            [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                            : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')]
                        ,
                        'completing_subscription' => [
                            ...$metadata, 'date' => now()->format('Y-m-d H:i:s')
                        ]
                    ],  
                    ['complete_subscription' => $result->metadata 
                        ? [...$result->metadata,  'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                        : [ 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')]
                    ]
                )
            ]);

            $subscription_transaction->update([
                'payment_provider' => $paymentProvider,
                'payment_provider_checkout_id' => $result->providerCheckoutId,
                ...match(true) {
                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                        'status' => match (SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status) {
                            SubscriptionStatus::APPROVED,
                            SubscriptionStatus::PENDING,  
                            SubscriptionStatus::APPROVAL_PENDING => SubscriptionTransactionStatus::PENDING,
                            SubscriptionStatus::CANCELLED, 
                            SubscriptionStatus::CANCELED => SubscriptionTransactionStatus::CANCELED, 
                            SubscriptionStatus::FAILED => SubscriptionTransactionStatus::FAILED, 
                            SubscriptionStatus::PAST_DUE,
                            SubscriptionStatus::SUSPENDED, 
                            SubscriptionStatus::EXPIRED => SubscriptionTransactionStatus::EXPIRED,
                            SubscriptionStatus::ACTIVE => SubscriptionTransactionStatus::COMPLETED,
                            default =>  SubscriptionTransactionStatus::PENDING,
                        },
                        'metadata' => array_merge(
                            $subscription->metadata, 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ],
                            $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'metadata') ?? []
                        )
                    ],

                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                        'status' => match (SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status) {
                            SubscriptionStatus::APPROVED,
                            SubscriptionStatus::PENDING,  
                            SubscriptionStatus::APPROVAL_PENDING => SubscriptionTransactionStatus::PENDING,
                            SubscriptionStatus::CANCELLED, 
                            SubscriptionStatus::CANCELED => SubscriptionTransactionStatus::CANCELED, 
                            SubscriptionStatus::FAILED => SubscriptionTransactionStatus::FAILED, 
                            SubscriptionStatus::PAST_DUE,
                            SubscriptionStatus::SUSPENDED, 
                            SubscriptionStatus::EXPIRED => SubscriptionTransactionStatus::EXPIRED,
                            SubscriptionStatus::ACTIVE => SubscriptionTransactionStatus::COMPLETED,
                            default =>  SubscriptionTransactionStatus::PENDING,
                        },
                        'metadata' => array_merge(
                            $subscription->metadata, 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ],
                            $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'metadata') ?? []
                        )
                    ],

                    default => [
                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                        'status' => match ($result->status) {
                            SubscriptionStatus::APPROVED,
                            SubscriptionStatus::PENDING,  
                            SubscriptionStatus::APPROVAL_PENDING => SubscriptionTransactionStatus::PENDING,
                            SubscriptionStatus::CANCELLED, 
                            SubscriptionStatus::CANCELED => SubscriptionTransactionStatus::CANCELED, 
                            SubscriptionStatus::FAILED => SubscriptionTransactionStatus::FAILED, 
                            SubscriptionStatus::PAST_DUE,
                            SubscriptionStatus::SUSPENDED, 
                            SubscriptionStatus::EXPIRED => SubscriptionTransactionStatus::EXPIRED,
                            SubscriptionStatus::ACTIVE => SubscriptionTransactionStatus::COMPLETED,
                            default =>  SubscriptionTransactionStatus::PENDING,
                        },
                        'metadata' => array_merge(
                            $subscription->metadata, 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                : ['date' => now()->format('Y-m-d H:i:s')]
                            ]
                        )
                    ]
                },
                'payment_provider_plan_id' => $result->providerPlanId,
                'payment_status' => $result->paymentStatus,
                'payment_provider_status' => $result->metadata['status'] ?? 'COMPLETED',
                
            ]);

            switch ($result->paymentStatus) {
                case PaymentStatus::PAID:

                    switch ($subscription_transaction->type)
                    {
                        case SubscriptionTransactionType::Initial:
                            $subscription->fill([
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                    
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                )
                            ]);
                            break;
                        case SubscriptionTransactionType::Retry:
                        case SubscriptionTransactionType::Renewal:
                            
                            $subscription->fill([
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $planPrice->interval->calculateNextInterval($start, $planPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::Upgrade:    
                            $newPlan = $subscriptionContext->plan;
                            $newPlanPrice = $subscriptionContext->price;

                            $subscription->fill([
                                'billing_plan_id' => $newPlan->id,
                                'billing_plan_price_id' => $newPlanPrice->id,
                                'billing_plan_name' => $plan->name,
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $newPlanPrice->interval->calculateNextInterval($start, $newPlanPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::Downgrade:
                            break;
                        case SubscriptionTransactionType::PriceIncrease:
                            $newPlanPrice = $subscriptionContext->price;

                            $subscription->fill([
                                'billing_plan_price_id' => $newPlanPrice->id,
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $newPlanPrice->interval->calculateNextInterval($start, $newPlanPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::PriceDecrease:
                            break;
                        case SubscriptionTransactionType::Static:
                        default:
                            break;
                    }

                    $subscription->fill([
                        'is_active' => true
                    ]);
                    
                    $order->update([
                        'payment_provider_checkout_id' => $result->providerCheckoutId,
                        'payment_provider_order_id' => $result->providerOrderId,
                        'payment_provider_transaction_id' => $result->providerTransactionId,
                        'status' => $order_status = OrderStatus::PROCESSED,
                        'delivery_status' => DeliveryStatus::IN_PROGRESS,
                        'fulfillment_status' => FulfillmentStatus::IN_PROGRESS,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ]);

                    $order_items->map(fn (BillingOrderItem $order_item) => $order_item->fill([
                        'status' => $order_status,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order_item->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ])->save());

                    event(new PaymentCaptured($order, $payment_transaction));
                    event(new OrderProcessed($order));
                    event(new SubscriptionActivated($subscription));
                    break;

                case PaymentStatus::COMPLETED:

                    switch ($subscription_transaction->type)
                    {
                        case SubscriptionTransactionType::Initial:
                            $subscription->fill([
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                    
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                )
                            ]);
                            break;
                        case SubscriptionTransactionType::Retry:
                        case SubscriptionTransactionType::Renewal:
                            
                            $subscription->fill([
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $planPrice->interval->calculateNextInterval($start, $planPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::Upgrade:    
                            $newPlan = $subscriptionContext->plan;
                            $newPlanPrice = $subscriptionContext->price;

                            $subscription->fill([
                                'billing_plan_id' => $newPlan->id,
                                'billing_plan_price_id' => $newPlanPrice->id,
                                'billing_plan_name' => $plan->name,
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $newPlanPrice->interval->calculateNextInterval($start, $newPlanPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::Downgrade:
                            break;
                        case SubscriptionTransactionType::PriceIncrease:
                            $newPlanPrice = $subscriptionContext->price;

                            $subscription->fill([
                                'billing_plan_price_id' => $newPlanPrice->id,
                                'payment_provider' => $paymentProvider,
                                'payment_provider_checkout_id' => $result->providerCheckoutId,

                                ...match(true) {
                                    $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                                    ],

                                    $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                        'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                        'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                                    ],

                                    default => [
                                        'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                        'status' =>  $result->status,
                                    ]
                                },
                                'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                                'payment_provider_plan_id' => $result->providerPlanId,
                                'ended_at' => null,
                                'canceled_at' => null,
                                'paused_at' => null,
                                'expired_at' => null,
                                'resumed_at' => null,
                                'processed_at' => now(),
                                'metadata' => array_merge(
                                $subscription->metadata, 
                                        $subscription->wasRecentlyCreated ? [
                                            'modify_subscription' =>  $result->metadata ?
                                                [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                                : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                                        ] : [],
                                        ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]],  
                                        ['complete_subscription' => $result->metadata 
                                            ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s')] 
                                            : ['date' => now()->format('Y-m-d H:i:s')]
                                        ]
                                ),
                                'starts_at' => $start = now(),
                                'ends_at' => $end = $newPlanPrice->interval->calculateNextInterval($start, $newPlanPrice->billing_interval_count),
                                'next_billing_at' => $end,
                            ]);
                            break;
                        case SubscriptionTransactionType::PriceDecrease:
                            break;
                        case SubscriptionTransactionType::Static:
                        default:
                            break;
                    }

                    $subscription->fill([
                        'is_active' => true
                    ]);

                    $order->update([
                        'payment_provider_checkout_id' => $result->providerCheckoutId,
                        'payment_provider_order_id' => $result->providerOrderId,
                        'payment_provider_transaction_id' => $result->providerTransactionId,
                        'status' => $order_status = OrderStatus::COMPLETED,
                        'delivery_status' => DeliveryStatus::DELIVERED,
                        'fulfillment_status' => FulfillmentStatus::FULFILLED,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ]);

                    $order_items->map(fn (BillingOrderItem $order_item) => $order_item->fill([
                        'status' => $order_status,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order_item->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ])->save());

                    event(new PaymentCompleted($order, $payment_transaction));
                    event(new SubscriptionActivated($subscription));
                    break;
                case PaymentStatus::FAILED:
                    $subscription->fill([
                        'payment_provider' => $paymentProvider,
                        'payment_provider_checkout_id' => $result->providerCheckoutId,

                        ...match(true) {
                            $paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar' => [
                                'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'polar', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'polar', 'status')) ?? $result->status,
                            ],

                            $paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle' => [
                                'payment_provider_subscription_id' => $this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'payment_provider_subscription_id') ?? $subscription->payment_provider_subscription_id,
                                'status' => SubscriptionStatus::tryFrom($this->getCachedProviderSubscriptionCreationData($user, 'paddle', 'status')) ?? $result->status,
                            ],

                            default => [
                                'payment_provider_subscription_id' => $result->providerSubscriptionId,
                                'status' =>  $result->status,
                            ]
                        },
                        'payment_provider_status' => $result->metadata['status'] ?? 'DEFAULT',
                        'payment_provider_plan_id' => $result->providerPlanId,
                        'ended_at' => null,
                        'canceled_at' => null,
                        'paused_at' => null,
                        'expired_at' => null,
                        'is_active' => false
                    ]);

                    $order->update([
                        'status' => $order_status = OrderStatus::FAILED,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ]);

                    $order_items->map(fn (BillingOrderItem $order_item) => $order_item->fill([
                        'status' => $order_status,
                        'payment_status' => $result->paymentStatus,
                        'processed_at' => now(),
                        'metadata' => array_merge(
                            $order_item->metadata, 
                            $subscription->metadata,
                            $subscription->wasRecentlyCreated ? [
                                'modify_subscription' =>  $result->metadata ?
                                    [...$result->metadata, 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value, 'date' => now()->format('Y-m-d H:i:s')] 
                                    : ['status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,'date' => now()->format('Y-m-d H:i:s')]
                            ] : [], 
                            ['completing_subscription' => [...$metadata, 'date' => now()->format('Y-m-d H:i:s')]], 
                            ['complete_subscription' => $result->metadata 
                                ? [...$result->metadata, 'date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,] 
                                : ['date' => now()->format('Y-m-d H:i:s'), 'status' => $result->status->value, 'payment_status' => $result->paymentStatus->value,]
                            ]
                        )
                    ])->save());

                    event(new PaymentFailed($order, $payment_transaction));
                    
                case PaymentStatus::PENDING:
                case PaymentStatus::DEFAULT:
                default:
                   break;
            }

            if ($paymentProvider === PaymentProvider::Polar || $paymentProviderValue === 'polar') {
                Cache::forget(
                    class_basename($user).'_'.$user->getKey().'_polar_subscription_active'
                );
            }

            if ($paymentProvider === PaymentProvider::Paddle || $paymentProviderValue === 'paddle') {
                Cache::forget(
                    class_basename($user).'_'.$user->getKey().'_paddle_subscription_active'
                );
            }

            $subscription->save();

            $subscription->billing_subscription_events()->create( [
                'type' => EventEnum::TransactionCompleted,
                'triggered_by' => 'SYSTEM',
                'billing_subscription_transaction_id' => $subscription_transaction?->id,
            ]);

            return $result->setCheckoutDetails(
                new CheckoutDetails(
                    $payment_transaction,
                    $order,
                    collect([$order_item]),
                    checkoutUrl: $result->getCheckoutUrl()
                )->setSubscriptionContext(
                    new SubscriptionContext(
                        $subscriptionContext->type,
                        $subscription,
                        $subscription_transaction,
                        $subscriptionContext->plan ?? $plan,
                        $subscriptionContext->price ?? $planPrice,
                        $subscriptionContext->currentPlan,
                        $subscriptionContext->currentPlanPrice
                    )
                )
            );
        });
    }
}