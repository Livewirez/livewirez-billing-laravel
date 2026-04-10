<?php

namespace App\Services\Payments;

use Inertia\Inertia;
use Illuminate\Support\Str;
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
use Livewirez\Billing\Actions\SetupPaymentToken;
use Livewirez\Billing\Actions\StartSubscription;
use Livewirez\Billing\Actions\UpdateSubscription;
use Livewirez\Billing\Actions\InitializeSubscription;
use Livewirez\Billing\Actions\CompletePaymentWithToken;
use Livewirez\Billing\Actions\StartSubscriptionWithToken;
use Livewirez\Billing\Http\Resources\BillingOrderResource;
use Livewirez\Billing\Actions\SetupSubscriptionPaymentToken;

class PayPalHandler extends PaymentProviderHandler
{
    #[\Override]
    public static function setupPaymentToken(Request $request, SetupPaymentToken $action): Response
    {
        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required', function(string $attribute, mixed $value, \Closure $fail) {
                if (PaymentProvider::tryFrom($value) !== PaymentProvider::PayPal) $fail('Unsupported Method');
            }],
            'products' => ['required', 'array'],
            'products.*' => ['required', 'array', 'required_array_keys:product,quantity'],
            'products.*.product' => ['required', 'numeric', 'exists:billing_products,id'],
            'products.*.quantity' =>  ['required', 'numeric']
        ]);

        $cartItem = array_map(
            fn (array $product) => new CartItem(
                BillingProduct::find($product['product']), 
                $product['quantity']
            ),
            $validated['products']
        );
        $cart = new Cart($cartItem);

        $result = $action->handle($request->user(),  PaymentProvider::PayPal, []);

        $request->session()->put('payment_provider', PaymentProvider::PayPal->value);
        $request->session()->put('paypal_payment_type', 'vault_setup');
        $request->session()->put('provider_class', $result['provider_class']);
        $request->session()->put('cart', $cart);
        $request->session()->put('save_token', $request->input('save_token'));

        if ($request->expectsJson()) return response()->json([
            'status' => 'pending', 'redirect' => $result['checkout_url'],
            'metadata' => array_filter($result, fn (string $key) => $key !== 'provider_class' , ARRAY_FILTER_USE_KEY)
        ]);

        return Redirect::away($result['checkout_url']);
    }

    public static function setupSubscriptionPaymentToken(Request $request, SetupSubscriptionPaymentToken $action): Response
    {
        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required', function(string $attribute, mixed $value, \Closure $fail) {
                if (PaymentProvider::tryFrom($value) !== PaymentProvider::PayPal) $fail('Unsupported Method');
            }],
        ]);

        if ( ! ($request->session()->has('selected_plan') && $request->session()->has('selected_plan_price'))) {

            if ($request->expectsJson()) return response()->json(['status' => 'failed', 'message' => 'Please a select a plan'], 400);

            return Redirect::back()->with(['status' => 'failed', 'message' => 'Please a select a plan']);
        }


        $result = $action->handle(
            $request->user(),
            PaymentProvider::PayPal,
            $request->session()->get('selected_plan_price'),
            []
        );

        $request->session()->put('payment_provider', PaymentProvider::PayPal->value);
        $request->session()->put('paypal_payment_type', 'vault_setup');
        $request->session()->put('provider_class', $result['provider_class']);
        $request->session()->put('save_token', $request->input('save_token'));

        if ($request->expectsJson()) return response()->json([
            'status' => 'pending', 'redirect' => $result['checkout_url'],
            'metadata' => array_filter($result, fn (string $key) => $key !== 'provider_class' , ARRAY_FILTER_USE_KEY)
        ]);

        return Redirect::away($result['checkout_url']);

    }

    #[\Override]
    public static function completePaymentWithToken(Request $request, CompletePaymentWithToken $action): Response | Responsable
    {
        $provider = $request->query('provider');
        $cart     = $request->session()->get('cart');
        $token    = $request->input('token');
        // $payerId = $request->input('PayerID');

        if (! $provider && ! $cart && ! $token) {
           \Illuminate\Support\Facades\Log::warning('No Provider in cancel', [__METHOD__ . __LINE__]);
            return Redirect::route('dashboard');
        }

        if (PaymentProvider::tryFrom($provider) === PaymentProvider::PayPal) {

            $savedToken = null;

            if ($isUuid = Str::isUuid($token)) {
                $savedToken = $request->user()->billable_payment_methods()->where([
                    'billable_payment_method_id' => $token
                ])->first();
            }

            $result = $savedToken && $isUuid ? $action->handle(
                $request->user(),
                $provider = PaymentProvider::PayPal,
                $cart,
                $savedToken,
                [
                    'provider_class' => $request->session()->get('provider_class'),
                ],
                [
    
                ]
            ) : $action->handle(
                $request->user(),
                $provider = PaymentProvider::PayPal,
                $cart,
                $token,
                [
                    'token' => $token,
                    'payer_id' => $payerId = $request->input('PayerID'),
                    'facilitatorAccessToken' => $request->input('facilitatorAccessToken'),
                    'provider_class' => $request->session()->get('provider_class'),
                    'paypal_payment_type' => $request->session()->get('paypal_payment_type'),
                    'vault_setup_token' => $request->input('vaultSetupToken'),
                    'save_token' => $request->session()->get('save_token') ?: $request->input('saveData'),
                ],
                [
                    'save_token' => $request->session()->get('save_token') ?: $request->input('saveData')
                ]
            );

            \Illuminate\Support\Facades\Log::debug(__METHOD__, [
                'result' => $result
            ]);

            switch ($result->status) {
                case PaymentStatus::COMPLETED:
                case PaymentStatus::PAID: 
 

                    if ($request->session()->has('cart')) {
                        $request->session()->forget('cart');
                    }

                    $request->session()->forget([
                        'payment_provider',
                        'paypal_payment_type',
                        'provider_class',
                        'save_token', 'saveData'
                    ]);

                    return Inertia::render('checkout/PaymentSuccess', [
                        'clear_cart' => true,
                        'message' => 'Thank you for your purchase!',
                        'order' => BillingOrderResource::make(
                            $result->getBillingOrder()->loadMissing([
                                'billing_order_items' => ['billing_product']
                            ])
                        )->resolve()
                    ]);
                case PaymentStatus::PENDING:
                    if ($result->getCheckoutUrl()) {
                        return Redirect::away($result->getCheckoutUrl());
                    }

                    if ($request->session()->has('cart')) {
                        return Inertia::render('store/CheckoutCart', [
                            'cart' => $request->session()->get('cart')
                        ]);
                    }

                    return Redirect::route('dashboard');
                case PaymentStatus::FAILED:
                    return Inertia::render('checkout/PaymentFailure', [
                        'message' => 'Your payment could not be completed with Paypal!'
                    ]);
                default:
                    return Redirect::route('dashboard');
            }
        }

        return Redirect::route('dashboard');
    }

    #[\Override]
    public static function startSubscriptionWithToken(Request $request, StartSubscriptionWithToken $action): Response | Responsable 
    {
        $provider  = $request->query('provider');
        $token     = $request->input('token');
        $payerId   = $request->input('PayerID');
        $plan      = $request->session()->get('selected_plan');
        $planPrice = $request->session()->get('selected_plan_price');

        if (! $provider && ! $token && ! $payerId && ! $plan && ! $planPrice) {
            \Illuminate\Support\Facades\Log::warning('No Provider in cancel', [__METHOD__ . __LINE__]);
            return Redirect::route('dashboard');
        }

        if (PaymentProvider::tryFrom($provider) === PaymentProvider::PayPal) {

            $savedToken = null;

            if ($isUuid = Str::isUuid($token)) {
                $savedToken = $request->user()->billable_payment_methods()->where([
                    'billable_payment_method_id' => $token
                ])->first();
            }

            $result = $savedToken && $isUuid ? $action->handle(
                $request->user(),
                $provider = PaymentProvider::PayPal,
                $planPrice,
                $savedToken,
                [
                    'provider_class' => $request->session()->get('provider_class'),
                ],
            ) : $action->handle(
                $request->user(),
                $provider = PaymentProvider::PayPal,
                $planPrice,
                $token,
                [
                    'token' => $token,
                    'payer_id' => $payerId = $request->input('PayerID'),
                    'facilitator_access_token' => $request->input('facilitatorAccessToken'),
                    'provider_class' => $request->session()->get('provider_class'),
                    'paypal_payment_type' => $request->session()->get('paypal_payment_type'),
                    'vault_setup_token' => $request->input('vaultSetupToken'),
                    'save_token' => $request->session()->get('save_token') ?: $request->input('saveData'),
                ]
            );

            \Illuminate\Support\Facades\Log::debug(__METHOD__, [
                'result' => $result
            ]);

             switch ($result->status) {
                case SubscriptionStatus::ACTIVE:

                    $request->session()->forget([
                        'payment_provider',
                        'paypal_payment_type',
                        'provider_class',
                        'save_token', 'saveData'
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

                    return Redirect::back();
                case SubscriptionStatus::FAILED:
                    if ($request->expectsJson()) return response()->json(['status' => 'failed', 'message' => 'An Error Occurred.'], 400);

                    return Redirect::back()->with(['status' => 'failed', 'message' => 'An Error Occurred.']);
                default:
                    return Redirect::route('dashboard');
                    
            }
        }

        return Redirect::route('dashboard');
    }

    #[\Override]
    public static function initializePayment(Request $request, InitializePayment $action): Response
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required', function(string $attribute, mixed $value, \Closure $fail) {
                if (PaymentProvider::tryFrom($value) !== PaymentProvider::PayPal) $fail('Unsupported Method');
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
                        collect($result->result->getError()),
                        [
                            __METHOD__ .': Payment Initiated',
                            $result->status->value
                        ]
                    );

                    switch ($result->status) {
                        case PaymentStatus::PENDING:
                            $request->session()->put('payment_provider', PaymentProvider::PayPal->value);
                            $request->session()->put('checkout_details', $result->getCheckoutDetails());

                            if ($request->expectsJson()) return response()->json([
                                'status' => 'pending', 'redirect' => $result->getCheckoutUrl(),
                                'metadata' => $result->metadata
                            ]);
                                
                            return Redirect::away($result->getCheckoutUrl());
                        case PaymentStatus::COMPLETED:
                            if ($request->expectsJson()) return response()->json(['status' => 'completed', 'redirect' => route('dashboard')]);
                                
                            return Redirect::route('dashboard')->with(['status' => 'completed']);
                        case PaymentStatus::PAYMENT_PROVIDER_UNAVAILABLE:
                            if ($request->expectsJson()) return response()->json(['status' => 'failed', 'message' => 'PayPal is unavailable please try another method.'], 400);

                            return Redirect::back()->with(['status' => 'failed', 'message' => 'PayPal is unavailable please try another method.']);
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
        $provider         = $request->query('provider');
        $token            = $request->input('token');
        $payerId          = $request->input('PayerID');
        $checkout_details = $request->session()->get('checkout_details');

        if (! $provider && ! $token && ! $payerId && ! $checkout_details) {
            \Illuminate\Support\Facades\Log::warning('No Provider in cancel', [__METHOD__ . __LINE__]);
            return Redirect::route('dashboard');
        }


        if (PaymentProvider::tryFrom($provider) === PaymentProvider::PayPal) {

            $result = $action->handle(
                $request->user(),
                $povider = PaymentProvider::PayPal,
                $checkout_details,
                $token,
                [
                    'billing_order_id' => $checkout_details->getBillingOrder()->billing_order_id,
                    'payment_provider_order_id' => $token,
                ]
            );

            switch ($result->status) {
                case PaymentStatus::COMPLETED: 
                case PaymentStatus::PAID: 

                    if ($request->session()->has('cart')) {
                        $request->session()->forget('cart');
                    }

                    $request->user()->billing_cart()->delete();

                    $request->session()->forget([
                        'payment_provider',
                        'checkout_details',
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
                    if ($result->getCheckoutUrl()) {
                        return Redirect::away($result->getCheckoutUrl());
                    }

                    if ($request->session()->has('cart')) {
                        return Inertia::render('store/CheckoutCart', [
                            'cart' => $request->session()->get('cart')
                        ]);
                    }

                    return Redirect::route('dashboard');
                case PaymentStatus::FAILED:
                    return Inertia::render('checkout/PaymentFailure', [
                        'message' => 'Your payment could not be completed with Paypal!'
                    ]);
                default:
                    return Redirect::route('dashboard');
            }
        }

        return Redirect::route('dashboard');
    }

    #[\Override]
    public static function cancelPayment(Request $request, CancelPayment $action): Response
    {
        if (! $provider = $request->query('provider')) {
            \Illuminate\Support\Facades\Log::warning('No Provider in cancel', [__METHOD__ . __LINE__]);
            return Redirect::route('dashboard');
        }

        if (PaymentProvider::tryFrom($provider) === PaymentProvider::PayPal) {
            if (! ($token = $request->query('token'))) {
                \Illuminate\Support\Facades\Log::warning('No Token from paypal redirect', [__METHOD__ . __LINE__ . 'Paypal']);
                return redirect()->route('dashboard');
            }
            
            $action->handle($request->user(), PaymentProvider::PayPal, $token, []);

            return Redirect::route('store.cart.checkout');
        }

        \Illuminate\Support\Facades\Log::warning('No Provider in Enum tryFrom', [__METHOD__ . __LINE__]);
        return Redirect::route('dashboard');
    }

    #[\Override]
    public static function initializeSubscription(Request $request, InitializeSubscription $action): Response
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required', function(string $attribute, mixed $value, \Closure $fail) {
                if (PaymentProvider::tryFrom($value) !== PaymentProvider::PayPal) $fail('Unsupported Method');
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
                            $request->session()->put('payment_provider', PaymentProvider::PayPal->value);
                            $request->session()->put('billing_subscription', $result->getCheckoutDetails()->getBillingSubscription());
                            $request->session()->put('checkout_details', $result->getCheckoutDetails());

                            if ($request->expectsJson()) return response()->json([
                                'status' => 'pending', 'redirect' => $result->getCheckoutUrl(),
                                'metadata' => $result->metadata
                            ]);
                                
                            return Redirect::away($result->getCheckoutUrl());
                        case SubscriptionStatus::ACTIVE:
                            if ($request->expectsJson()) return response()->json(['status' => 'completed', 'redirect' => route('dashboard')]);
                                
                            return Redirect::route('dashboard')->with(['status' => 'completed']);
                        case SubscriptionStatus::FAILED:
                        case SubscriptionStatus::PENDING: 
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

    #[\Override]
    public static function startSubscription(Request $request, StartSubscription $action): Response | Responsable
    {
        $data = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required', function(string $attribute, mixed $value, \Closure $fail) {
                if (PaymentProvider::tryFrom($value) !== PaymentProvider::PayPal) $fail('Unsupported Method');
            }],
            'subscription_id' => ['required'],
        ]);

        $userId = $request->user()->id;

        $plan = $request->session()->get('selected_plan');
        if (($planPrice = $request->session()->get('selected_plan_price')) === null) {

            \Illuminate\Support\Facades\Log::error('Plan Price not in session');

            if ($request->expectsJson()) return response()->json(['message' => 'An Error Occurred.'], 400);

            return Redirect::route('dashboard');
        }

        // Create a unique lock per user or transaction
        $lock = Cache::lock("subscription-lock:user:{$userId}", 10); // 10 seconds timeout

        if ($lock->get()) {
            try {
        
                $result = $action->handle(
                    $request->user(),
                    PaymentProvider::PayPal,
                    $data['subscription_id'],
                    $planPrice,
                    $request->session()->get('checkout_details'),
                    [
                        'token' => $request->query('token'),
                        'ba_token' => $request->query('ba_token'),
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
                                'selected_plan', 
                                'selected_plan_price', 
                                'payment_provider',
                                'billing_subscription',
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

                            return Redirect::back();
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
        }
    }


    #[\Override]
    public static function updateSubscription(Request $request, UpdateSubscription $action): Response
    {
        throw new \Exception('Unsupported');
    }
}