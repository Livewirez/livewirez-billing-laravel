<?php

use Inertia\Inertia;
use Illuminate\Http\Request;
use Livewirez\Billing\Lib\Cart;
use Livewirez\Billing\Lib\CartItem;
use Illuminate\Support\Facades\Route;
use Livewirez\Billing\Enums\ProductType;
use Livewirez\Billing\Models\BillingPlan;
use App\Http\Controllers\OrdersController;
use Livewirez\Billing\Models\BillingProduct;
use Livewirez\Billing\Lib\BillingCartWrapper;
use Livewirez\Billing\Enums\SubscriptionStatus;
use App\Http\Controllers\SubscriptionsController;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewirez\Billing\Http\Resources\BillingProductResource;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/store', fn () => Inertia::render('store/Store', [
        'products' => BillingProductResource::collection(
            BillingProduct::whereIn(
                'product_type', 
                [ProductType::PHYSICAL, ProductType::DIGITAL]
                )->get()
        )->resolve()
    ]))->name('store');

    Route::get('/store/products/{product}', fn (Request $request, BillingProduct $product) => Inertia::render('store/Product', [
        'product' => BillingProductResource::make($product)->resolve()
    ]))->name('store.product');
    
    
    Route::get('plans', function () {

        $plans = BillingPlan::with(['billing_prices' => fn ($q) => $q->where('currency', 'KES'), 'billing_plan_payment_provider_information', 'billing_prices.billing_plan_payment_provider_information'])
        ->where(['is_active' => true])->get();
    
        return Inertia::render('Plans', [
            'plans' => \Livewirez\Billing\Http\Resources\BillingPlanResource::collection($plans)->resolve()
        ]); 


        // return Inertia::render('Plans', [
        //     // 'plans' => Illuminate\Http\Resources\Json\JsonResource::collection(
        //     //     collect(
        //     //         json_decode(
        //     //             Illuminate\Support\Facades\Redis::get('plans'),
        //     //             true
        //     //         )
        //     //     )
        //     // )->resolve()]

        //     'plans' => Illuminate\Http\Resources\Json\JsonResource::collection(unserialize(Illuminate\Support\Facades\Redis::get('plans_ser')))->resolve()
        // ]);

    })->name('plans');

    Route::get('plansv2', function () {
    
        $plans = BillingPlan::with(['billing_prices' => fn ($q) => $q->where('currency', 'KES'), 'billing_plan_payment_provider_information', 'billing_prices.billing_plan_payment_provider_information'])
        ->where(['is_active' => true])->get();
    
        return Inertia::render('PlansV2', [
            'plans' => \Livewirez\Billing\Http\Resources\BillingPlanResource::collection($plans)->resolve()
        ]); 
    })->name('plansv2');
    
    
    Route::get('checkout/plan/{name}', function (Request $request, string $name) {
    
        $plan_id = $request->query('plan_id');
        $interval = $request->query('interval');
    
        $plan = BillingPlan::with(['billing_prices' => fn ($q) => $q->where('currency', 'USD'), 'billing_plan_payment_provider_information', 'billing_prices.billing_plan_payment_provider_information'])
        ->where(['is_active' => true, 'name' => $name, 'billing_plan_id' => $plan_id])->firstOrFail();
    
        $plan_price = $plan->billing_prices->firstOrFail(
            fn ($pp) => $pp->interval === ($interval === 'yearly' ? \Livewirez\Billing\Enums\SubscriptionInterval::YEARLY : \Livewirez\Billing\Enums\SubscriptionInterval::MONTHLY)
        );

        $plan_price->billing_plan_payment_provider_information = $plan_price->billing_plan_payment_provider_information->merge(
            $plan->billing_plan_payment_provider_information->filter(fn ($bpp) => $bpp->payment_provider === Livewirez\Billing\Enums\PaymentProvider::PayPal)
        );

      
        $request->session()->put('selected_plan', $plan);
        $request->session()->put('selected_plan_price', $plan_price);

        return Inertia::render('checkout/Plan', [
           'plan' => \Livewirez\Billing\Http\Resources\BillingPlanResource::make($plan)->resolve(),
           'plan_price' => \Livewirez\Billing\Http\Resources\BillingPlanPriceResource::make($plan_price)->resolve(),
        ]); 
    })->name('checkout.plan');
    
    Route::get('checkout/planv2/{name}', function (Request $request, string $name) {
    
        $plan_id = $request->query('plan_id');
        $interval = $request->query('interval');
    
        $plan = BillingPlan::with(['billing_prices' => fn ($q) => $q->where('currency', 'USD'), 'billing_plan_payment_provider_information', 'billing_prices.billing_plan_payment_provider_information'])
        ->where(['is_active' => true, 'name' => $name, 'billing_plan_id' => $plan_id])->firstOrFail();
    
        $plan_price = $plan->billing_prices->firstOrFail(
            fn ($pp) => $pp->interval === ($interval === 'yearly' ? \Livewirez\Billing\Enums\SubscriptionInterval::YEARLY : \Livewirez\Billing\Enums\SubscriptionInterval::MONTHLY)
        );

        $plan_price->billing_plan_payment_provider_information = $plan_price->billing_plan_payment_provider_information->merge(
            $plan->billing_plan_payment_provider_information->filter(fn ($bpp) => $bpp->payment_provider === Livewirez\Billing\Enums\PaymentProvider::PayPal)
        );

        $request->session()->put('selected_plan', $plan);
        $request->session()->put('selected_plan_price', $plan_price);
    
        return Inertia::render('checkout/PlanV3', [
           'plan' => \Livewirez\Billing\Http\Resources\BillingPlanResource::make($plan)->resolve(),
           'plan_price' => \Livewirez\Billing\Http\Resources\BillingPlanPriceResource::make($plan_price)->resolve(),
        ]); 
    })->name('checkout.planv2');


    Route::get('/store/product/{product}/buynow', function (Request $request, BillingProduct $product) {

        $sci = array_map(function (array $data) {
            return CartItem::fromArray($data);
        }, [['product' => $product, 'quantity' => 1]]);

        $request->session()->put('cart', $cart = new Cart($sci));

        $request->session()->put(
            'billing_cart',
            BillingCartWrapper::fromCart(
                $request->user(), 
                $cart
            )
        );
         
        return redirect(route('store.cart.checkout'));

    })->name('store.product.buy_now');

    Route::get('/store/cart/checkout', function (Request $request) {

        if (!  $cart = $request->session()->get('cart')) {
            return redirect(route('store'));
        }
        

        $extra_tax = 0.33;

        $cart = $request->session()->get('cart');

        return Inertia::render('store/CheckoutCart_', [
            'payment_methods' => \Livewirez\Billing\Http\Resources\BillablePaymentMethodResource::collection(
                $request->user()->billable_payment_methods()->get()
            )->resolve(),
            'cart_items' => array_map(fn (CartItem $ci) => ['product' => $ci->getProduct()->getId(), 'quantity' => $ci->getQuantity()], $cart->items),
            'cart_info' => [
                'items' => array_map(function (CartItem $sci) use ($extra_tax) {
                    return [
                        'id' => $sci->getProduct()->getId(),
                        'name' => $sci->getProduct()->getName(),
                        'description' => $sci->getProduct()->getDescription(),
                        'quantity' => $sci->getQuantity(),
                        'thumbnail' => $sci->getProduct()->getImageUrl(),
                        'unit_amount' => [
                            'currency_code' => $sci->getProduct()->getCurrencyCode(),
                            'value' => $sci->getProduct()->getListedPrice(),
                            'formatted_value' => Livewirez\Billing\Money::formatAmountUsingCurrency(
                                $sci->getProduct()->getListedPrice(),
                                $sci->getProduct()->getCurrencyCode(),
                            ),
                            'total_formatted_value' => Livewirez\Billing\Money::formatAmountUsingCurrency(
                                $sci->getProduct()->getListedPrice() * $sci->getQuantity(),
                                $sci->getProduct()->getCurrencyCode(),
                            )
                        ],
                        'tax' => [
                            'currency_code' => $sci->getProduct()->getCurrencyCode(),
                            'value' => ($sci->getProduct()->getTax() + $extra_tax)
                        ]
                    ];
                }, $cart->all()),

                'amount' => [
                    'currency_code' => $cart->getCurrencyCode(),
                    'value' => ($cart->getGrandTotalFromExtraTax(true, $extra_tax)),
                    'breakdown' => [
                        'item_total' => [
                            'currency_code' => $cart->getCurrencyCode(),
                            'value' => $cart->getItemTotals(false)
                        ],
                        'tax_total' => [
                            'currency_code' => $cart->getCurrencyCode(),
                            'value' => $cart->getItemExtraTaxTotals($extra_tax)
                        ],
                        'shipping' => [
                            'currency_code' => $cart->getCurrencyCode(),
                            'value' => $cart->getShippingTotal()
                        ],
                        'handling' => [
                            'currency_code' => $cart->getCurrencyCode(),
                            'value' => $cart->getHandlingTotal()
                        ],
                        'insurance' => [
                            'currency_code' => $cart->getCurrencyCode(),
                            'value' => $cart->getInsuranceTotal()
                        ],
                        'shipping_discount' => [
                            'currency_code' => $cart->getCurrencyCode(),
                            'value' => $cart->getShippingDiscountTotal()
                        ],
                        'discount' => [
                            'currency_code' => $cart->getCurrencyCode(),
                            'value' => $cart->getDiscountTotal()
                        ],
                    ]
                ]
            ]
        ]);

    })->name('store.cart.checkout');

    Route::get('/payment/complete_orderxx', function (Request $request) {

        // if ($request->session()->has('product') && $request->session()->has('shopping_cart')) {
        //     $request->session()->forget('product');
        // }

        // if ($request->session()->has('product') && ! $request->session()->has('shopping_cart')) {
        //     $request->session()->forget('product');
        // }

        // if ($request->session()->has('shopping_cart') && ! $request->session()->has('product')) {
        //     $request->session()->forget('shopping_cart');
        // }

        return Inertia::render('checkout/PaymentSuccess', [
            'message' => 'Thank you for your purchase!'
        ]);
    })->name('payment.complete_orderxx');

    Route::get('/payment/complete_order', [OrdersController::class, 'completePayment'])->name('payment.complete_order');
    Route::get('/payment/complete_order_with_token', [OrdersController::class, 'completePaymentWithToken'])->name('payment.complete_order_with_token');
    Route::get('payment/cancel_order', [OrdersController::class, 'cancelPayment'])->name('payment.cancel_order');

    Route::get('/subscriptions/startsubscription', [SubscriptionsController::class, 'startSubscription'])->name('subscriptions.start_subscription');
    Route::get('/subscriptions/start_subscription_with_token', [SubscriptionsController::class, 'startSubscriptionWithToken'])->name('subscriptions.start_subscription_with_token');
    Route::get('/subscriptions', function (Request $request) {

        $subscription = $request->user()->billing_subscription()
        ->where('is_active', true)
        ->whereIn('status', [SubscriptionStatus::ACTIVE, SubscriptionStatus::CANCELLATION_PENDING])
        ->sole();

        return Inertia::render('Subscriptions', [
            'subscription' => $subscription->only(['id', 'interval', 'payment_provider', 'billing_plan_name',  'status', 'ends_at'])
        ]);
    })->name('subscriptions');
    
    Route::get('window', function () {
        return Inertia::render('Window');
    })->name('window');

    Route::get('download', function () {
        return Inertia::render('Download');
    })->name('download');

});


require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
