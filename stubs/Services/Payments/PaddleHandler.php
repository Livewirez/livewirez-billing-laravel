<?php

namespace App\Services\Payments;

use Inertia\Inertia;
use Illuminate\Http\Request;
use Livewirez\Billing\Lib\Cart;
use Livewirez\Billing\Lib\CartItem;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Support\Facades\Redirect;
use Inertia\Response as InertiaResponse;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Actions\CancelPayment;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingProduct;
use Illuminate\Contracts\Support\Responsable;
use Livewirez\Billing\Actions\CompletePayment;
use Livewirez\Billing\Actions\InitializePayment;
use Livewirez\Billing\Models\BillingPlanPrice;
use Symfony\Component\HttpFoundation\Response;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Actions\StartSubscription;
use Livewirez\Billing\Actions\UpdateSubscription;
use Livewirez\Billing\Actions\InitializeSubscription;
use Livewirez\Billing\Http\Resources\BillingOrderResource;

class PaddleHandler extends PaymentProviderHandler
{
    #[\Override]
    public static function initializePayment(Request $request, InitializePayment $action): Response
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required', function(string $attribute, mixed $value, \Closure $fail) {
                if (PaymentProvider::tryFrom($value) !== PaymentProvider::Paddle) $fail('Unsupported Method');
            }],
            'products' => ['required', 'array'],
            'products.*' => ['required', 'array', 'required_array_keys:product,quantity'],
            'products.*.product' => ['required', 'numeric', 'exists:billing_products,id'],
            'products.*.quantity' =>  ['required', 'numeric']
        ]);

        // Create a unique lock per user or transaction
        $lock = Cache::lock("payment-lock:user:{$userId}", 10); // 10 seconds timeout

        if ($lock->get()) {
            try {

                $cartItem = array_map(
                    fn (array $product) => new CartItem(
                        BillingProduct::find($product['product']), 
                        $product['quantity']
                    ),
                    $validated['products']
                );

                $result = $action->handle(
                    $request->user(),
                    $povider = PaymentProvider::from($validated['provider']),
                    $cart = new Cart($cartItem)
                );

                if ($result->success) {
                    \Illuminate\Support\Facades\Log::info(
                        __METHOD__ .': Payment Initiated',
                        [
                            'result' => $result,
                            'result_metadata' => $result->metadata
                        ]
                    );

                    switch ($result->status) {
                        case PaymentStatus::PENDING:
                            $request->session()->put('payment_provider', PaymentProvider::Paddle->value);
                            $request->session()->put('checkout_details', $result->getCheckoutDetails());
                            $request->session()->put('paddle_transaction_id', $result->providerTransactionId);

                            return response()->json([
                                'status' => 'pending', 'transaction_id' => $result->providerTransactionId,
                                'metadata' => $result->metadata
                            ]);
                        case PaymentStatus::COMPLETED:
                            if ($request->expectsJson()) return response()->json(['status' => 'completed', 'redirect' => route('dashboard')]);
                                
                            return Redirect::route('dashboard')->with(['status' => 'completed']);
                        case PaymentStatus::PAYMENT_PROVIDER_UNAVAILABLE:
                            if ($request->expectsJson()) return response()->json(['status' => 'failed', 'message' => 'Paddle is unavailable please try another method.'], 400);

                            return Redirect::back()->with(['status' => 'failed', 'message' => 'Paddle is unavailable please try another method.']);
                        case PaymentStatus::FAILED: 
                        default:
                            if ($request->expectsJson()) return response()->json(['status' => 'failed', 'message' => 'An Error Occurred.'], 400);

                            return Redirect::back()->with(['status' => 'failed', 'message' => 'An Error Occurred.']);
                    }
                }

                \Illuminate\Support\Facades\Log::error(
                    collect($result->result->getError()),
                    [
                        'Payment Error: ' . __METHOD__,
                        $result->status->value
                    ]
                );

                if ($request->expectsJson()) return response()->json(['status' => 'failed', 'message' => 'An Error Occurred.'], 400);

                return Redirect::back()->with(['status' => 'failed', 'message' => 'An Error Occurred.']);
            } finally {
                $lock->release();
            }
        } else {
            if ($request->expectsJson()) return response()->json(['status' => 'locked', 'message' => 'Payment already in progress.'], 429);

            return Redirect::back()->with(['status' => 'locked', 'message' => 'Payment already in progress.']);
            
        }
    }

    #[\Override]
    public static function completePayment(Request $request, CompletePayment $action): Response | Responsable
    {
        if (! ($provider = $request->query('provider')) 
            || ! ($checkout_details = $request->session()->get('checkout_details'))
        ) {
            \Illuminate\Support\Facades\Log::warning('No Provider in cancel', [__METHOD__ . __LINE__]);
            return Redirect::route('dashboard');
        }

        if (PaymentProvider::tryFrom($provider) === PaymentProvider::Paddle && $request->session()->has('paddle_transaction_id')) {

            $result = $action->handle(
                $request->user(),
                $provider = PaymentProvider::Paddle,
                $checkout_details,
                $token = $request->session()->get(
                    'paddle_transaction_id', 
                    $checkout_details->getBillingOrder()->payment_provider_transaction_id
                ),
                [
                    'billing_order_id' => $checkout_details->getBillingOrder()->billing_order_id,
                    'paddle_transaction_id' => $token,
                    'payment_provider_transaction_id' => $token,
                ]
            );

            switch ($result->status) {
                case PaymentStatus::COMPLETED:
                case PaymentStatus::PAID:  
                    $request->session()->forget([
                        'cart',
                        'payment_provider',
                        'checkout_details',
                        'paddle_transaction_id'
                    ]);

                    return Inertia::render('checkout/PaymentSuccess', [
                        'message' => 'Thank you for your purchase!',
                        'order' => BillingOrderResource::make(
                            $result->getBillingOrder()->loadMissing([
                                'billing_order_items' => ['billing_product']
                            ])
                        )->resolve()
                    ]);
                case PaymentStatus::PENDING:

                    if ($request->session()->has('cart')) {
                        return Inertia::render('store/CheckoutCart', [
                            'cart' => $request->session()->get('cart')
                        ]);
                    }

                    return Redirect::route('dashboard');
                case PaymentStatus::FAILED:
                    return Inertia::render('checkout/PaymentFailure', [
                        'message' => 'Your payment could not be completed with Paddle!'
                    ]);
                default:
                    return Redirect::route('dashboard');
            }
        }

        return Redirect::route('dashboard');
    }


    public static function cancelPayment(Request $request, CancelPayment $action): Response
    {
        throw new \Exception('Unsupported');
    }

    #[\Override]
    public static function initializeSubscription(Request $request, InitializeSubscription $action): Response
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required', function(string $attribute, mixed $value, \Closure $fail) {
                if (PaymentProvider::tryFrom($value) !== PaymentProvider::Paddle) $fail('Unsupported Method');
            }],
            'plan_price' => ['required', 'numeric', 'exists:billing_plan_prices,id']
        ]);

        // Create a unique lock per user or transaction
        $lock = Cache::lock("subscription-lock:user:{$userId}", 10); // 10 seconds timeout

        if ($lock->get()) {
            try {

                $result = $action->handle(
                    $request->user(),
                    $povider = PaymentProvider::from($validated['provider']),
                    BillingPlanPrice::find($validated['plan_price'])
                );

                if ($result->success) {
                    \Illuminate\Support\Facades\Log::info(
                        collect($result->result->getOk()),
                        [
                            'Subscription Value: ' . __METHOD__,
                            $result->status->value
                        ]
                    );

                    switch ($result->status) {
                        case SubscriptionStatus::APPROVAL_PENDING:

                            $request->session()->put('payment_provider', PaymentProvider::Paddle->value);
                            $request->session()->put('checkout_details', $result->getCheckoutDetails());
                            $request->session()->put('billing_subscription_id', $result->billingSubscriptionId);
                            $request->session()->put('paddle_transaction_id', $result->providerTransactionId);

                            return response()->json([
                                'status' => 'pending', 'transaction_id' => $result->providerTransactionId,
                                'metadata' => $result->metadata
                            ]);
                        case SubscriptionStatus::PAYMENT_PROVIDER_UNAVAILABLE:
                            if ($request->expectsJson()) return response()->json(['status' => 'failed', 'message' => 'Paddle is unavailable please try another method.'], 400);

                            return Redirect::back()->with(['status' => 'failed', 'message' => 'Paddle is unavailable please try another method.']);
                        case SubscriptionStatus::ACTIVE:
                            if ($request->expectsJson()) return response()->json([
                                'status' => 'successful', 'message' => 'Subscription is Active.',
                                'redirect' => route('subscriptions')
                            ]);

                            return Redirect::route('subscriptions');
                        default:
                            if ($request->expectsJson()) return response()->json(['status' => 'failed', 'message' => 'An Error Occurred.'], 400);

                            return Redirect::back()->with(['status' => 'failed', 'message' => 'An Error Occurred.']);
                    }
                }

                \Illuminate\Support\Facades\Log::error(
                    collect($result->result->getError()),
                    [
                        'Subscription Error: ' . __METHOD__,
                        $result->status->value
                    ]
                );

                if ($request->expectsJson()) return response()->json(['status' => 'failed', 'message' => 'An Error Occurred.'], 400);

                return Redirect::back()->with(['status' => 'failed', 'message' => 'An Error Occurred.']);
            } finally {
                $lock->release();
            }
        } else {
            if ($request->expectsJson()) return response()->json(['status' => 'locked', 'message' =>  'Subscription process already in progress.'], 429);

            return Redirect::back()->with(['status' => 'locked', 'message' =>  'Subscription process already in progress.']);
        }
    }


    public static function updateSubscription(Request $request, UpdateSubscription $action): Response
    {
        throw new \Exception('Unsupported');
    }


    public static function startSubscription(Request $request, StartSubscription $action): Response | Responsable
    {
        $data = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required', function(string $attribute, mixed $value, \Closure $fail) {
                if (PaymentProvider::tryFrom($value) !== PaymentProvider::Paddle) $fail('Unsupported Method');
            }],
        ]);

        $userId = $request->user()->id;

        $plan = $request->session()->get('selected_plan');
        if (($planPrice = $request->session()->get('selected_plan_price')) === null && (! $request->session()->has('payment_provider')
                && $request->session()->get('payment_provider') !== PaymentProvider::Paddle->value
                && ! $request->session()->has('paddle_transaction_id')
                && ! $request->session()->has('billing_subscription_id')
            )) {

            \Illuminate\Support\Facades\Log::error('Plan Price not in session');

            if ($request->expectsJson()) return response()->json(['message' => 'An Error Occurred.'], 400);

            return Redirect::route('dashboard');
        }

        // Create a unique lock per user or transaction
        $lock = Cache::lock("subscription-lock:user:{$userId}", 10); // 10 seconds timeout

        if ($lock->get()) {
            try {

                $checkout_details = $request->session()->get('checkout_details');
        
                $result = $action->handle(
                    $request->user(),
                    PaymentProvider::Paddle,
                    $paddleTransactionId = $request->session()->get(
                        'paddle_transaction_id', 
                        $checkout_details->getBillingOrder()->payment_provider_transaction_id
                    ),
                    $planPrice,
                    $checkout_details,
                    [
                        'paddle_transaction_id' => $paddleTransactionId,
                        'payment_provider_transaction_id' => $paddleTransactionId,
                    ]
                );

                if ($result->success) {
                    \Illuminate\Support\Facades\Log::info(
                        collect($result->result->getOk()),
                        [
                            'Subscription Value: ' . __METHOD__,
                            $result->status->value
                        ]
                    );

                    switch ($result->status) {
                        case SubscriptionStatus::ACTIVE:

                            $request->session()->forget([
                                'selected_plan', 'selected_plan_price',
                                'payment_provider',
                                'paddle_transaction_id',
                                'billing_subscription_id',
                                'checkout_details'
                            ]);

                            return Inertia::render('checkout/SubscriptionSuccess', [
                                'message' => 'Thank you for your purchase!',
                                'plan_price' => \Livewirez\Billing\Http\Resources\BillingPlanPriceResource::make($planPrice)->resolve(),
                                'plan' => \Livewirez\Billing\Http\Resources\BillingPlanResource::make($plan)->resolve(),
                            ]);
                        case SubscriptionStatus::APPROVAL_PENDING:
                        case SubscriptionStatus::APPROVED: 
                            if ($result->getCheckoutUrl()) {
                                if ($request->expectsJson()) return response()->json([
                                    'status' => 'pending', 
                                    'redirect' => $result->getCheckoutUrl(),
                                    'metadata' => $result->metadata
                                ]);
                                
                                return Redirect::away($result->getCheckoutUrl());
                            }

                            return response()->json([
                                'status' => 'pending', 'transaction_id' => $result->providerTransactionId ?? $paddleTransactionId,
                                'metadata' => $result->metadata
                            ]);
                        case SubscriptionStatus::FAILED:
                        default:
                            if ($request->expectsJson()) return response()->json(['status' => 'failed', 'message' => 'An Error Occurred.'], 400);

                            return Redirect::back()->with(['status' => 'failed', 'message' => 'An Error Occurred.']);
                    }
                }

                \Illuminate\Support\Facades\Log::error(
                    collect($result->result->getError()),
                    [
                        'Subscription Error: ' . __METHOD__,
                        $result->status->value
                    ]
                );

                if ($request->expectsJson()) return response()->json(['status' => 'failed', 'message' => 'An Error Occurred.'], 400);

                return Redirect::back()->with(['status' => 'failed', 'message' => 'An Error Occurred.']);
            } finally {
                $lock->release();
            }
        } else {
            if ($request->expectsJson()) return response()->json(['status' => 'locked', 'message' => 'Subscription process already in progress.'], 429);

            return Redirect::back()->with(['status' => 'locked', 'message' => 'Subscription process already in progress.']);
        };
    }

}