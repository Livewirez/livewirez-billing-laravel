<?php 

namespace App\Services\Payments;

use Exception;
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
use Livewirez\Billing\Models\BillingPlanPrice;
use Symfony\Component\HttpFoundation\Response;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Actions\InitializePayment;
use Livewirez\Billing\Actions\SetupPaymentToken;
use Livewirez\Billing\Actions\StartSubscription;
use Livewirez\Billing\Actions\UpdateSubscription;
use Livewirez\Billing\Actions\InitializeSubscription;
use Livewirez\Billing\Actions\CompletePaymentWithToken;
use Livewirez\Billing\Actions\StartSubscriptionWithToken;
use Livewirez\Billing\Http\Resources\BillingOrderResource;
use Livewirez\Billing\Actions\SetupSubscriptionPaymentToken;

class CardHandler extends PaymentProviderHandler
{
    public static function setupPaymentToken(Request $request, SetupPaymentToken $action): Response
    {
        return Redirect::route('dashboard');
    }

    public static function setupSubscriptionPaymentToken(Request $request, SetupSubscriptionPaymentToken $action): Response
    {
        return Redirect::route('dashboard');
    }

    public static function completePaymentWithToken(Request $request, CompletePaymentWithToken $action): Response | Responsable
    {
        return Redirect::route('dashboard');
    }

    public static function startSubscriptionWithToken(Request $request, StartSubscriptionWithToken $action): Response | Responsable 
    {
        return Redirect::route('dashboard');
    }

    #[\Override]
    public static function initializePayment(Request $request, InitializePayment $action): Response
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required', function(string $attribute, mixed $value, \Closure $fail) {
                if (PaymentProvider::tryFrom($value) !== PaymentProvider::Card) $fail('Unsupported Method');
            }],
            'products' => ['required', 'array'],
            'products.*' => ['required', 'array', 'required_array_keys:product,quantity'],
            'products.*.product' => ['required', 'numeric', 'exists:billing_products,id'],
            'products.*.quantity' =>  ['required', 'numeric']
        ]);

        $request->session()->put('card_gateway', $request->input('card_gateway', 'cybersource'));

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
                    $cart = new Cart($cartItem),
                    [
                        'card_gateway' => $request->input('card_gateway', 'cybersource'),
                        'token' => $request->input('token')
                    ]
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
                        case PaymentStatus::PAID:
                        case PaymentStatus::PENDING:
                        case PaymentStatus::APPROVED:

                            $request->session()->put('payment_provider', PaymentProvider::Card->value);
                            $request->session()->put('checkout_details', $result->getCheckoutDetails());
                            $request->session()->put('payment_provider_order_id', $result->getCheckoutDetails()->getBillingOrder()->payment_provider_order_id);

                            if ($request->expectsJson()) return response()->json([
                                'status' => 'pending', 'redirect' => $result->getCheckoutUrl(),
                                'metadata' => $result->metadata
                            ]);
                                
                            return Redirect::away($result->getCheckoutUrl()); 
                        case PaymentStatus::COMPLETED:
                            if ($request->expectsJson()) return response()->json(['status' => 'completed', 'redirect' => route('dashboard')]);
                                
                            return Redirect::route('dashboard')->with(['status' => 'completed']);
                        case PaymentStatus::PAYMENT_PROVIDER_UNAVAILABLE:
                            if ($request->expectsJson()) return response()->json(['status' => 'failed', 'message' => 'Unavailable please try another method.'], 400);

                            return Redirect::back()->with(['status' => 'failed', 'message' => 'Unavailable please try another method.']);
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

    public static function completePayment(Request $request, CompletePayment $action): Response | Responsable
    {
        if (! ($provider = $request->query('provider')) 
            || ! ($checkout_details = $request->session()->get('checkout_details'))
        ) {
            \Illuminate\Support\Facades\Log::warning('No Provider in cancel', [__METHOD__ . __LINE__]);
            return Redirect::route('dashboard');
        }

        if (PaymentProvider::tryFrom($provider) === PaymentProvider::Card) {

            $result = $action->handle(
                $request->user(),
                $provider = PaymentProvider::Card,
                $checkout_details,
                $token = $request->session()->get(
                    'payment_provider_order_id',
                    $checkout_details->getBillingOrder()->payment_provider_order_id
                ),
                [
                    'billing_order_id' => $checkout_details->getBillingOrder()->billing_order_id,
                    'card_gateway' => $request->input('card_gateway', 'cybersource'),
                    'token' => $request->input('token')
                ]
            );

            \Illuminate\Support\Facades\Log::info(__METHOD__ . ' Card CompletePayment', [
                'result' => $result,
                'metadata' => $result->metadata
            ]);

            switch ($result->status) {
                case PaymentStatus::COMPLETED:
                case PaymentStatus::PAID:  
                case PaymentStatus::APPROVED:
                    if ($request->session()->has('cart')) {
                        $request->session()->forget('cart');
                    }

                    $request->session()->forget([
                        'payment_provider',
                        'checkout_details',
                        'card_gateway',
                        'payment_provider_order_id'
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
                        'message' => 'Your payment could not be completed with Card!'
                    ]);
                default:
                    return Redirect::route('dashboard');
            }
        }

        return Redirect::route('dashboard');
    }

    public static function cancelPayment(Request $request, CancelPayment $action): Response
    {
        throw new Exception('Unsupported');
    }

    public static function initializeSubscription(Request $request, InitializeSubscription $action): Response
    {
        throw new Exception('Unsupported');
    }

    public static function updateSubscription(Request $request, UpdateSubscription $action): Response
    {
        throw new Exception('Unsupported');
    }

    public static function startSubscription(Request $request, StartSubscription $action): Response | Responsable
    {
        throw new Exception('Unsupported');
    }
}